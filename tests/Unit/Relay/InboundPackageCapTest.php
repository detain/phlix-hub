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
 *
 * The scan recognizes TWO bind grammars, both of which construct a
 * `websocket://` listener: the direct literal `new Worker("websocket://…")`
 * (:8802/:8805, byte-frozen by their own posture suites) and the SH-064
 * `new Worker(WsTlsContext::listenString($bindHost, $port))` form (:8803/:8804,
 * which must build the scheme+host+port dynamically for the bind-host knob and
 * IPv6 bracketing). A future worker that dodges BOTH markers to escape the cap
 * pin is the exact thing the completeness scan refuses to allow silently — so
 * the two-marker union is strictly stronger than the old single literal, not a
 * relaxation: any new websocket listener must reuse one of these two
 * constructions (or extend this list, which is a disclosed census move).
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

    /**
     * Substrings that each mark "this file opens a websocket listener". A file
     * counts as a governed WS surface if it contains ANY one of them; the
     * completeness scan matches the SAME set so the two tests can never drift
     * on what a websocket worker IS.
     *
     * @var list<string>
     */
    private const WEBSOCKET_BIND_MARKERS = [
        'websocket://',
        'WsTlsContext::listenString(',
    ];

    /**
     * Constructor-call-site substrings for the completeness scan — the SAME
     * two bind grammars as above, but pinned at the `new Worker(` call so a
     * docblock or comment that merely mentions `websocket://` can never be
     * mistaken for a worker (which would under-count) nor a real listener
     * missed (which is the "forgotten fifth" the scan exists to catch).
     *
     * @var list<string>
     */
    private const WEBSOCKET_CONSTRUCTOR_MARKERS = [
        'new Worker("websocket://',
        'new Worker(WsTlsContext::listenString(',
    ];

    private static function bindsWebsocket(string $source): bool
    {
        foreach (self::WEBSOCKET_BIND_MARKERS as $marker) {
            if (str_contains($source, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function constructsWebsocketWorker(string $source): bool
    {
        foreach (self::WEBSOCKET_CONSTRUCTOR_MARKERS as $marker) {
            if (str_contains($source, $marker)) {
                return true;
            }
        }

        return false;
    }

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
            self::assertTrue(
                self::bindsWebsocket($source),
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
            if (is_string($contents) && self::constructsWebsocketWorker($contents)) {
                $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($found);
        $expected = self::WORKER_FILES;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'websocket worker inventory moved — every WS surface owes the InboundPackageCap pin',
        );
    }
}
