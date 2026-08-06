<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Generator;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelInterface;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

class IdleReaperTest extends TestCase
{
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(StructuredLogger::class);
    }

    public function test_tick_reaps_only_stale_tunnels(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        // Create two mock tunnels - one stale, one not
        $staleTunnel = $this->createMock(TunnelInterface::class);
        $staleTunnel->method('getTunnelId')->willReturn('tunnel-stale');
        $staleTunnel->method('getServerId')->willReturn('server-stale');
        $staleTunnel->method('getLastFrameAt')->willReturn(time() - 100);

        $activeTunnel = $this->createMock(TunnelInterface::class);
        $activeTunnel->method('getTunnelId')->willReturn('tunnel-active');
        $activeTunnel->method('getServerId')->willReturn('server-active');
        $activeTunnel->method('getLastFrameAt')->willReturn(time() - 30);

        // allTunnels yields [serverId => Tunnel] for ACTIVE tunnels only
        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([
                'server-stale' => $staleTunnel,
                'server-active' => $activeTunnel,
            ]));

        // closeTunnel should only be called for the stale tunnel
        $staleTunnel->expects($this->once())->method('isStale')->with(90)->willReturn(true);
        $activeTunnel->expects($this->once())->method('isStale')->with(90)->willReturn(false);

        $tunnelManager
            ->expects($this->once())
            ->method('closeTunnel')
            ->with('server-stale', 'timeout');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(1, $reapedCount);
    }

    public function test_tick_reaps_nothing_when_all_tunnels_active(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $activeTunnel = $this->createMock(TunnelInterface::class);
        $activeTunnel->method('getTunnelId')->willReturn('tunnel-active');
        $activeTunnel->method('getServerId')->willReturn('server-active');
        $activeTunnel->method('getLastFrameAt')->willReturn(time() - 30);

        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([
                'server-active' => $activeTunnel,
            ]));

        $activeTunnel->expects($this->once())->method('isStale')->with(90)->willReturn(false);

        $tunnelManager
            ->expects($this->never())
            ->method('closeTunnel');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(0, $reapedCount);
    }

    public function test_tick_reaps_multiple_stale_tunnels(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $staleTunnel1 = $this->createMock(TunnelInterface::class);
        $staleTunnel1->method('getTunnelId')->willReturn('tunnel-stale-1');
        $staleTunnel1->method('getServerId')->willReturn('server-stale-1');
        $staleTunnel1->method('getLastFrameAt')->willReturn(time() - 150);

        $staleTunnel2 = $this->createMock(TunnelInterface::class);
        $staleTunnel2->method('getTunnelId')->willReturn('tunnel-stale-2');
        $staleTunnel2->method('getServerId')->willReturn('server-stale-2');
        $staleTunnel2->method('getLastFrameAt')->willReturn(time() - 200);

        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([
                'server-stale-1' => $staleTunnel1,
                'server-stale-2' => $staleTunnel2,
            ]));

        $staleTunnel1->expects($this->once())->method('isStale')->with(90)->willReturn(true);
        $staleTunnel2->expects($this->once())->method('isStale')->with(90)->willReturn(true);

        $tunnelManager
            ->expects($this->exactly(2))
            ->method('closeTunnel');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(2, $reapedCount);
    }

    public function test_tick_handles_empty_tunnel_list(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([]));

        $tunnelManager
            ->expects($this->never())
            ->method('closeTunnel');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(0, $reapedCount);
    }

    public function test_get_interval_seconds_returns_configured_value(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            120,
            45,
        );

        $this->assertSame(120, $reaper->getIntervalSeconds());
        $this->assertSame(45, $reaper->getStaleThresholdSeconds());
    }

    public function test_tick_uses_configured_stale_threshold(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $tunnel = $this->createMock(TunnelInterface::class);
        $tunnel->method('getTunnelId')->willReturn('tunnel-1');
        $tunnel->method('getServerId')->willReturn('server-1');
        $tunnel->method('getLastFrameAt')->willReturn(time() - 60);

        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([
                'server-1' => $tunnel,
            ]));

        // With 90s threshold, this tunnel is NOT stale
        $tunnel->expects($this->once())->method('isStale')->with(90)->willReturn(false);

        $tunnelManager
            ->expects($this->never())
            ->method('closeTunnel');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(0, $reapedCount);
    }

    public function test_tick_uses_custom_stale_threshold(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $tunnel = $this->createMock(TunnelInterface::class);
        $tunnel->method('getTunnelId')->willReturn('tunnel-1');
        $tunnel->method('getServerId')->willReturn('server-1');
        $tunnel->method('getLastFrameAt')->willReturn(time() - 60);

        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator([
                'server-1' => $tunnel,
            ]));

        // With 50s threshold, this tunnel IS stale
        $tunnel->expects($this->once())->method('isStale')->with(50)->willReturn(true);

        $tunnelManager
            ->expects($this->once())
            ->method('closeTunnel')
            ->with('server-1', 'timeout');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            50, // Custom threshold lower than tunnel's idle time
        );

        $reapedCount = $reaper->tick();

        $this->assertSame(1, $reapedCount);
    }

    public function test_reap_db_maintenance_reaps_stale_db_sessions_when_session_manager_wired(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager
            ->expects($this->once())
            ->method('reapStaleSessions')
            ->willReturn(3);

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            $sessionManager,
        );

        $reaper->reapDbMaintenance();
    }

    public function test_reap_db_maintenance_is_noop_for_db_sessions_when_session_manager_null(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        // No session manager passed; reapDbMaintenance() must complete cleanly.
        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reaper->reapDbMaintenance();
        $this->addToAssertionCount(1);
    }

    public function test_reap_db_maintenance_prunes_expired_tokens_when_client_relay_token_service_wired(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        // ClientRelayTokenService is final, so we use a real instance with a
        // mock DB to verify pruneExpiredTokens() is called with correct SQL.
        $db = $this->createMock(Connection::class);
        $capturedSql = '';
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql): int {
                $capturedSql = $sql;
                return 5; // 5 rows pruned
            },
        );

        $clientRelayTokenService = new ClientRelayTokenService($db);

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            null, // sessionManager
            null, // heartbeatHandler
            $clientRelayTokenService,
        );

        $reaper->reapDbMaintenance();

        $this->assertStringContainsString('DELETE FROM client_relay_tokens', $capturedSql);
        $this->assertStringContainsString('expires_at < NOW() - INTERVAL 1 DAY', $capturedSql);
        $this->assertStringContainsString('revoked_at IS NOT NULL', $capturedSql);
    }

    public function test_reap_db_maintenance_is_noop_for_client_relay_tokens_when_service_null(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        // No token service passed; reapDbMaintenance() must complete cleanly.
        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reaper->reapDbMaintenance();
        $this->addToAssertionCount(1);
    }

    /**
     * HB-4.7 (H-A1) wiring guard: the maintenance-worker DB-maintenance sweep
     * MUST invoke Ed25519KeyManager::purgeExpiredPreviousKey() on the injected
     * key manager, so the rotated-out previous-key sidecar is reclaimed on the
     * periodic timer instead of lingering until the next rotate(). Ed25519KeyManager
     * is final (unmockable), so this uses a real instance over a temp key path with
     * an injectable clock and asserts the expired sidecar file is unlinked by the
     * reap — removing the wiring leaves the file and fails the assertion.
     */
    public function test_reap_db_maintenance_purges_expired_previous_key_when_key_manager_wired(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $keyPath = sys_get_temp_dir() . '/phlix_hub_idlereaper_key_' . bin2hex(random_bytes(6)) . '.pem';
        $now = 1_000_000;
        $keyManager = new Ed25519KeyManager($keyPath, static function () use (&$now): int {
            return $now;
        });
        // Rotate to persist a previous-key sidecar, then advance past the 24h
        // overlap window so it is expired and eligible for purge.
        $keyManager->getOrCreateKeyPair();
        $keyManager->rotate();
        $sidecar = $keyPath . '.previous.json';
        self::assertFileExists($sidecar, 'rotate() must create the previous-key sidecar');
        $now += Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            null, // sessionManager
            null, // heartbeatHandler
            null, // clientRelayTokenService
            $keyManager,
        );

        $reaper->reapDbMaintenance();

        self::assertFileDoesNotExist(
            $sidecar,
            'reapDbMaintenance() must invoke purgeExpiredPreviousKey() on the injected key manager',
        );

        @unlink($keyPath);
        @unlink($sidecar);
    }

    public function test_reap_db_maintenance_is_noop_for_key_manager_when_null(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        // No key manager passed; reapDbMaintenance() must complete cleanly.
        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
        );

        $reaper->reapDbMaintenance();
        $this->addToAssertionCount(1);
    }

    /**
     * HB-2.6 data-locality regression guard (relay-worker side).
     *
     * tick() is the IN-MEMORY path armed on the relay worker: it must scan the
     * live tunnel registry and flush the in-memory accumulators, but it must NOT
     * run the DB-only pruners (those belong to reapDbMaintenance() on the
     * maintenance worker). Before the HB-2.6 fix, tick() called reapStaleSessions
     * + pruneAllServerHeartbeats + pruneExpiredTokens itself — this test FAILS
     * against that wiring (the never() expectations trip).
     */
    public function test_tick_flushes_accumulators_and_scans_tunnels_but_runs_no_db_pruners(): void
    {
        $staleTunnel = $this->createMock(TunnelInterface::class);
        $staleTunnel->method('getTunnelId')->willReturn('tunnel-stale');
        $staleTunnel->method('getServerId')->willReturn('server-stale');
        $staleTunnel->method('getLastFrameAt')->willReturn(time() - 200);
        $staleTunnel->expects($this->once())->method('isStale')->with(90)->willReturn(true);

        // Populated registry — as it is on the relay worker (NOT the maintenance
        // fork, whose TunnelManager is empty).
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager
            ->method('allTunnels')
            ->willReturn($this->createTunnelGenerator(['server-stale' => $staleTunnel]));
        $tunnelManager
            ->expects($this->once())
            ->method('closeTunnel')
            ->with('server-stale', 'timeout');

        // In-memory accumulators must be flushed on the tick; DB pruners must NOT.
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->expects($this->once())->method('flushAll');
        $sessionManager->expects($this->never())->method('reapStaleSessions');

        $heartbeatHandler = $this->createMock(HeartbeatHandler::class);
        $heartbeatHandler->expects($this->never())->method('pruneAllServerHeartbeats');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            $sessionManager,
            $heartbeatHandler,
        );

        $this->assertSame(1, $reaper->tick());
    }

    /**
     * HB-2.6 data-locality regression guard (maintenance-worker side).
     *
     * reapDbMaintenance() is the DB-ONLY path armed on the maintenance worker,
     * whose tunnel registry + accumulators are EMPTY. It must run the DB pruners
     * but must NOT touch the live tunnel registry (allTunnels/closeTunnel) nor
     * flush the in-memory accumulators (flushAll) — those are relay-worker work.
     */
    public function test_reap_db_maintenance_runs_db_pruners_but_never_touches_tunnel_registry_or_flush(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->expects($this->never())->method('allTunnels');
        $tunnelManager->expects($this->never())->method('closeTunnel');

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->expects($this->once())->method('reapStaleSessions')->willReturn(0);
        $sessionManager->expects($this->never())->method('flushAll');

        $heartbeatHandler = $this->createMock(HeartbeatHandler::class);
        $heartbeatHandler
            ->expects($this->once())
            ->method('pruneAllServerHeartbeats')
            ->with(100);

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            $sessionManager,
            $heartbeatHandler,
        );

        $reaper->reapDbMaintenance();
    }

    /**
     * Helper to create a Generator as returned by TunnelManager::allTunnels().
     *
     * @param array<string, TunnelInterface> $tunnels
     *
     * @return Generator<string, TunnelInterface>
     */
    private function createTunnelGenerator(array $tunnels): Generator
    {
        foreach ($tunnels as $serverId => $tunnel) {
            yield $serverId => $tunnel;
        }
    }

    /**
     * S62: the MCP personal-access-token pruner runs on the DB-maintenance
     * sweep, alongside the HB-4.2 client-relay-token pruner it mirrors.
     *
     * {@see McpTokenService} is `final`, so — exactly as the sibling
     * `ClientRelayTokenService` test above does — a REAL instance is driven
     * against a mock {@see Connection} and the SQL it emits is captured. That
     * makes the assertion about the statement actually issued rather than about
     * a mock having been poked.
     *
     * Wiring this pruner into the existing 60-second maintenance timer rather
     * than giving it a `Timer::add(86400, …)` of its own is deliberate: a bare
     * daily timer never fires on a box that restarts more often than once a day.
     */
    public function test_reap_db_maintenance_prunes_expired_mcp_tokens_when_service_wired(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);

        $db = $this->createMock(Connection::class);
        /** @var list<string> $statements */
        $statements = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$statements): int {
                $statements[] = $sql;
                return 7;
            },
        );

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            null, // sessionManager
            null, // heartbeatHandler
            null, // clientRelayTokenService
            null, // keyManager
            new McpTokenService($db),
        );

        $reaper->reapDbMaintenance();

        $this->assertCount(
            1,
            $statements,
            'reapDbMaintenance() with only the MCP token service wired must issue exactly one statement. '
            . 'Zero means the pruner is not reached at all.',
        );
        $this->assertStringContainsString('DELETE FROM mcp_tokens', $statements[0]);
        $this->assertStringContainsString('expires_at < NOW() - INTERVAL 30 DAY', $statements[0]);
        $this->assertStringContainsString('revoked_at IS NOT NULL', $statements[0]);
    }

    /**
     * The MCP pruner is DB-only work and must NOT ride the relay worker's
     * in-memory {@see IdleReaper::tick()} — the same HB-2.6 data-locality rule
     * every other pruner here obeys.
     */
    public function test_tick_never_prunes_mcp_tokens(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('allTunnels')->willReturn($this->createTunnelGenerator([]));

        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $reaper = new IdleReaper(
            $tunnelManager,
            $this->logger,
            60,
            90,
            null,
            null,
            null,
            null,
            new McpTokenService($db),
        );

        $this->assertSame(0, $reaper->tick());
    }

    /**
     * An unwired MCP token service must leave the sweep working, exactly as the
     * other optional collaborators do.
     */
    public function test_reap_db_maintenance_is_noop_for_mcp_tokens_when_service_null(): void
    {
        $reaper = new IdleReaper(
            $this->createMock(TunnelManagerInterface::class),
            $this->logger,
            60,
            90,
        );

        $reaper->reapDbMaintenance();
        $this->addToAssertionCount(1);
    }
}
