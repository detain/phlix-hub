<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Dns\StaticZoneManager;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
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
        $this->tmpDir = sys_get_temp_dir() . '/phlix-dns-test-' . uniqid();
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

        $this->assertSame('abc12345.phlix.media', $fqdn);
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

        // allocateSubdomain() no longer triggers TLS provisioning;
        // operators install certs out-of-band (docs/hub-admin/tls.md).
        // The mock therefore expects no call at all.
        $this->certManager->expects($this->never())->method('provisionCertificate');

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

    /**
     * In this build TlsCertificateManager::provisionCertificate()
     * throws RuntimeException (ACME not implemented). Subdomain
     * allocation must still succeed — and not even call the cert
     * manager — so DNS works while operators install TLS material
     * out-of-band; see docs/hub-admin/tls.md.
     */
    public function test_allocateSubdomain_does_not_invoke_cert_provisioning(): void
    {
        $serverId = 'server-no-cert-call';

        $this->db->method('query')->willReturn([]);
        $this->certManager->expects($this->never())->method('provisionCertificate');

        $subdomain = $this->manager->allocateSubdomain($serverId);

        $this->assertIsString($subdomain);
        $this->assertSame(8, strlen($subdomain));
    }

    /**
     * refreshCertificate(), by contrast, MUST surface the
     * NotImplemented exception. It is the explicit "provision now"
     * path; silently swallowing it would re-introduce the silent-stub
     * lie at a different layer.
     */
    public function test_refreshCertificate_propagates_not_implemented_exception(): void
    {
        $serverId = 'server-refresh';

        $this->db->method('query')->willReturn([
            ['subdomain' => 'abc12345'],
        ]);

        $this->certManager->method('provisionCertificate')
            ->willThrowException(new \RuntimeException(
                'ACME certificate provisioning is not implemented in this build. '
                . 'Provision certs out-of-band — see docs/hub-admin/tls.md.',
            ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ACME certificate provisioning is not implemented');

        $this->manager->refreshCertificate($serverId);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->recursiveDelete($file);
            } else {
                // Suppression intentionally avoided: production code
                // removed `@unlink` for shell-safety, and reintroducing
                // the silenced form here would undermine that fix.
                // Ignore the boolean return — best-effort cleanup is
                // fine for a temp dir teardown.
                if (!unlink($file)) {
                    // noop: best-effort temp cleanup
                }
            }
        }
        if (!rmdir($dir)) {
            // noop: best-effort temp cleanup
        }
    }
}
