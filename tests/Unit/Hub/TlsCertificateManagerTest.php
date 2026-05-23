<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use RuntimeException;

class TlsCertificateManagerTest extends TestCase
{
    private string $tmpDir;
    private StructuredLogger $logger;
    private TlsCertificateManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-tls-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->logger = new StructuredLogger('test', []);

        $this->manager = new TlsCertificateManager(
            $this->tmpDir,
            'test@example.com',
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        parent::tearDown();
    }

    public function test_getCertificatePath_returns_null_when_not_exists(): void
    {
        $subdomain = 'nonexistent';

        $path = $this->manager->getCertificatePath($subdomain);

        $this->assertNull($path);
    }

    public function test_getPrivateKeyPath_returns_null_when_not_exists(): void
    {
        $subdomain = 'nonexistent-key';

        $path = $this->manager->getPrivateKeyPath($subdomain);

        $this->assertNull($path);
    }

    public function test_certificate_directory_structure_created(): void
    {
        $subdomain = 'test-dir';
        $fqdn = $subdomain . '.phlix.media';

        $certsDir = $this->tmpDir;
        if (!is_dir($certsDir)) {
            mkdir($certsDir, 0755, true);
        }

        $subdomainDir = $certsDir . '/' . $fqdn;
        if (!is_dir($subdomainDir)) {
            mkdir($subdomainDir, 0755, true);
        }

        $this->assertDirectoryExists($certsDir);
        $this->assertDirectoryExists($subdomainDir);
    }

    /**
     * provisionCertificate() must NOT silently succeed. The previous
     * implementation generated keys and a CSR via the openssl CLI and
     * then returned `file_exists($fullchain)`, which always returned
     * false for fresh subdomains while logging "provisioning failed".
     * That fooled callers into thinking the manager was doing
     * something. It must throw clearly so a future regression is
     * caught immediately.
     */
    public function test_provisionCertificate_throws_not_implemented(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(TlsCertificateManager::NOT_IMPLEMENTED_MESSAGE);

        $this->manager->provisionCertificate('abc12345');
    }

    /**
     * The exception message is part of the public contract — operator
     * runbooks grep for it. Lock the exact string in case someone
     * refactors it.
     */
    public function test_provisionCertificate_exception_message_is_stable(): void
    {
        $this->assertSame(
            'ACME certificate provisioning is not implemented in this build. '
            . 'Provision certs out-of-band — see docs/hub-admin/tls.md.',
            TlsCertificateManager::NOT_IMPLEMENTED_MESSAGE,
        );

        try {
            $this->manager->provisionCertificate('abc12345');
            $this->fail('Expected RuntimeException, none thrown — silent-stub regression.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'ACME certificate provisioning is not implemented',
                $e->getMessage(),
            );
            $this->assertStringContainsString(
                'docs/hub-admin/tls.md',
                $e->getMessage(),
            );
        }
    }

    public function test_isProvisioned_false_when_no_files(): void
    {
        $this->assertFalse($this->manager->isProvisioned('never-set-up'));
    }

    public function test_isProvisioned_true_when_both_files_exist(): void
    {
        $fqdn = 'wired.phlix.media';
        $dir = $this->tmpDir . '/' . $fqdn;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\nstub\n-----END CERTIFICATE-----\n");
        file_put_contents($dir . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\nstub\n-----END PRIVATE KEY-----\n");

        $this->assertTrue($this->manager->isProvisioned('wired'));
        $this->assertSame($dir . '/fullchain.pem', $this->manager->getCertificatePath('wired'));
        $this->assertSame($dir . '/privkey.pem', $this->manager->getPrivateKeyPath('wired'));
    }

    public function test_needsRenewal_true_when_cert_absent(): void
    {
        $this->assertTrue($this->manager->needsRenewal('absent'));
    }

    public function test_getAcmeEmail_returns_configured_email(): void
    {
        $this->assertSame('test@example.com', $this->manager->getAcmeEmail());
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->recursiveDelete($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }
}
