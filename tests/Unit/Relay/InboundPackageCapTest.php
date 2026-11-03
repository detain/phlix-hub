<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Phlix\Hub\Relay\InboundPackageCap;

/**
 * Review Minor 57 — the WebSocket surfaces' inbound package cap is a DECLARED
 * hub number, not an inherited vendor one.
 *
 * The four `websocket://` workers (:8802 relay, :8803 client-relay, :8804
 * SyncPlay, :8805 federation) used to run on whatever
 * `TcpConnection::$defaultMaxPackageSize` the vendored Workerman happens to
 * ship, while every hub-side queue derivation (Tunnel's 256-frame caps, the
 * SyncPlay message budgets) silently assumed that number. The cure mirrors
 * the HTTP surface's M-HUB-30 pin: each worker's TCP-accept `onConnect`
 * (unused before this — the websocket protocol speaks through
 * `onWebSocketConnect`) lands the constant onto the connection.
 *
 * Pinned here: the value equals the current vendor default (behavior-
 * preservation at the pin's landing), the closure lands it on a real
 * connection, and — source-scan, the estate idiom for worker wiring — all
 * four websocket worker files assign the cap AND no fifth websocket worker
 * exists that forgot to.
 */
final class InboundPackageCapTest extends TestCase
{
    /** @var list<string> the four governed worker files */
    private const WORKER_FILES = [
        'src/Relay/RelayWorker.php',
        'src/Relay/ClientRelayWorker.php',
        'src/Relay/FederationWorker.php',
        'src/SyncPlay/SyncPlayRelayWorker.php',
    ];

    public function testTheDeclaredCapEqualsTheCurrentVendorDefault(): void
    {
        // Behavior-preserving by construction: pinning today's inherited value
        // declares it as OURS. A vendor default change must therefore be a
        // DISCLOSED decision here, not a silent move of every derivation.
        self::assertSame(
            10485760,
            InboundPackageCap::MAX_INBOUND_PACKAGE_BYTES,
            '10 MiB — the value the WS surfaces inherited pre-pin',
        );
        self::assertSame(
            InboundPackageCap::MAX_INBOUND_PACKAGE_BYTES,
            TcpConnection::$defaultMaxPackageSize,
            'still equals the vendor default (re-verify deliberately when this reddens)',
        );
    }

    public function testTheHandlerLandsTheCapOnARealConnection(): void
    {
        /** @var array{0: resource, 1: resource} $pair */
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $connection = new TcpConnection(new Select(), $pair[0]);

        try {
            (InboundPackageCap::onConnect())($connection);

            self::assertSame(
                InboundPackageCap::MAX_INBOUND_PACKAGE_BYTES,
                $connection->maxPackageSize,
                'the assignment must hit the vendor property the input layer consults',
            );
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testEveryWebsocketWorkerAssignsTheCap(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::WORKER_FILES as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            self::assertIsString($source, $relative . ' must exist');
            self::assertStringContainsString(
                'websocket://',
                $source,
                $relative . ' is expected to be a websocket worker',
            );
            self::assertStringContainsString(
                'InboundPackageCap::onConnect()',
                $source,
                'the WS inbound cap must be pinned in ' . $relative . ', not inherited',
            );
        }
    }

    public function testTheGovernedWorkerSetIsExactlyTheFourWsSurfaces(): void
    {
        // Completeness law: ANY new `websocket://` worker in src/ must join
        // WORKER_FILES above (and thus the pin) — this scan makes "forgot the
        // fifth worker" structurally impossible to land silently.
        $root = dirname(__DIR__, 3);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (is_string($contents) && str_contains($contents, 'new Worker("websocket://')) {
                $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($found);
        $expected = self::WORKER_FILES;
        sort($expected);

        self::assertSame($expected, $found, 'websocket worker inventory moved — every WS surface owes the InboundPackageCap pin');
    }
}
