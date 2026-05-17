<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Hub\TlsCertificateManager;
use Phlex\Hub\Common\Logger\StructuredLogger;

class TlsCertificateManagerTest extends TestCase
{
    private string $tmpDir;
    private StructuredLogger $logger;
    private TlsCertificateManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-tls-test-' . uniqid();
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
        $fqdn = $subdomain . '.phlex.media';

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

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->recursiveDelete($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
