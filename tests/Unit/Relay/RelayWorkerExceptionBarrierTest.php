<?php

/**
 * Phlix hub component: Relay tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Workerman\Connection\TcpConnection;

/**
 * HUB-S31 / HUB-56: caller-side exception barrier on the :8802 and :8803
 * message dispatch, mirroring the S312 law attested on :8805. Both workers
 * run count=1, so a Throwable escaping the callback would stop the sole
 * process. Pins per venue: contained (no throw out of onMessage), exactly ONE
 * relay error line carrying direction/error/class, and the poisoned socket
 * closed with `handler_error`.
 */
final class RelayWorkerExceptionBarrierTest extends TestCase
{
    use LoggerFactoryIsolation;

    public function testRelayWorkerContainsThrowingTunnelDispatch(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $closed = [];
        $connection->method('close')->willReturnCallback(
            static function (mixed ...$args) use (&$closed): void {
                $closed[] = $args[0] ?? null;
            }
        );
        $connId = spl_object_id($connection);

        $tunnel = new class {
            public function onServerMessage(string $data): void
            {
                throw new RuntimeException('tunnel-probe-down');
            }
        };

        $prop = new ReflectionProperty(RelayWorker::class, 'connTunnels');
        $before = $prop->getValue();
        $prop->setValue(null, [$connId => $tunnel]);

        try {
            $logger = $this->injectRelayLogger();
            $logger->expects(self::once())
                ->method('error')
                ->with(
                    self::stringContains('kept alive'),
                    self::callback(
                        static fn (array $ctx): bool => ($ctx['direction'] ?? null) === 'message'
                            && ($ctx['error'] ?? null) === 'tunnel-probe-down'
                            && ($ctx['class'] ?? null) === RuntimeException::class
                    ),
                );

            (new RelayWorker($this->container([])))->onMessage($connection, 'frame');
        } finally {
            $prop->setValue(null, $before);
        }

        self::assertSame(['handler_error'], $closed);
    }

    public function testClientRelayWorkerContainsThrowingControllerDispatch(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $closed = [];
        $connection->method('close')->willReturnCallback(
            static function (mixed ...$args) use (&$closed): void {
                $closed[] = $args[0] ?? null;
            }
        );
        $connId = spl_object_id($connection);

        $client = new class {
            public function onMessage(mixed $data, mixed $decoder): void
            {
                throw new RuntimeException('client-probe-down');
            }
        };

        $clients = new ReflectionProperty(ClientMountController::class, 'connClients');
        $decoders = new ReflectionProperty(ClientMountController::class, 'connDecoders');
        $clientsBefore = $clients->getValue();
        $decodersBefore = $decoders->getValue();
        $clients->setValue(null, [$connId => $client]);
        $decoders->setValue(null, [$connId => new stdClass()]);

        $controller = (new ReflectionClass(ClientMountController::class))->newInstanceWithoutConstructor();

        try {
            $logger = $this->injectRelayLogger();
            $logger->expects(self::once())
                ->method('error')
                ->with(
                    self::stringContains('kept alive'),
                    self::callback(
                        static fn (array $ctx): bool => ($ctx['direction'] ?? null) === 'message'
                            && ($ctx['error'] ?? null) === 'client-probe-down'
                            && ($ctx['class'] ?? null) === RuntimeException::class
                    ),
                );

            (new ClientRelayWorker($this->container([ClientMountController::class => $controller])))
                ->onMessage($connection, 'frame');
        } finally {
            $clients->setValue(null, $clientsBefore);
            $decoders->setValue(null, $decodersBefore);
        }

        self::assertSame(['handler_error'], $closed);
    }

    /** @param array<string, object> $entries */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /** @param array<string, object> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function get(string $id): object
            {
                return $this->entries[$id] ?? throw new RuntimeException("unresolved: $id");
            }

            public function has(string $id): bool
            {
                return isset($this->entries[$id]);
            }
        };
    }

    /** Swap the memoised RELAY channel logger for a strict mock. */
    private function injectRelayLogger(): StructuredLogger&MockObject
    {
        $logger = $this->createMock(StructuredLogger::class);
        (new ReflectionProperty(LoggerFactory::class, 'loggers'))->setValue(null, [LogChannels::RELAY => $logger]);

        return $logger;
    }
}
