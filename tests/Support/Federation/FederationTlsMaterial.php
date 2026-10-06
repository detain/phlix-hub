<?php

/**
 * Throwaway TLS material + free-port reservation for the real-TLS federation
 * dial E2E (tests/Unit/Federation/FederationMasterTlsDialTest.php).
 *
 * Everything is generated IN-PROCESS with ext-openssl — no openssl CLI
 * dependency, no committed key files, nothing that outlives the test tmp dir.
 * The shapes mirror the venue-proven pattern from tests/Support/Alexa/:
 * a CA, a leaf server cert signed by it (SAN DNS:localhost + IPs so
 * peer-name verification against 'localhost' genuinely exercises the SAN
 * path), and a SECOND unrelated CA used to prove verify_peer is real —
 * a client trusting only that other anchor must be refused.
 *
 * @copyright 2026 Phlix
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Federation;

use RuntimeException;

/**
 * Generates the disposable certificate chain the TLS dial E2E runs against.
 */
final class FederationTlsMaterial
{
    /**
     * Write ca.pem / server.pem (+ server key inside) / other-ca.pem into $dir.
     *
     * @param string $dir Existing, test-owned directory (removed by the caller).
     *
     * @return array{ca: string, server: string, other_ca: string} Absolute paths.
     */
    public static function generate(string $dir): array
    {
        $caKey = self::privateKey();
        $caCert = self::signCa($caKey, 'Phlix E2E Federation CA #1');

        $otherKey = self::privateKey();
        $otherCert = self::signCa($otherKey, 'Phlix E2E Federation CA #2 (untrusted)');

        $serverKey = self::privateKey();
        $serverCert = self::signServer($serverKey, $caKey, $caCert);

        $paths = [
            'ca' => $dir . '/ca.pem',
            'server' => $dir . '/server.pem',
            'other_ca' => $dir . '/other-ca.pem',
        ];

        self::write($paths['ca'], self::export($caCert));
        self::write($paths['server'], self::export($serverCert) . self::export($serverKey));
        self::write($paths['other_ca'], self::export($otherCert));

        return $paths;
    }

    /**
     * Reserve (bind-then-release) an ephemeral loopback port for the child
     * TLS listener. Workerman v5.2.2 exposes no getSocket() on Worker, so the
     * parent picks the port deterministically and passes it as an argument.
     */
    public static function reservePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("reservePort: cannot bind loopback: $errstr");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException("reservePort: could not read back port from '$name'");
        }

        return $port;
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private static function privateKey()
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('TLS material: openssl_pkey_new failed: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * Self-signed CA certificate.
     *
     * @param \OpenSSLAsymmetricKey $key
     *
     * @return \OpenSSLCertificate
     */
    private static function signCa($key, string $cn)
    {
        $csr = openssl_csr_new(
            ['CN' => $cn],
            $key,
            ['digest_alg' => 'sha256', 'config' => self::configPath()],
        );
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('TLS material: CA csr failed: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, null, $key, 2, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'phlix_ca_ext',
            'config' => self::configPath(),
        ]);
        if ($cert === false) {
            throw new RuntimeException('TLS material: CA sign failed: ' . openssl_error_string());
        }

        return $cert;
    }

    /**
     * Server leaf signed by the CA, SAN DNS:localhost + loopback IPs so SNI /
     * peer_name 'localhost' verifies against the SAN (CN fallback is long dead).
     *
     * @param \OpenSSLAsymmetricKey $serverKey
     * @param \OpenSSLAsymmetricKey $caKey
     * @param \OpenSSLCertificate      $caCert
     *
     * @return \OpenSSLCertificate
     */
    private static function signServer($serverKey, $caKey, $caCert)
    {
        $csr = openssl_csr_new(
            ['CN' => 'localhost'],
            $serverKey,
            ['digest_alg' => 'sha256', 'config' => self::configPath()],
        );
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('TLS material: server csr failed: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, $caCert, $caKey, 2, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'phlix_server_ext',
            'config' => self::configPath(),
        ]);
        if ($cert === false) {
            throw new RuntimeException('TLS material: server sign failed: ' . openssl_error_string());
        }

        return $cert;
    }

    /**
     * A tiny openssl.cnf written once per process into the system tmp — the
     * extension blocks openssl_csr_sign's x509_extensions names reference.
     */
    private static function configPath(): string
    {
        $path = sys_get_temp_dir() . '/phlix-federation-tls-' . getmypid() . '.cnf';
        if (is_file($path)) {
            return $path;
        }

        $cnf = <<<'CNF'
        [req]
        distinguished_name = req_dn
        prompt = no

        [req_dn]
        CN = localhost

        [phlix_ca_ext]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign

        [phlix_server_ext]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, keyEncipherment
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:localhost, IP:127.0.0.1, IP:0:0:0:0:0:0:0:1
        CNF;

        file_put_contents($path, $cnf);

        return $path;
    }

    /**
     * @param \OpenSSLCertificate|\OpenSSLAsymmetricKey $thing
     */
    private static function export(object $thing): string
    {
        $out = '';
        if ($thing instanceof \OpenSSLCertificate) {
            if (!openssl_x509_export($thing, $out)) {
                throw new RuntimeException('TLS material: x509 export failed');
            }

            return $out;
        }

        if (!openssl_pkey_export($thing, $out)) {
            throw new RuntimeException('TLS material: key export failed');
        }

        return $out;
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("TLS material: cannot write $path");
        }
    }
}
