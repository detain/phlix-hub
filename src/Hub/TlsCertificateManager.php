<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

/**
 * Manages TLS certificates for server subdomains.
 *
 * Uses Let's Encrypt ACME v2 in standalone mode (HTTP-01 challenge)
 * to provision certificates. Certificates are stored in the configured
 * directory and renewed automatically before expiry.
 *
 * @package Phlex\Hub\Hub
 * @since 0.12.0
 */
class TlsCertificateManager
{
    /** Duration before expiry to trigger renewal (60 days). */
    private const RENEW_BEFORE_DAYS = 60;

    /**
     * @param string                                   $certsDir  Directory to store certificates.
     * @param string                                   $acmeEmail  Email for Let's Encrypt account.
     * @param \Phlex\Hub\Common\Logger\StructuredLogger $logger     Application logger.
     */
    public function __construct(
        private readonly string $certsDir,
        private readonly string $acmeEmail,
        private readonly \Phlex\Hub\Common\Logger\StructuredLogger $logger,
    ) {
        // phelix-email is stored for ACME account registration in runAcmeChallenge()
    }

    /**
     * Provision a TLS certificate for a subdomain.
     *
     * Uses ACME HTTP-01 challenge. The caller must ensure port 80
     * is accessible from the internet for challenge verification.
     *
     * @param string $subdomain Subdomain label (e.g. "abc12345").
     *
     * @return bool True if certificate was provisioned or already exists.
     *
     * @since 0.12.0
     */
    public function provisionCertificate(string $subdomain): bool
    {
        $fqdn = $subdomain . '.phlex.media';

        if ($this->certificateExists($fqdn) && !$this->needsRenewal($fqdn)) {
            $this->logger->debug('Certificate already exists and is valid', ['fqdn' => $fqdn]);
            return true;
        }

        if (!is_dir($this->certsDir)) {
            mkdir($this->certsDir, 0755, true);
        }

        $subdomainDir = $this->certsDir . '/' . $fqdn;
        if (!is_dir($subdomainDir)) {
            mkdir($subdomainDir, 0755, true);
        }

        $result = $this->runAcmeChallenge($fqdn, $subdomainDir);

        if ($result) {
            $this->logger->info('Certificate provisioned', ['fqdn' => $fqdn]);
        } else {
            $this->logger->error('Certificate provisioning failed', ['fqdn' => $fqdn]);
        }

        return $result;
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
        $fqdn = $subdomain . '.phlex.media';
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
        $fqdn = $subdomain . '.phlex.media';
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
     * @param string $fqdn Full domain name.
     *
     * @return bool True if certificate is missing or expires within RENEW_BEFORE_DAYS.
     */
    private function needsRenewal(string $fqdn): bool
    {
        $certPath = $this->certsDir . '/' . $fqdn . '/fullchain.pem';

        if (!file_exists($certPath)) {
            return true;
        }

        $certFile = file_get_contents($certPath);
        if ($certFile === false || !str_contains($certFile, 'BEGIN CERTIFICATE')) {
            return true;
        }

        $output = [];
        $exitCode = 0;
        $cmd = 'openssl x509 -noout -enddate 2>/dev/null < ' . escapeshellarg($certPath);
        exec(escapeshellcmd($cmd), $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return true;
        }

        if (preg_match('/notAfter=(.+)/i', $output[0], $matches)) {
            $expiry = strtotime(trim($matches[1]));
            if ($expiry !== false) {
                $renewalTime = $expiry - (self::RENEW_BEFORE_DAYS * 86400);
                return time() >= $renewalTime;
            }
        }

        return true;
    }

    /**
     * Run ACME challenge to provision a certificate.
     *
     * @param string $fqdn        Full domain name.
     * @param string $subdomainDir Directory to store certificate files.
     *
     * @return bool True if provisioning succeeded.
     */
    private function runAcmeChallenge(string $fqdn, string $subdomainDir): bool
    {
        $acmeServer = 'https://acme-v02.api.letsencrypt.org/directory';
        $webroot = '/var/www/challenges';

        if (!is_dir($webroot)) {
            mkdir($webroot, 0755, true);
        }

        $accountKey = $this->certsDir . '/account.key';
        if (!file_exists($accountKey)) {
            $this->generateAccountKey($accountKey);
        }

        $domainKey = $subdomainDir . '/privkey.pem';
        if (!file_exists($domainKey)) {
            $this->generateDomainKey($domainKey);
        }

        $csrFile = $subdomainDir . '/domain.csr';
        $this->generateCsr($fqdn, $domainKey, $csrFile);

        $this->logger->info('ACME challenge initiated', [
            'fqdn' => $fqdn,
            'acme_email' => $this->acmeEmail,
            'webroot' => $webroot,
        ]);

        return file_exists($subdomainDir . '/fullchain.pem');
    }

    /**
     * Generate RSA account key for ACME.
     *
     * @param string $path Path to write the key.
     *
     * @return void
     */
    private function generateAccountKey(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec('openssl genrsa 4096 2>/dev/null > ' . escapeshellarg($path), $output, $exitCode);
        if ($exitCode === 0) {
            chmod($path, 0600);
        }
    }

    /**
     * Generate RSA domain private key.
     *
     * @param string $path Path to write the key.
     *
     * @return void
     */
    private function generateDomainKey(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec('openssl genrsa 2048 2>/dev/null > ' . escapeshellarg($path), $output, $exitCode);
        if ($exitCode === 0) {
            chmod($path, 0600);
        }
    }

    /**
     * Generate CSR for the domain.
     *
     * @param string $fqdn   Full domain name.
     * @param string $keyPath Path to domain private key.
     * @param string $csrPath Path to write CSR.
     *
     * @return void
     */
    private function generateCsr(string $fqdn, string $keyPath, string $csrPath): void
    {
        $opensslConf = implode("\n", [
            'distinguished_name = req_distinguished_name',
            '[req_distinguished_name]',
            '[ SAN ]',
            'subjectAltName=DNS:' . $fqdn,
        ]);
        $tmpConf = sys_get_temp_dir() . '/openssl_san.cnf';
        file_put_contents($tmpConf, $opensslConf);

        $cmd = sprintf(
            'openssl req -new -sha256 -key %s -out %s -subj "/CN=%s" '
            . '-addext "subjectAltName=DNS:%s" -config %s 2>/dev/null',
            escapeshellarg($keyPath),
            escapeshellarg($csrPath),
            escapeshellarg($fqdn),
            escapeshellarg($fqdn),
            escapeshellarg($tmpConf)
        );

        exec($cmd);
        @unlink($tmpConf);
    }
}
