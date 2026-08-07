<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Alexa;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Spawns {@see tls_probe_server.php} in a child process and hands back its URL.
 *
 * A real TLS listener, not a mock, because the property under test —
 * "`CURLOPT_CONNECTTIMEOUT` does not bound a peer that stalls AFTER the
 * handshake" — is a property of libcurl, and no test double has it.
 *
 * The child binds an ephemeral port and writes it to a file; this class waits
 * for that file rather than guessing a port, so two of these can run
 * concurrently and neither can collide with whatever else is listening on the
 * box.
 */
final class TlsProbeServer
{
    /** @var resource|null */
    private $process = null;

    private string $dir;

    private string $portFile;

    private int $port = 0;

    private function __construct(string $mode, int $stallSeconds, string $body)
    {
        $dir = sys_get_temp_dir() . '/phlix-alexa-tls-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('could not create ' . $dir);
        }
        $this->dir = $dir;
        $this->portFile = $dir . '/port';

        $certFile = $dir . '/server.pem';
        file_put_contents($certFile, self::selfSignedLocalhostPem());

        $bodyFile = $dir . '/body.pem';
        file_put_contents($bodyFile, $body);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/tls_probe_server.php', $certFile, $mode, (string) $stallSeconds,
                $this->portFile, $bodyFile],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('could not start the TLS probe server');
        }
        $this->process = $process;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $raw = @file_get_contents($this->portFile);
            if (is_string($raw) && trim($raw) !== '') {
                $this->port = (int) trim($raw);
                break;
            }
            usleep(20_000);
        }

        if ($this->port <= 0) {
            $this->stop();
            throw new RuntimeException('the TLS probe server never reported a port');
        }
    }

    /**
     * A server that completes the handshake and then sends nothing for
     * `$stallSeconds`.
     */
    public static function stalling(int $stallSeconds): self
    {
        return new self('stall', $stallSeconds, '');
    }

    /**
     * A server that completes the handshake and answers 200 with `$body`.
     */
    public static function serving(string $body): self
    {
        return new self('serve', 0, $body);
    }

    /**
     * A server that completes the handshake and writes `$rawResponse` to the
     * socket verbatim — status line, headers and all.
     */
    public static function servingRaw(string $rawResponse): self
    {
        return new self('raw', 0, $rawResponse);
    }

    /**
     * The same, but over plain TCP with no TLS at all — reachable only via an
     * `http://` URL. Used to show the fetcher's protocol pin refuses plain http
     * even when a server is sitting there ready to answer 200.
     */
    public static function servingRawWithoutTls(string $rawResponse): self
    {
        return new self('plain-raw', 0, $rawResponse);
    }

    /**
     * A server that answers the first connection with a 302 pointing at itself
     * (same host, same scheme) and the second with 200 + `$body`. Following the
     * redirect therefore SUCCEEDS, which is what makes
     * `CURLOPT_FOLLOWLOCATION => false` observable: with it, the fetch sees a
     * 302 and returns null; without it, the fetch returns `$body`.
     */
    public static function redirectingTo(string $body): self
    {
        return new self('redirect', 0, $body);
    }

    /**
     * The plain-http URL for a server started with
     * {@see servingRawWithoutTls()}.
     */
    public function plainUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/echo.api/probe.pem';
    }

    /**
     * The URL to point a fetcher at. Uses `localhost` (not `127.0.0.1`) so the
     * fetcher's `CURLOPT_SSL_VERIFYHOST => 2` is genuinely exercised against the
     * certificate's `DNS:localhost` SAN.
     */
    public function url(): string
    {
        return 'https://localhost:' . $this->port . '/echo.api/probe.pem';
    }

    /**
     * The CA file to trust for {@see \Phlix\Hub\Alexa\CurlCertChainFetcher}'s
     * `$caInfoPath`. The server certificate is its own root.
     */
    public function caFile(): string
    {
        return $this->dir . '/server.pem';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
            $this->process = null;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * A throwaway self-signed certificate + key for `localhost`, valid for a
     * day. Generated in-process so the suite does not depend on the `openssl`
     * CLI being installed.
     */
    private static function selfSignedLocalhostPem(): string
    {
        $configPath = tempnam(sys_get_temp_dir(), 'phlix-tls-probe-cnf-');
        Assert::assertIsString($configPath);
        file_put_contents($configPath, <<<'CNF'
            [req]
            distinguished_name = req_dn
            prompt = no

            [req_dn]
            CN = localhost

            [server_ext]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,digitalSignature,keyCertSign
            extendedKeyUsage = serverAuth
            subjectAltName = DNS:localhost, IP:127.0.0.1
            CNF);

        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false) {
                throw new RuntimeException('could not generate the probe server key');
            }

            $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
            if ($csr === false) {
                throw new RuntimeException('could not build the probe server CSR');
            }

            $cert = openssl_csr_sign($csr, null, $key, 1, [
                'digest_alg' => 'sha256',
                'config' => $configPath,
                'x509_extensions' => 'server_ext',
            ], random_int(1, PHP_INT_MAX));
            if ($cert === false) {
                throw new RuntimeException('could not self-sign the probe server certificate');
            }

            $certPem = '';
            openssl_x509_export($cert, $certPem);
            $keyPem = '';
            openssl_pkey_export($key, $keyPem, null, ['config' => $configPath]);

            return $certPem . $keyPem;
        } finally {
            @unlink($configPath);
        }
    }
}
