<?php

/**
 * Phlix hub component: Relay tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\ClientRelayWorker;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;
use Workerman\Worker;

/**
 * HUB-S32: :8802/:8805 listen-host knobs (default preserved) and no raw
 * close(string) arms left in FederationWorker's connect path.
 */
final class WorkerBindHostAndCloseTest extends TestCase
{
    use \Phlix\Hub\Tests\Support\TempLoggerCapture;

    /** @var array<array-key, Worker> */
    private array $previousWorkerRegistry = [];

    protected function setUp(): void
    {
        parent::setUp();
        // start() logs the plaintext advisory; keep it off the real log path.
        $this->startLoggerCapture();
        /** @var array<array-key, Worker> $registry */
        $registry = (new ReflectionProperty(Worker::class, 'workers'))->getValue();
        $this->previousWorkerRegistry = $registry;
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Worker::class, 'workers'))->setValue(null, $this->previousWorkerRegistry);
        $this->stopLoggerCapture();
        parent::tearDown();
    }

    /**
     * HUB-S32 behaviour pin: a NON-default bind host given to each worker
     * really reaches the Workerman listen string (not just the constructor
     * parameter list), and the default stays the historical 0.0.0.0 literal.
     */
    public function testNonDefaultBindHostReachesTheListenString(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        // Built by NAME so a constructor reorder fails loudly instead of
        // silently binding the wrong thing.
        $cases = [];
        $cases['relay :8802'] = static fn (string $host, int $port): Worker =>
            (new RelayWorker($container, port: $port, bindHost: $host))->start();
        $cases['client :8803'] = static fn (string $host, int $port): Worker =>
            (new ClientRelayWorker($container, port: $port, bindHost: $host))->start();
        $cases['federation :8805'] = static fn (string $host, int $port): Worker =>
            (new FederationWorker($container, port: $port, bindHost: $host))->start();

        foreach ($cases as $label => $build) {
            $worker = $build('127.0.0.1', 39001);
            self::assertSame('websocket://127.0.0.1:39001', $worker->getSocketName(), $label);

            $v6 = $build('::1', 39002);
            self::assertSame('websocket://[::1]:39002', $v6->getSocketName(), $label . ' (bare IPv6 bracketed)');
        }
    }

    public function testDefaultBindHostIsHistoricalLiteral(): void
    {
        foreach ([FederationWorker::class, RelayWorker::class] as $cls) {
            $param = (new ReflectionClass($cls))->getConstructor()?->getParameters();
            $found = null;
            foreach ($param ?? [] as $p) {
                if ($p->getName() === 'bindHost') {
                    $found = $p->getDefaultValue();
                }
            }
            self::assertSame('0.0.0.0', $found, $cls);
        }
    }

    public function testFederationConnectPathHasNoRawStringClose(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../src/Relay/FederationWorker.php');
        self::assertSame(0, preg_match("/->close\\('(invalid_path|unknown_hub|internal_error)'\\)/", $src));
        self::assertStringContainsString('WebSocketCloseFrame::reject($connection, self::CLOSE_POLICY)', $src);
    }
}
