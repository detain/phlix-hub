<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use RuntimeException;

/**
 * Manages TLS certificates for server subdomains.
 *
 * NOTE: ACME (Let's Encrypt) automated provisioning advertised by Step
 * C.8 in CHANGELOG.md is NOT implemented in this build. Calling
 * {@see provisionCertificate()} or the underlying ACME challenge will
 * throw a {@see RuntimeException}. Operators must provision certs
 * out-of-band — see docs/hub-admin/tls.md.
 *
 * The read-side helpers ({@see getCertificatePath()},
 * {@see getPrivateKeyPath()}) honestly return null when the on-disk
 * files are missing, so callers can detect "not provisioned" without
 * being lied to.
 *
 * @package Phlix\Hub\Hub
 * @since 0.12.0
 */
class TlsCertificateManager
{
    /** Duration before expiry to trigger renewal (60 days). */
    private const int RENEW_BEFORE_DAYS = 60;

    /**
     * Stable, machine-grep-able exception message emitted by every
     * code path that pretends to "provision" a certificate. Tests pin
     * to this exact string so a future regression to the silent-stub
     * behaviour fails fast.
     */
    public const string NOT_IMPLEMENTED_MESSAGE =
        'ACME certificate provisioning is not implemented in this build. '
        . 'Provision certs out-of-band — see docs/hub-admin/tls.md.';

    /**
     * @param string                                   $certsDir   Directory to store certificates.
     * @param string                                   $acmeEmail  Email for Let's Encrypt account (unused; reserved).
     * @param \Phlix\Hub\Common\Logger\StructuredLogger $logger     Application logger.
     */
    public function __construct(
        private readonly string $certsDir,
        private readonly string $acmeEmail,
        private readonly \Phlix\Hub\Common\Logger\StructuredLogger $logger,
    ) {
        // $acmeEmail is retained on the constructor signature for forward
        // compatibility with the eventual ACME implementation; until then
        // it is only exposed via getAcmeEmail() for diagnostics/logging.
    }

    /**
     * Return the configured ACME account email.
     *
     * Exposed for operators / diagnostic endpoints that want to show
     * which contact would be used once ACME is implemented.
     *
     * @return string Configured ACME contact email.
     */
    public function getAcmeEmail(): string
    {
        return $this->acmeEmail;
    }

    /**
     * Provision a TLS certificate for a subdomain.
     *
     * NOT IMPLEMENTED. This build does not ship automated ACME
     * provisioning. Operators must install certs out-of-band; see
     * docs/hub-admin/tls.md.
     *
     * @param string $subdomain Subdomain label (e.g. "abc12345").
     *
     * @return bool Never returns — always throws.
     *
     * @throws RuntimeException Always, with {@see NOT_IMPLEMENTED_MESSAGE}.
     *
     * @since 0.12.0
     */
    public function provisionCertificate(string $subdomain): bool
    {
        $fqdn = $subdomain . '.phlix.media';

        $this->logger->warning(
            'TlsCertificateManager::provisionCertificate() called but ACME is not implemented',
            ['fqdn' => $fqdn],
        );

        throw new RuntimeException(self::NOT_IMPLEMENTED_MESSAGE);
    }

    /**
     * Check whether a subdomain has a usable cert on disk.
     *
     * Truthful: returns true iff both fullchain.pem and privkey.pem
     * exist for the FQDN under the configured certs dir. Does not
     * attempt to provision anything.
     *
     * @param string $subdomain Subdomain label (e.g. "abc12345").
     *
     * @return bool True if both cert files are present.
     *
     * @since 0.12.0
     */
    public function isProvisioned(string $subdomain): bool
    {
        $fqdn = $subdomain . '.phlix.media';

        return $this->certificateExists($fqdn);
    }

    /**
     * Get the fullchain certificate path for a subdomain.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return string|null Full path to fullchain.pem or null if not provisioned.
     *
     * @since 0.12.0
     */
    public function getCertificatePath(string $subdomain): ?string
    {
        $fqdn = $subdomain . '.phlix.media';
        $path = $this->certsDir . '/' . $fqdn . '/fullchain.pem';

        if (!file_exists($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Get the private key path for a subdomain.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return string|null Full path to privkey.pem or null if not provisioned.
     *
     * @since 0.12.0
     */
    public function getPrivateKeyPath(string $subdomain): ?string
    {
        $fqdn = $subdomain . '.phlix.media';
        $path = $this->certsDir . '/' . $fqdn . '/privkey.pem';

        if (!file_exists($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Check if a certificate exists for a subdomain.
     *
     * @param string $fqdn Full domain name.
     *
     * @return bool True if certificate files exist.
     */
    private function certificateExists(string $fqdn): bool
    {
        $certPath = $this->certsDir . '/' . $fqdn . '/fullchain.pem';
        $keyPath = $this->certsDir . '/' . $fqdn . '/privkey.pem';

        return file_exists($certPath) && file_exists($keyPath);
    }

    /**
     * Check if a certificate needs renewal.
     *
     * Used by external monitoring/cron scripts. Pure read; never
     * mutates state or invokes ACME.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return bool True if certificate is missing or expires within RENEW_BEFORE_DAYS.
     *
     * @since 0.12.0
     */
    public function needsRenewal(string $subdomain): bool
    {
        $fqdn = $subdomain . '.phlix.media';
        $certPath = $this->certsDir . '/' . $fqdn . '/fullchain.pem';

        if (!file_exists($certPath)) {
            return true;
        }

        $certFile = file_get_contents($certPath);
        if ($certFile === false || !str_contains($certFile, 'BEGIN CERTIFICATE')) {
            return true;
        }

        // Use proc_open with an argv array so the FQDN-derived path
        // cannot influence shell parsing. The cert content is fed via
        // stdin rather than letting the shell expand redirection.
        $exitCode = 0;
        $output = $this->runOpenssl(
            ['openssl', 'x509', '-noout', '-enddate'],
            $certFile,
            $exitCode,
        );

        if ($exitCode !== 0 || $output === '') {
            return true;
        }

        $lines = preg_split('/\r?\n/', $output) ?: [];
        $firstLine = $lines[0] ?? '';
        if ($firstLine !== '' && preg_match('/notAfter=(.+)/i', $firstLine, $matches) === 1) {
            $expiry = strtotime(trim($matches[1]));
            if ($expiry !== false) {
                $renewalTime = $expiry - (self::RENEW_BEFORE_DAYS * 86400);
                return time() >= $renewalTime;
            }
        }

        return true;
    }

    /**
     * Run an openssl command via proc_open with an argv array.
     *
     * No shell, no escapeshellcmd. Argument values cannot be
     * misinterpreted as flags or redirection.
     *
     * @param list<string> $argv     Argv (argv[0] is the program).
     * @param string|null  $stdin    Optional stdin payload.
     * @param int          $exitCode Out-param: child exit code.
     *
     * @return string Captured stdout.
     */
    private function runOpenssl(array $argv, ?string $stdin, int &$exitCode): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            $exitCode = -1;
            return '';
        }

        if ($stdin !== null && isset($pipes[0]) && is_resource($pipes[0])) {
            // Non-blocking stdin: a small child that exits before fully
            // draining stdin must not deadlock the parent fwrite() if a
            // future caller passes a large payload.
            stream_set_blocking($pipes[0], false);
            fwrite($pipes[0], $stdin);
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stdout = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            // Defensive: ensure stdout reads block until the child
            // closes its end, so stream_get_contents() returns the
            // full output rather than a partial buffer.
            stream_set_blocking($pipes[1], true);
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);

        return $stdout;
    }
}
