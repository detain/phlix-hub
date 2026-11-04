<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Dns\StaticZoneManager;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class DnsAliasManagerTest extends TestCase
{
    private string $tmpDir;
    private Connection&MockObject $db;
    private StaticZoneManager $zoneManager;
    private TlsCertificateManager&MockObject $certManager;
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

    public function testGetFqdnReturnsFullDomain(): void
    {
        $subdomain = 'abc12345';

        $fqdn = $this->manager->getFqdn($subdomain);

        $this->assertSame('abc12345.phlix.media', $fqdn);
    }

    public function testAllocateSubdomainGenerates8CharSubdomain(): void
    {
        $serverId = 'server-123-uuid';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
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
        // operators install certs out-of-band (phlix-docs docs/hub-admin/tls.md).
        // The mock therefore expects no call at all.
        $this->certManager->expects($this->never())->method('provisionCertificate');

        $subdomain = $this->manager->allocateSubdomain($serverId);

        $this->assertSame(8, strlen($subdomain));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $subdomain);
    }

    public function testGetSubdomainReturnsNullWhenNotAllocated(): void
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

    public function testResolveReturnsNullForUnknownSubdomain(): void
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
    public function testAllocateSubdomainDoesNotInvokeCertProvisioning(): void
    {
        $serverId = 'server-no-cert-call';

        $this->db->method('query')->willReturn([]);
        $this->certManager->expects($this->never())->method('provisionCertificate');

        $subdomain = $this->manager->allocateSubdomain($serverId);

        $this->assertSame(8, strlen($subdomain));
    }

    /**
     * refreshCertificate(), by contrast, MUST surface the
     * NotImplemented exception. It is the explicit "provision now"
     * path; silently swallowing it would re-introduce the silent-stub
     * lie at a different layer.
     */
    public function testRefreshCertificatePropagatesNotImplementedException(): void
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

    // --- review hub Minor 43: DB-then-DNS drift on provider failure -----------

    public function testDnsRecordFailureRevertsTheSubdomainRowAndRethrows(): void
    {
        $serverId = 'server-dns-fail';

        /** @var list<string> $sqlSequence */
        $sqlSequence = [];
        $this->db->method('query')
            ->willReturnCallback(function (string $sql) use (&$sqlSequence): array {
                $sqlSequence[] = $sql;
                return [];
            });

        $dns = $this->createMock(StaticZoneManager::class);
        $dns->method('addRecord')->willThrowException(new \RuntimeException('provider down'));
        $dns->expects($this->never())->method('updateSoa');

        $manager = new DnsAliasManager($this->db, $dns, $this->certManager, $this->logger);

        try {
            $manager->allocateSubdomain($serverId);
            $this->fail('the provider failure must surface to the caller unchanged');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        // Exact statement sequence: existence probe, allocation UPDATE, then
        // the REVERT. Without the cure the third statement never happens and
        // the row permanently claims an alias DNS never got (the short-circuit
        // on the next call would return this phantom label forever).
        $this->assertSame([
            'SELECT subdomain FROM servers WHERE id = :id AND subdomain IS NOT NULL LIMIT 1',
            'SELECT id FROM servers WHERE subdomain = :subdomain LIMIT 1',
            'UPDATE servers SET subdomain = :subdomain WHERE id = :id',
            'UPDATE servers SET subdomain = NULL WHERE id = :id',
        ], $sqlSequence);
    }

    public function testUpdateSoaFailureAlsoRevertsTheSubdomainRow(): void
    {
        // The disclosed partial window: the A-record write can succeed while
        // the SOA refresh throws. The revert still runs — the orphan zone line
        // is inert and the deterministic label re-adds idempotently on retry.
        $serverId = 'server-soa-fail';

        /** @var list<string> $sqlSequence */
        $sqlSequence = [];
        $this->db->method('query')
            ->willReturnCallback(function (string $sql) use (&$sqlSequence): array {
                $sqlSequence[] = $sql;
                return [];
            });

        $dns = $this->createMock(StaticZoneManager::class);
        $dns->expects($this->once())->method('addRecord');
        $dns->method('updateSoa')->willThrowException(new \RuntimeException('soa write failed'));

        $manager = new DnsAliasManager($this->db, $dns, $this->certManager, $this->logger);

        try {
            $manager->allocateSubdomain($serverId);
            $this->fail('the provider failure must surface to the caller unchanged');
        } catch (\RuntimeException $e) {
            $this->assertSame('soa write failed', $e->getMessage());
        }

        $this->assertSame([
            'SELECT subdomain FROM servers WHERE id = :id AND subdomain IS NOT NULL LIMIT 1',
            'SELECT id FROM servers WHERE subdomain = :subdomain LIMIT 1',
            'UPDATE servers SET subdomain = :subdomain WHERE id = :id',
            'UPDATE servers SET subdomain = NULL WHERE id = :id',
        ], $sqlSequence);
    }

    public function testSuccessfulAllocationNeverIssuesTheRevert(): void
    {
        // Anti-mutation for the cure itself: the happy path (real file-backed
        // zone manager from setUp) must NOT touch the NULL revert statement.
        $serverId = 'server-happy';

        /** @var list<string> $sqlSequence */
        $sqlSequence = [];
        $this->db->method('query')
            ->willReturnCallback(function (string $sql) use (&$sqlSequence): array {
                $sqlSequence[] = $sql;
                return [];
            });

        $subdomain = $this->manager->allocateSubdomain($serverId);

        $this->assertSame(8, strlen($subdomain));
        $this->assertSame([
            'SELECT subdomain FROM servers WHERE id = :id AND subdomain IS NOT NULL LIMIT 1',
            'SELECT id FROM servers WHERE subdomain = :subdomain LIMIT 1',
            'UPDATE servers SET subdomain = :subdomain WHERE id = :id',
        ], $sqlSequence);
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
