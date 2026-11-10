<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Support\ProcessLocalTopology;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * C6 — process-local topology fail-loud law (review hub__srphp cluster).
 *
 * All four WS workers keep their authoritative connection topology in PHP
 * statics ($connTunnels, $connServerIds/$liveConnections, $clients/$rooms/
 * $roomHosts/$roomPlayback, $connHubIds plus the FederationRelayController
 * and ClientMountController statics). Workerman forks `count` children that
 * each inherit a COPY: with count > 1 the processes irreconcilably disagree
 * about which tunnels/mounts/watch groups exist, tunnels get routed by a
 * process that never accepted the socket, and every "global" rate limiter
 * silently becomes a per-N-th share (RateLimitProfiles law: one worker
 * process == the global budget). The knob was silently unsound while every
 * call site happened to pass 1.
 *
 * These tests pin:
 *   1. the pure guard: count 1 is accepted, anything else throws with the
 *      worker name and offending count named in the message;
 *   2. the wiring, not theater: each of the four worker constructors
 *      actually runs the guard (count:2 refuses to build, count:1 builds).
 */
final class ProcessLocalTopologyTest extends TestCase
{
    public function testHelperAcceptsSingleProcess(): void
    {
        self::assertSame(1, ProcessLocalTopology::SINGLE_PROCESS, 'the topology law is exactly one process');

        // Reaching the end of this test without a RuntimeException IS the
        // acceptance assertion; the rejection legs below pin the throw.
        ProcessLocalTopology::assertSingleProcess(ProcessLocalTopology::SINGLE_PROCESS, 'some-worker');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideForeignCounts(): array
    {
        return ['zero' => [0], 'two' => [2], 'negative' => [-1]];
    }

    /**
     * @dataProvider provideForeignCounts
     */
    public function testHelperRejectsForeignCountNamingWorkerAndCount(int $count): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('some-worker');

        try {
            ProcessLocalTopology::assertSingleProcess($count, 'some-worker');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('count=' . $count, $exception->getMessage());

            throw $exception;
        }
    }

    public function testRelayWorkerConstructorRefusesForkedTopology(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phlix-hub-relay-ws (:8802)');

        new RelayWorker(self::container(), 8802, 2);
    }

    public function testClientRelayWorkerConstructorRefusesForkedTopology(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phlix-hub-client-relay-ws (:8803)');

        new ClientRelayWorker(self::container(), ClientRelayWorker::DEFAULT_PORT, 2);
    }

    public function testSyncPlayRelayWorkerConstructorRefusesForkedTopology(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phlix-hub-syncplay-relay-ws (:8804)');

        // Constructor shape: (port, count, container, ...).
        new SyncPlayRelayWorker(8804, 2, self::container());
    }

    public function testFederationWorkerConstructorRefusesForkedTopology(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phlix-hub-federation-ws (:8805)');

        new FederationWorker(self::container(), FederationWorker::DEFAULT_PORT, 2);
    }

    public function testEveryWorkerStillBuildsAtTheShippedSingleProcessCount(): void
    {
        self::assertInstanceOf(RelayWorker::class, new RelayWorker(self::container()));
        self::assertInstanceOf(ClientRelayWorker::class, new ClientRelayWorker(self::container()));
        self::assertInstanceOf(SyncPlayRelayWorker::class, new SyncPlayRelayWorker(8804, 1, self::container()));
        self::assertInstanceOf(FederationWorker::class, new FederationWorker(self::container()));
    }

    private static function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('stub');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
