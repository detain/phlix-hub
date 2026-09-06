<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Alexa;

use OpenSSLCertificateSigningRequest;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * A self-generated PKI that can produce a **signable** Alexa request.
 *
 * ## What this fixture is evidence of, and what it is NOT
 *
 * Everything here is generated in-process by this class: the root CA, the
 * intermediate, every leaf, and every signature. That means it CANNOT be
 * evidence that Amazon's real chain is accepted — a fixture whose signature this
 * same code also produced is a check derived from its subject, and it would keep
 * passing against a middleware that verified some other algorithm entirely, as
 * long as this class produced signatures the same wrong way.
 *
 * What it IS evidence of is the **algorithm**: that an RSA/SHA-256 detached
 * signature over the raw body verifies against the public key of a leaf whose
 * chain anchors to a configured trust root and whose SAN carries Amazon's
 * documented Echo name — and, more importantly, that each of those conditions is
 * individually necessary, because this class can also produce a chain with every
 * one of them broken in isolation while the rest stay correct.
 *
 * The complementary fixture is the **real, recorded** Amazon chain in
 * `tests/fixtures/alexa/` (see
 * {@see \Phlix\Hub\Tests\Unit\Http\Middleware\AlexaSignatureMiddlewareTest} for
 * its provenance). Nobody in this repo made that one, and it is what proves the
 * PEM splitting, the SAN parsing and the expiry check work against the bytes
 * Amazon actually publishes. Neither fixture alone is enough:
 *
 * | question                                     | answered by                |
 * | -------------------------------------------- | -------------------------- |
 * | does the algorithm accept a good request?    | this class (generated)     |
 * | is each guard individually necessary?        | this class (generated)     |
 * | does it parse Amazon's REAL chain shape?     | recorded fixture           |
 * | is a genuinely expired leaf rejected?        | recorded fixture           |
 *
 * There is deliberately no fixture of a *valid* real Amazon request: producing
 * one requires Amazon's private key, which is the entire point of the scheme.
 *
 * ## Cost
 *
 * Six RSA-2048 keypairs. Generated once per process and memoised in
 * {@see shared()}, because regenerating them per test method measured in
 * seconds, not milliseconds.
 */
final class AlexaCertificateFixture
{
    /** Memoised instance — key generation is the expensive part. */
    private static ?self $shared = null;

    private string $configPath;

    private string $trustedCaFile;

    /** @var array<string, string> Leaf PEM chains, keyed by variant name. */
    private array $chains = [];

    /** @var array<string, OpenSSLAsymmetricKey> Leaf private keys, by variant. */
    private array $leafKeys = [];

    private OpenSSLAsymmetricKey $strangerKey;

    private function __construct()
    {
        $dir = sys_get_temp_dir() . '/phlix-alexa-fixture-' . getmypid();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('could not create fixture dir ' . $dir);
        }

        $this->configPath = $dir . '/openssl.cnf';
        file_put_contents($this->configPath, self::OPENSSL_CONFIG);

        // --- the trusted world -------------------------------------------------
        [$rootCert, $rootKey] = $this->selfSignedCa('Phlix Test Alexa Root CA', 1);
        [$interCert, $interKey] = $this->intermediate('Phlix Test Alexa Intermediate', $rootCert, $rootKey, 2);

        $this->trustedCaFile = $dir . '/trusted-root.pem';
        file_put_contents($this->trustedCaFile, self::exportCert($rootCert));

        $interPem = self::exportCert($interCert);

        // --- the untrusted world (a perfectly well-formed chain to nowhere) ----
        [$rogueRoot, $rogueRootKey] = $this->selfSignedCa('Rogue Alexa Root CA', 11);
        $rogueInterPem = self::exportCert($rogueRoot);

        // --- leaves ------------------------------------------------------------
        $this->addLeaf('valid', 'leaf_echo_san', $interCert, $interKey, $interPem, 3);
        $this->addLeaf('wrongSan', 'leaf_lookalike_san', $interCert, $interKey, $interPem, 4);
        $this->addLeaf('multiSan', 'leaf_multi_san', $interCert, $interKey, $interPem, 5);
        $this->addLeaf('untrusted', 'leaf_echo_san', $rogueRoot, $rogueRootKey, $rogueInterPem, 6);
        $this->addLeaf('noSan', 'leaf_no_san', $interCert, $interKey, $interPem, 7);
        $this->addLeaf('noExtensions', null, $interCert, $interKey, $interPem, 8);
        $this->addLeaf('ipSanFirst', 'leaf_ip_then_echo_san', $interCert, $interKey, $interPem, 9);

        // A key that never appears in any chain — used to forge signatures.
        $strangerKey = openssl_pkey_new(self::KEY_OPTIONS);
        if ($strangerKey === false) {
            throw new RuntimeException('could not generate the stranger key');
        }
        $this->strangerKey = $strangerKey;
    }

    /**
     * The process-wide fixture.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Path to the generated root CA, for the middleware's `$caBundlePaths`.
     *
     * @return list<string>
     */
    public function trustedCaBundle(): array
    {
        return [$this->trustedCaFile];
    }

    /**
     * A PEM chain (leaf + issuer) for the named variant.
     *
     * Variants:
     *  - `valid`     — SAN exactly `echo-api.amazon.com`, chains to the trusted root.
     *  - `wrongSan`  — SAN `echo-api.amazon.com.attacker.test`; a SUPERSTRING of
     *                  the real name, so a `str_contains()`-shaped SAN check
     *                  would accept it. Everything else about it is correct.
     *  - `multiSan`  — several SAN entries, the echo name among them but NOT
     *                  first; the control proving the SAN loop looks past entry 0.
     *  - `untrusted` — correct SAN, correct dates, well-formed chain, but its
     *                  root is not in the configured trust store.
     *  - `noSan`     — has an extensions block, but no `subjectAltName` at all.
     *  - `noExtensions` — no extensions block whatsoever; a distinct shape from
     *                  `noSan`, reaching a different branch of the SAN check.
     *  - `ipSanFirst` — SAN whose first entries are `IP:` and `email:` types
     *                  before the echo `DNS:` name; the control proving a
     *                  non-DNS entry is skipped rather than mis-read.
     */
    public function chain(string $variant): string
    {
        if (!isset($this->chains[$variant])) {
            throw new RuntimeException('unknown chain variant: ' . $variant);
        }

        return $this->chains[$variant];
    }

    /**
     * Base64 signature over `$body` made with the named variant's leaf key —
     * i.e. the signature Amazon would send.
     */
    public function sign(string $body, string $variant = 'valid'): string
    {
        if (!isset($this->leafKeys[$variant])) {
            throw new RuntimeException('unknown chain variant: ' . $variant);
        }

        $signature = '';
        if (openssl_sign($body, $signature, $this->leafKeys[$variant], OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('could not sign the fixture body');
        }

        return base64_encode($signature);
    }

    /**
     * Base64 signature over `$body` made with a key that appears in NO chain —
     * a structurally perfect signature by the wrong signer.
     */
    public function forge(string $body): string
    {
        $signature = '';
        if (openssl_sign($body, $signature, $this->strangerKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('could not forge the fixture body signature');
        }

        return base64_encode($signature);
    }

    /** RSA-2048, matching the key size Amazon's real Echo leaf uses. */
    private const KEY_OPTIONS = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    /**
     * A complete openssl config, written to a temp file and passed explicitly to
     * every call. Not relying on the host `openssl.cnf` keeps this fixture
     * identical on the dev box and on the CI runner.
     */
    private const OPENSSL_CONFIG = <<<'CNF'
        [req]
        distinguished_name = req_dn
        prompt = no

        [req_dn]
        CN = placeholder

        [ca_ext]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash

        [leaf_echo_san]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:echo-api.amazon.com

        [leaf_lookalike_san]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:echo-api.amazon.com.attacker.test

        [leaf_multi_san]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:first.example.test, DNS:echo-api.amazon.com, DNS:last.example.test

        [leaf_no_san]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth

        [leaf_ip_then_echo_san]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = serverAuth
        subjectAltName = IP:127.0.0.1, email:ops@example.test, DNS:echo-api.amazon.com
        CNF;

    /**
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey}
     */
    private function selfSignedCa(string $commonName, int $serial): array
    {
        $key = openssl_pkey_new(self::KEY_OPTIONS);
        if ($key === false) {
            throw new RuntimeException('could not generate a CA key');
        }

        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('could not build the CA CSR');
        }

        $cert = openssl_csr_sign($csr, null, $key, 3650, [
            'digest_alg' => 'sha256',
            'config' => $this->configPath,
            'x509_extensions' => 'ca_ext',
        ], $serial);
        if ($cert === false) {
            throw new RuntimeException('could not self-sign the CA');
        }

        return [$cert, $key];
    }

    /**
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey}
     */
    private function intermediate(
        string $commonName,
        OpenSSLCertificate $issuerCert,
        OpenSSLAsymmetricKey $issuerKey,
        int $serial,
    ): array {
        $key = openssl_pkey_new(self::KEY_OPTIONS);
        if ($key === false) {
            throw new RuntimeException('could not generate an intermediate key');
        }

        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('could not build the intermediate CSR');
        }

        $cert = openssl_csr_sign($csr, $issuerCert, $issuerKey, 1825, [
            'digest_alg' => 'sha256',
            'config' => $this->configPath,
            'x509_extensions' => 'ca_ext',
        ], $serial);
        if ($cert === false) {
            throw new RuntimeException('could not sign the intermediate');
        }

        return [$cert, $key];
    }

    /**
     * Generate a leaf under `$issuerCert` and record it as `$variant`.
     *
     * `$extensionSection` of null signs the CSR with no `x509_extensions` at
     * all, producing a certificate with no extensions block whatsoever — a
     * different shape from "has extensions but no subjectAltName", and the two
     * reach different branches of the SAN check.
     */
    private function addLeaf(
        string $variant,
        ?string $extensionSection,
        OpenSSLCertificate $issuerCert,
        OpenSSLAsymmetricKey $issuerKey,
        string $issuerPem,
        int $serial,
    ): void {
        $key = openssl_pkey_new(self::KEY_OPTIONS);
        if ($key === false) {
            throw new RuntimeException('could not generate a leaf key');
        }

        $csr = openssl_csr_new(['commonName' => 'echo-api.amazon.com'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('could not build the leaf CSR');
        }

        $options = ['digest_alg' => 'sha256', 'config' => $this->configPath];
        if ($extensionSection !== null) {
            $options['x509_extensions'] = $extensionSection;
        }

        $cert = openssl_csr_sign($csr, $issuerCert, $issuerKey, 365, $options, $serial);
        if ($cert === false) {
            throw new RuntimeException('could not sign the leaf');
        }

        $this->chains[$variant] = self::exportCert($cert) . $issuerPem;
        $this->leafKeys[$variant] = $key;
    }

    private static function exportCert(OpenSSLCertificate $cert): string
    {
        $pem = '';
        if (openssl_x509_export($cert, $pem) !== true) {
            throw new RuntimeException('could not export a certificate');
        }

        return $pem;
    }
}
