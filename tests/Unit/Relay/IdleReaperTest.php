<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Generator;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelInterface;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

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
}
