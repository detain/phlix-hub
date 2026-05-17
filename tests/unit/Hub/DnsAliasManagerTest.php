<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Hub\DnsAliasManager;
use Phlex\Hub\Hub\Dns\StaticZoneManager;
use Phlex\Hub\Hub\TlsCertificateManager;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class DnsAliasManagerTest extends TestCase
{
    private string $tmpDir;
    private Connection $db;
    private StaticZoneManager $zoneManager;
    private TlsCertificateManager $certManager;
    private StructuredLogger $logger;
    private DnsAliasManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-dns-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->db = $this->createMock(Connection::class);
        $this->zoneManager = new StaticZoneManager($this->tmpDir . '/zones');
        $this->certManager = $this->createMock(TlsCertificateManager::class);
        $this->logger = new StructuredLogger('test', []);

        $this->manager = new DnsAliasManager(
            $this->db,
            $this->zoneManager,
            $this->certManager,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmpDir);
        parent::tearDown();
    }

    public function test_getFqdn_returns_full_domain(): void
    {
        $subdomain = 'abc12345';

        $fqdn = $this->manager->getFqdn($subdomain);

        $this->assertSame('abc12345.phlex.media', $fqdn);
    }

    public function test_allocateSubdomain_generates_8_char_subdomain(): void
    {
        $serverId = 'server-123-uuid';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($serverId) {
                if (str_contains($sql, 'UPDATE servers SET subdomain')) {
                    return [];
                }
                if (str_contains($sql, 'SELECT subdomain FROM servers')) {
                    return [];
                }
                if (str_contains($sql, 'SELECT id FROM servers WHERE subdomain')) {
                    return [];
                }
                return [];
            });

        $this->certManager->method('provisionCertificate')->willReturn(true);

        $subdomain = $this->manager->allocateSubdomain($serverId);

        $this->assertIsString($subdomain);
        $this->assertSame(8, strlen($subdomain));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $subdomain);
    }

    public function test_getSubdomain_returns_null_when_not_allocated(): void
    {
        $serverId = 'server-unallocated';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'SELECT subdomain FROM servers')) {
                    return [];
                }
                return [];
            });

        $subdomain = $this->manager->getSubdomain($serverId);

        $this->assertNull($subdomain);
    }

    public function test_resolve_returns_null_for_unknown_subdomain(): void
    {
        $subdomain = 'unknown123';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'SELECT id FROM servers WHERE subdomain')) {
                    return [];
                }
                return [];
            });

        $resolved = $this->manager->resolve($subdomain);

        $this->assertNull($resolved);
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
