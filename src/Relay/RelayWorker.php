<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function count;
use function is_array;
use function is_string;
use function json_decode;
use function spl_object_id;

/**
 * WebSocket worker that handles server-to-hub relay tunnel connections.
 *
 * Servers connect via WSS to ws://hub:8802 and send a JSON HELLO message
 * as their first message. The worker then registers the connection with
 * TunnelManager and hands off message handling to the appropriate Tunnel.
 *
 * @package Phlix\Hub\Relay
 */
final class RelayWorker
{
    /**
     * Internal map of connection ID => Tunnel for active server connections.
     *
     * @var array<int, Tunnel>
     */
    private static array $connTunnels = [];

    /**
     * This worker's metrics collector, resolved in {@see onWorkerStart()} when
     * metrics is enabled. Null keeps every connection hook a no-op.
     *
     * @var MetricsCollector|null
     */
    private ?MetricsCollector $metrics = null;

    /**
     * @param ContainerInterface $container   PSR-11 container for resolving TunnelManager.
     * @param int                 $port        Internal WS port for relay connections.
     * @param int                 $count       Number of worker processes (default 1 for relay ordering).
     * @param string              $channelHost Host of the workerman/channel broker (cross-process proxy).
     * @param int                 $channelPort Port of the workerman/channel broker.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = 8802,
        private readonly int $count = 1,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
    ) {
    }

    /**
     * Start the relay WebSocket worker.
     *
     * Creates the Workerman Worker instance. The worker is not actually
     * started until Worker::runAll() is called (by Application::boot()).
     *
     * The `websocket://` scheme makes Workerman bind the
     * {@see \Workerman\Protocols\Websocket} application protocol to the
     * connection. That protocol performs the HTTP `Upgrade` handshake and
     * deframes inbound WebSocket frames, so {@see onMessage()} receives clean,
     * already-deframed payloads (text payloads for the JSON HELLO/HELLO_ACK
     * handshake, binary payloads for the relay frames). The protocol MUST NOT
     * be nulled — doing so disables both the handshake and deframing, leaving
     * onMessage with raw HTTP/WS bytes and the tunnel unable to establish. This
     * mirrors the working {@see ClientRelayWorker} (port 8803) pattern.
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-relay-ws';
        $worker->count = $this->count;

        // Fired during the WS upgrade handshake (before any frames arrive).
        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // Fired with each deframed WS message payload (HELLO text, then frames).
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        // Join the cross-process channel broker and wire the relay proxy so
        // HTTP workers can route browser requests through the tunnels this
        // worker owns.
        $worker->onWorkerStart = [$this, 'onWorkerStart'];

        return $worker;
    }

    /**
     * Worker-start hook: connect to the channel broker, attach the relay proxy
     * manager to the tunnel registry, and subscribe to proxy requests.
     *
     * This is where the cross-process HTTP-over-relay proxy is wired: the proxy
     * manager (which sends HTTP_REQUEST frames + reassembles HTTP_RESPONSE
     * frames) lives in this process alongside the tunnels, and incoming proxy
     * requests arrive via the channel broker.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function onWorkerStart(): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            ChannelClient::connect($this->channelHost, $this->channelPort);

            /** @var mixed $tunnelManager */
            $tunnelManager = $this->container->get(TunnelManager::class);
            /** @var mixed $proxyManager */
            $proxyManager = $this->container->get(RelayProxyManager::class);

            if ($tunnelManager instanceof TunnelManager && $proxyManager instanceof RelayProxyManager) {
                $tunnelManager->setProxyManager($proxyManager);
                // The vendor Channel\Client::on() callback is typed with the
                // legacy `callback` pseudo-type, which Psalm resolves to an
                // (undefined) Channel\callback class that neither an array nor a
                // Closure can satisfy. The runtime only does is_callable(), so a
                // first-class callable is correct here.
                /** @psalm-suppress InvalidArgument */
                ChannelClient::on(RelayProxyProtocol::REQUEST_EVENT, $proxyManager->onRequest(...));
                /** @psalm-suppress InvalidArgument */
                ChannelClient::on(RelayProxyProtocol::CANCEL_EVENT, $proxyManager->onCancel(...));
                $logger->info('Relay proxy: relay worker joined channel broker', [
                    'channel_host' => $this->channelHost,
                    'channel_port' => $this->channelPort,
                ]);

                // Startup reconciliation (Step B7): the relay worker owns every
                // server tunnel, so on (re)start its in-memory registry is the
                // source of truth. Close any `relay_sessions` row left open with
                // no live tunnel behind it — orphans from a prior crash/restart
                // that would otherwise keep `relay_active=1` stale and let the
                // proxy forward into a 504. At a fresh start the live set is
                // empty, so every open row is reconciled closed.
                $this->reconcileOrphanedSessions($tunnelManager, $logger);
            } else {
                $logger->error('Relay proxy: could not resolve proxy manager / tunnel manager');
            }
        } catch (Throwable $e) {
            $logger->error('Relay proxy: relay worker channel init failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // S4 metrics: resolve this worker's collector and arm its flush timer +
        // a live-connection touch timer. Must be per-worker here (NOT in the
        // master via HubServicesProvider::boot()) so the tunnel connection hooks
        // feed the same registry instance the flush drains. Guarded so a metrics
        // failure never breaks the relay worker.
        try {
            /** @var mixed $collector */
            $collector = $this->container->get(MetricsCollector::class);
            /** @var mixed $touchTunnelManager */
            $touchTunnelManager = $this->container->get(TunnelManager::class);
            if (
                $collector instanceof MetricsCollector
                && $collector->isEnabled()
                && $touchTunnelManager instanceof TunnelManager
            ) {
                $this->metrics = $collector;
                /** @var mixed $flushService */
                $flushService = $this->container->get(MetricsFlushService::class);
                if ($flushService instanceof MetricsFlushService) {
                    $flushInterval = $flushService->flushIntervalSeconds();
                    Timer::add($flushInterval, static function () use ($flushService, $logger): void {
                        // Best-effort: a transient DB error on a flush tick must
                        // never escape the timer and crash the relay worker.
                        try {
                            $flushService->flush(0, time());
                        } catch (Throwable $e) {
                            $logger->warning('Metrics: relay flush tick failed', ['error' => $e->getMessage()]);
                        }
                    });
                    // Push each live tunnel's current cumulative bytes into the
                    // registry between flushes so the live-connection panel shows
                    // real throughput for the whole tunnel lifetime (not a zero
                    // row until it closes). Touch at least twice per flush window.
                    $touchInterval = max(1, intdiv($flushInterval, 2));
                    Timer::add($touchInterval, static function () use ($touchTunnelManager, $collector): void {
                        foreach ($touchTunnelManager->allTunnels() as $tunnel) {
                            if (!$tunnel instanceof Tunnel) {
                                continue;
                            }
                            $ws = $tunnel->serverWs;
                            $collector->touchConnection($tunnel->tunnelId, $ws->bytesRead, $ws->bytesWritten);
                        }
                    });
                }
            }
        } catch (Throwable $e) {
            $logger->error('Metrics: relay worker timer init failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Arm the hub's periodic maintenance timers HERE, inside this running
        // worker's event loop, rather than in HubServicesProvider::boot() (the
        // master, pre-fork). Two reasons this is the correct home:
        //   1. cid>=0 — inside the event loop Workerman\Timer::add takes the
        //      Swoole path so each callback fires in a coroutine, keeping its DB
        //      queries behind PhlixMySQLConnection's per-connection mutex. Armed
        //      in boot() they ran on the pcntl signal scheduler (cid<0), bypassed
        //      the mutex, and collided with request transactions on the shared
        //      socket → 2014 / "already active transaction" heartbeat 500s.
        //   2. count=1 — the relay worker is a singleton (unlike the N HTTP
        //      workers) and already owns the TunnelManager the idle reaper and
        //      tunnel-heartbeat loop scan, so each reaper runs exactly once
        //      hub-wide.
        HubServicesProvider::startMaintenanceTimers($this->container);
    }

    /**
     * Close orphaned `relay_sessions` against the live tunnel registry.
     *
     * Resolves {@see RelaySessionManager} from the container and asks it to
     * close every open session whose `server_id` is not currently backed by a
     * live tunnel in `$tunnelManager`. Best-effort: any failure is logged and
     * swallowed so a reconciliation hiccup never blocks worker start. Separated
     * from {@see onWorkerStart()} so it is unit-testable without the channel
     * broker.
     *
     * @param TunnelManager  $tunnelManager The live tunnel registry.
     * @param StructuredLogger $logger       Relay logger.
     *
     * @return void
     *
     * @since 0.11.0
     */
    public function reconcileOrphanedSessions(TunnelManager $tunnelManager, StructuredLogger $logger): void
    {
        try {
            /** @var mixed $sessionManager */
            $sessionManager = $this->container->get(RelaySessionManager::class);
            if (!$sessionManager instanceof RelaySessionManager) {
                $logger->error('Relay: could not resolve session manager for startup reconciliation');
                return;
            }

            $liveServerIds = [];
            foreach ($tunnelManager->allTunnels() as $serverId => $_tunnel) {
                $liveServerIds[] = $serverId;
            }

            $sessionManager->closeOrphanedSessions($liveServerIds, 'reconciled_on_start');
        } catch (Throwable $e) {
            $logger->error('Relay: startup session reconciliation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the WebSocket upgrade for an inbound server tunnel connection.
     *
     * Fired by the Workerman WebSocket protocol once the HTTP `Upgrade`
     * handshake completes. The server_id is not yet known here — it is carried
     * in the first deframed message (the JSON HELLO) handled by {@see onMessage()}.
     *
     * @param TcpConnection    $connection New server connection being upgraded.
     * @param WorkermanRequest $request    The WS upgrade HTTP request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        // Nothing to do on upgrade — the first deframed message carries the
        // JSON HELLO (and the server_id) which onMessage handles.
    }

    /**
     * Handle a deframed message from a server connection.
     *
     * The Websocket protocol has already performed the upgrade handshake and
     * deframing, so $data is a single complete WebSocket message payload. The
     * first such payload is the JSON HELLO (text frame) carrying the server_id;
     * subsequent payloads are binary relay frames delegated to the Tunnel
     * (which buffers + decodes them via its own FrameDecoder).
     *
     * @param TcpConnection $connection Server connection.
     * @param string        $data       Deframed WebSocket message payload.
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        // First message — expect JSON HELLO
        if (!isset(self::$connTunnels[$connId])) {
            $this->handleHello($connection, $data);
            return;
        }

        // Subsequent messages — delegate to tunnel
        self::$connTunnels[$connId]->onServerMessage($data);
    }

    /**
     * Handle the HELLO handshake from a server.
     *
     * @param TcpConnection $connection Server connection.
     * @param string         $data       JSON HELLO payload.
     *
     * @return void
     */
    private function handleHello(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        try {
            /** @var array<string, mixed>|null $hello */
            $hello = json_decode($data, true, 2, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $connection->close('invalid_hello');
            return;
        }

        if (!is_array($hello)) {
            $connection->close('invalid_hello');
            return;
        }

        $serverId = $hello['server_id'] ?? null;
        if (!is_string($serverId) || $serverId === '') {
            $connection->close('missing_server_id');
            return;
        }

        // Resolve TunnelManager from container and create tunnel
        try {
            $tunnelManager = $this->container->get(TunnelManagerInterface::class);
            if (!$tunnelManager instanceof TunnelManagerInterface) {
                $connection->close('internal_error');
                return;
            }

            $tunnel = $tunnelManager->acceptServer($serverId, $connection);
            self::$connTunnels[$connId] = $tunnel;

            // Let the tunnel process the HELLO (validates + transitions state)
            $tunnel->onServerMessage($data);

            // S4 metrics: register the tunnel as a live connection. Its byte
            // counts are pushed by the periodic touch timer (see onWorkerStart)
            // and a final touch on close.
            $this->metrics?->openConnection(
                $tunnel->tunnelId,
                'websocket',
                null,
                $connection->getRemoteIp(),
                $tunnel->relaySessionId,
                null,
            );
        } catch (Throwable $e) {
            $connection->close('internal_error');
        }
    }

    /**
     * Handle server connection close.
     *
     * @param TcpConnection $connection Server connection.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);

        if (isset(self::$connTunnels[$connId])) {
            $tunnel = self::$connTunnels[$connId];

            // S4 metrics: record the FINAL cumulative byte counts. Deliberately
            // NOT closeConnection() — that would drop the registry row before the
            // next flush persists it; the flush service TTL-prunes the now-idle
            // row afterwards (mirrors the server's WS close hook).
            $this->metrics?->touchConnection(
                $tunnel->tunnelId,
                $connection->bytesRead,
                $connection->bytesWritten,
            );

            $tunnel->onServerClose();
            unset(self::$connTunnels[$connId]);
        }
    }

    /**
     * Get the count of active server connections.
     *
     * @return int Active connection count.
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$connTunnels);
    }

    /**
     * Clear the static connection→tunnel map.
     *
     * Intended for test isolation only — the static map is process-global, so
     * tests that assert {@see getActiveConnectionCount()} must reset it between
     * cases. Production never needs this (connections are removed on close).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connTunnels = [];
    }
}
