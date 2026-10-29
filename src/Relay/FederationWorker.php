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
use Phlix\Hub\Common\Http\TrustedProxyResolver;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationPushDispatcher;
use Phlix\Hub\Federation\FederationPushProtocol;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function count;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function trim;

/**
 * WebSocket worker that handles hub-to-hub federation connections.
 *
 * Leaf hubs connect via WSS to `ws://master-hub:8805/relay/federation/{hub_id}`.
 * This worker is the master-side counterpart that accepts inbound WS
 * connections from federated leaf hubs.
 *
 * The port above is {@see self::DEFAULT_PORT}. This docblock said `8804` — the
 * SyncPlay relay's port — from the file's introduction until S66, which is the
 * kind of mistake nothing catches while the constant is the only thing anyone
 * runs. See `docs/websockets.md` for the full surface catalog.
 *
 * Connection lifecycle:
 *   1. WS upgrade — parse `hub_id` from the request path.
 *   2. Rate-limit the inbound connect per TRUSTED client IP
 *      ({@see RateLimitProfiles::FEDERATION_CONNECT}) — BEFORE any
 *      `federation_peers` DB read — and refuse over budget with a WS close
 *      frame 1013 ({@see self::CLOSE_TRY_AGAIN_LATER}).
 *   3. Validate the hub_id is a known peer in federation_peers.
 *      On failure, close with reason "Unknown hub".
 *   4. On success, delegate to {@see FederationRelayController::onConnect()}.
 *   5. Subsequent text/binary frames are frame-budgeted per REMOTE peer hub
 *      id ({@see RateLimitProfiles::FEDERATION_FRAME}, hit before dispatch)
 *      and then dispatched to {@see FederationRelayController::onMessage()};
 *      close events go to {@see FederationRelayController::onClose()}.
 *   6. The frame limiter instance is also installed into the
 *      {@see FederationPeerManager} at {@see self::onWorkerStart()} so the
 *      leaf-side outbound link in THIS process shares it (both roles, one
 *      budget per remote peer — keys never collide across roles).
 *
 * @package Phlix\Hub\Relay
 */
final class FederationWorker
{
    /**
     * Default port for federation WS connections (master side).
     */
    public const DEFAULT_PORT = 8805;

    /**
     * WS close code delivered on rate-limit rejection: RFC 6455's application
     * code for "try again later" (1013), matching the :8802 {@see RelayWorker}
     * and :8803 {@see ClientRelayWorker} connect-refusal posture (review
     * hub__exploiter M3). A leaf hub treats a 1013 link end exactly like any
     * other disconnect: its capped reconnect ladder re-dials after the
     * limiter window rolls over.
     */
    public const CLOSE_TRY_AGAIN_LATER = 1013;

    /**
     * Map of connection ID => hub ID, set at WS-connect time so later
     * message/close callbacks know which hub the connection belongs to.
     *
     * @var array<int, string>
     */
    private static array $connHubIds = [];

    /**
     * Inbound-connect limiter (master role), lazily resolved; null = the
     * container could not provide one → connect limiting fails OPEN
     * (unlimited), the deliberate trade-off of both sibling workers: a
     * broken limiter must never deny federation to legitimate peers.
     */
    private ?RateLimiterInterface $connectLimiter = null;

    /**
     * Frame-budget limiter (both roles — see onWorkerStart's install into
     * the peer manager), lazily resolved; null = fail OPEN (unlimited).
     */
    private ?RateLimiterInterface $frameLimiter = null;

    /**
     * Lazy proxy-trust resolver for the connect limiter's client-IP key
     * (the :8805 listener sits behind the managed install's HAProxy, same
     * as :8802/:8803 — see {@see ClientRelayWorker}'s resolve law).
     */
    private ?TrustedProxyResolver $trustedProxyResolver = null;

    /**
     * @param ContainerInterface $container   PSR-11 container for resolving services.
     * @param int                $port        Federation WS port (default 8805).
     * @param int                $count       Number of worker processes. MUST stay 1 in
     *        production: the push dispatcher subscribes the process-local connection
     *        map, and a second instance would hold sockets the first's replies (and
     *        the HTTP side's view of "connected") cannot see — same count=1 law the
     *        SyncPlay relay documents.
     * @param string             $channelHost `workerman/channel` broker host.
     * @param int                $channelPort `workerman/channel` broker port.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly int $count = 1,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
    ) {
    }

    /**
     * Start the federation WebSocket worker.
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-federation-ws';
        $worker->count = $this->count;

        $worker->onWorkerStart = [$this, 'onWorkerStart'];
        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];

        // Close DB connections in onWorkerStop (still in coroutine context) so
        // hooked PDO sockets aren't destroyed at RSHUTDOWN outside a coroutine.
        \Phlix\Hub\Common\Database\ConnectionPool::armWorkerStopCleanup($worker);

        return $worker;
    }

    /**
     * Join the cross-process channel broker and subscribe the federation
     * push dispatcher.
     *
     * THIS process owns the leaf sockets and their H-4 verified stamps, so
     * master-side push INTENTS published by the HTTP workers
     * ({@see FederationPushBridge}) can only become real writes here — via
     * {@see FederationPushDispatcher}, resolved from this process's container
     * so it holds the SAME {@see \Phlix\Hub\Federation\FederationConnectionManager}
     * the handshake callbacks stamp. Mirrors
     * {@see \Phlix\Hub\SyncPlay\SyncPlayRelayWorker::onWorkerStart()}.
     *
     * The join is wrapped so a broker failure logs and CONTINUES: the WS
     * surface itself — every leaf link, every handshake — must keep working
     * when no cross-process push can be received, and a throw here would take
     * down a resident worker at boot.
     *
     * Boot self-heal lives HERE, not in the master (2026-10-05 lane): the
     * leaf→master dial is bootstrapped through
     * {@see \Phlix\Hub\Federation\FederationPeerManager::bootstrapFromWorker()}
     * as the FIRST act of this callback, in its own guard. The master cannot
     * dial: {@see \Workerman\Worker::$globalEvent} exists only in running
     * children, so a pre-fork dial dies at connect() (ws peers, after parking
     * an untickable chain in the master's pcntl table that also POISONS every
     * child's reconnectScheduled latch) or died at the AsyncTcpConnection
     * constructor (wss peers pre-transport=ssl, where the throw escaped the
     * old master-side try/catch entirely — since the https→ws+ssl mapping in
     * FederationPeerManager::buildMasterDialPlan() that shape connects for
     * real, but the loop-shape argument for booting here stands unchanged).
     * This worker is count=1 — the same production law
     * as the push-dispatcher subscription above — so exactly one child boots
     * exactly one link: the single-dialer invariant holds by construction.
     * Deliberately BEFORE the broker join: an unresolvable dispatcher or a
     * down broker must never strand the outbound link bootstrap behind it.
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Boot self-heal: park the chain (disabled) or dial (enabled) in
        // THIS process's real event loop. Every failure mode here is
        // log-and-continue — a broken bootstrap may never take down the
        // resident WS surface this worker hosts.
        try {
            /** @var mixed $peerManager */
            $peerManager = $this->container->get(FederationPeerManager::class);
            if ($peerManager instanceof FederationPeerManager) {
                // M-HUB-14 (review hub__exploiter M3, leaf role): the leaf
                // link's inbound frame budget rides the SAME limiter instance
                // this process uses for master-side inbound frames — this is
                // the one container in the :8805 worker that owns both sides
                // of the federation surface, and every key is a REMOTE peer
                // hub id, so the two roles never share a bucket. Installing
                // BEFORE bootstrapFromWorker() means the very first dialled
                // link is already guarded. A null here (unresolvable limiter)
                // is the documented fail-open: the setter accepts it and the
                // leaf guard stays vacuous.
                $peerManager->setReceiveFrameLimiter($this->frameLimiter());
                $peerManager->bootstrapFromWorker();
            } else {
                $logger->error('Federation boot: could not resolve the peer manager');
            }
        } catch (Throwable $e) {
            $logger->error('Federation boot: leaf link bootstrap failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            ChannelClient::connect($this->channelHost, $this->channelPort);

            /** @var mixed $dispatcher */
            $dispatcher = $this->container->get(FederationPushDispatcher::class);
            if (!$dispatcher instanceof FederationPushDispatcher) {
                $logger->error('Federation master push: could not resolve the push dispatcher');

                return;
            }

            // The vendor Channel\Client::on() callback is typed with the legacy
            // `callback` pseudo-type, which Psalm resolves to an (undefined)
            // Channel\callback class that neither an array nor a Closure can
            // satisfy. The runtime only does is_callable(), so a first-class
            // callable is correct here.
            /** @psalm-suppress InvalidArgument */
            ChannelClient::on(FederationPushProtocol::COMMAND_EVENT, $dispatcher->onCommand(...));

            $logger->info('Federation master push: worker joined channel broker', [
                'channel_host' => $this->channelHost,
                'channel_port' => $this->channelPort,
            ]);
        } catch (Throwable $e) {
            $logger->error('Federation master push: channel init failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the WebSocket upgrade for an inbound federation connection.
     *
     * @param TcpConnection    $connection Leaf hub connection being upgraded.
     * @param WorkermanRequest $request    The WS upgrade HTTP request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);
        $connId = spl_object_id($connection);

        $hubId = self::parseHubId($request->path());
        if ($hubId === null) {
            $logger->warning('Federation: WS rejected, missing hub_id in path', [
                'path' => $request->path(),
            ]);
            $connection->close('invalid_path');
            return;
        }

        // M-HUB-14 (review hub__exploiter M3, leg 2): throttle inbound
        // federation connects per TRUSTED client IP BEFORE the DB read. The
        // unthrottled path paid a `federation_peers` SELECT per socket and,
        // once the path validated, the controller's HELLO ceremony paid three
        // session-row writes + a nonce mint + an Ed25519 signature per
        // attempt — an anonymous (or victim-spoofed) connect→HELLO→never-
        // auth→reconnect loop was pure write-amp. Keyed on the proxy-resolved
        // IP (raw TCP peer is HAProxy loopback on the managed install),
        // 10/60s: a legitimate leaf's capped backoff ladder re-dials at most
        // once per minute, so CGNAT neighbours pair freely while the loop is
        // starved. Over budget: one warning + close 1013, NO dispatch, NO DB
        // read. Limiter failure fails OPEN (unlimited) by sibling law.
        $limiter = $this->connectLimiter();
        if ($limiter !== null) {
            $this->trustedProxyResolver ??= new TrustedProxyResolver();
            $clientIp = $this->trustedProxyResolver->resolve(
                $connection->getRemoteIp(),
                self::upgradeHeader($request, 'x-forwarded-for'),
                self::upgradeHeader($request, 'x-real-ip'),
            );

            $state = $limiter->hit('federation_connect:' . $clientIp);
            if ($state->limited) {
                $logger->warning('Federation: inbound connect rate-limited', [
                    'remote_ip' => $clientIp,
                    'reset_at'  => $state->resetAt,
                ]);
                WebSocketCloseFrame::reject($connection, self::CLOSE_TRY_AGAIN_LATER);

                return;
            }
        }

        // Validate hubId is a known peer
        try {
            $hubRepo = $this->container->get(FederationHubRepository::class);
            if (!$hubRepo instanceof FederationHubRepository) {
                $connection->close('internal_error');
                return;
            }

            $peer = $hubRepo->getPeerById($hubId);
            if ($peer === null) {
                $logger->warning('Federation: WS rejected, unknown hub', [
                    'hub_id' => $hubId,
                ]);
                $connection->close('Unknown hub');
                return;
            }
        } catch (Throwable $e) {
            $logger->error('Federation: WS internal error during connect', [
                'hub_id' => $hubId,
                'error' => $e->getMessage(),
            ]);
            $connection->close('internal_error');
            return;
        }

        self::$connHubIds[$connId] = $hubId;

        try {
            $controller = $this->resolveController();
            if ($controller === null) {
                $connection->close('internal_error');
                unset(self::$connHubIds[$connId]);
                return;
            }

            $controller->onConnect($connection, $hubId);
        } catch (Throwable $e) {
            $logger->error('Federation: WS connect handler error', [
                'hub_id' => $hubId,
                'error' => $e->getMessage(),
            ]);
            unset(self::$connHubIds[$connId]);
            $connection->close('internal_error');
        }
    }

    /**
     * Handle an inbound frame from a federation peer.
     *
     * @param TcpConnection $connection Peer connection.
     * @param string        $data       Raw WebSocket frame payload.
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        // M-HUB-14 (review hub__exploiter M3, legs 1+3): per-PEER frame
        // budget at the master-side choke point, BEFORE dispatch. The
        // controller's H-4 gate refuses pre-verification DATA with a warning
        // + audit row per FRAME (leg 1: log/audit amp from any socket that
        // reached validation) and post-verification offers fan out to
        // unbounded inline INSERTs per `shares[]` element (leg 3) — one hit
        // per payload, keyed on the REMOTE peer hub id registered at
        // connect time, bounds both before they reach the handler. Frames on
        // connections absent from the map (connect refused/error'd) keep the
        // unchanged pass-through: the controller owns that lifecycle and
        // bounds them itself. 120/60s is heartbeat-dominated headroom
        // (4/min/link legit); over budget: one warning + close 1013, the
        // leaf's ladder re-dials. Fails OPEN (unlimited) when unresolvable.
        $hubId = self::$connHubIds[spl_object_id($connection)] ?? null;
        if ($hubId !== null) {
            $limiter = $this->frameLimiter();
            if ($limiter !== null) {
                $state = $limiter->hit('federation_frame:' . $hubId);
                if ($state->limited) {
                    LoggerFactory::get(LogChannels::RELAY)->warning(
                        'Federation: inbound frame budget exceeded for peer',
                        [
                            'hub_id'   => $hubId,
                            'reset_at' => $state->resetAt,
                        ],
                    );
                    WebSocketCloseFrame::reject($connection, self::CLOSE_TRY_AGAIN_LATER);

                    return;
                }
            }
        }

        $controller = $this->resolveController();
        if ($controller === null) {
            $connection->close();
            return;
        }

        $controller->onMessage($connection, $data);
    }

    /**
     * Handle a federation connection close.
     *
     * @param TcpConnection $connection Peer connection that closed.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);
        unset(self::$connHubIds[$connId]);

        $controller = $this->resolveController();
        if ($controller === null) {
            return;
        }

        $controller->onClose($connection);
    }

    /**
     * Parse the `hub_id` segment from a `/relay/federation/{hub_id}` path.
     *
     * @param string $path Request path (e.g. `/relay/federation/abc-123`).
     *
     * @return string|null The decoded hub UUID, or null if the path is invalid.
     */
    public static function parseHubId(string $path): ?string
    {
        if (preg_match('~^/relay/federation/([^/?#]+)/?(?:[?#].*)?$~', $path, $matches) !== 1) {
            return null;
        }

        $hubId = rawurldecode($matches[1]);
        $hubId = trim($hubId);

        return $hubId !== '' ? $hubId : null;
    }

    /**
     * Get the count of active federation connections (diagnostics).
     *
     * @return int
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$connHubIds);
    }

    /**
     * The inbound-connect limiter, lazily resolved (see
     * {@see self::resolveConnectLimiter()} for the fail-open law).
     */
    private function connectLimiter(): ?RateLimiterInterface
    {
        return $this->connectLimiter ??= $this->resolveConnectLimiter();
    }

    /**
     * The frame-budget limiter, lazily resolved — the SAME instance the leaf
     * leg receives at {@see self::onWorkerStart()}.
     */
    private function frameLimiter(): ?RateLimiterInterface
    {
        return $this->frameLimiter ??= $this->resolveFrameLimiter();
    }

    /**
     * Resolve the federation connect limiter from the container.
     *
     * Mirrors {@see \Phlix\Hub\SyncPlay\RelayWorker::resolveRelayConnectLimiter()}
     * and {@see ClientRelayWorker}: any failure (no container entry, broken
     * definition) yields null and the guard fails OPEN — the federation
     * surface must stay reachable when the limiter itself is misconfigured;
     * the alternative is a config typo denying federation to every paired
     * hub.
     */
    private function resolveConnectLimiter(): ?RateLimiterInterface
    {
        try {
            /** @var mixed $limiter */
            $limiter = $this->container->get(RateLimitProfiles::FEDERATION_CONNECT);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Federation: connect rate limiter unavailable, failing open (unlimited)',
                ['error' => $e->getMessage()],
            );

            return null;
        }

        return $limiter instanceof RateLimiterInterface ? $limiter : null;
    }

    /**
     * Resolve the shared federation frame-budget limiter from the container
     * (same fail-open law as {@see self::resolveConnectLimiter()}).
     */
    private function resolveFrameLimiter(): ?RateLimiterInterface
    {
        try {
            /** @var mixed $limiter */
            $limiter = $this->container->get(RateLimitProfiles::FEDERATION_FRAME);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Federation: frame rate limiter unavailable, failing open (unlimited)',
                ['error' => $e->getMessage()],
            );

            return null;
        }

        return $limiter instanceof RateLimiterInterface ? $limiter : null;
    }

    /**
     * Read an upgrade-request header as a plain string, null when absent or
     * non-string (the Workerman Request header bag shape).
     */
    private static function upgradeHeader(WorkermanRequest $request, string $name): ?string
    {
        /** @var mixed $value */
        $value = $request->header($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Resolve the FederationRelayController from the container.
     *
     * @return FederationRelayController|null
     */
    private function resolveController(): ?FederationRelayController
    {
        try {
            /** @var mixed $controller */
            $controller = $this->container->get(FederationRelayController::class);
        } catch (Throwable) {
            return null;
        }

        return $controller instanceof FederationRelayController ? $controller : null;
    }

    /**
     * Clear static connection map.
     *
     * Intended for test isolation only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connHubIds = [];
    }
}
