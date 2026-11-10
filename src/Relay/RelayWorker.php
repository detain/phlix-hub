<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Channel\Client as ChannelClient;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Common\Support\ProcessLocalTopology;
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
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;
use function spl_object_id;

/**
 * WebSocket worker that handles server-to-hub relay tunnel connections.
 *
 * Servers connect to ws://hub:8802 (wss:// when `relay_tls` is enabled —
 * see {@see self::tlsBootPosture()} for the bind-time TLS law) and send a
 * JSON HELLO message as their first message. The worker then registers the
 * connection with TunnelManager and hands off message handling to the
 * appropriate Tunnel.
 *
 * @package Phlix\Hub\Relay
 */
final class RelayWorker
{
    /**
     * WS close code used when an inbound server relay-connect is rate-limited.
     *
     * 1013 ("Try Again Later") is the RFC 6455 code for a transient server-side
     * refusal — the media server should back off and reconnect. This is NOT an
     * auth failure (that stays HELLO-time, code 'unauthorized'); it throttles the
     * connect rate itself to close the H-H1 tunnel-displacement DoS surface.
     */
    public const CLOSE_TRY_AGAIN_LATER = 1013;

    /**
     * Internal map of connection ID => Tunnel for active server connections.
     *
     * @var array<int, Tunnel>
     */
    private static array $connTunnels = [];

    /**
     * Per-worker relay-connect rate limiter (`rate_limiter.relay_connect`,
     * 10/60s), resolved in {@see onWorkerStart()} and memoized. Keyed by the
     * connecting server's remote IP in {@see onWebSocketConnect()}. Null keeps
     * the connect hook unlimited (limiter unavailable / not registered). The
     * relay worker is `count=1`, so this per-worker limit is a true global limit.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $relayConnectLimiter = null;

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
     * @param bool                $useTls      Enable TLS (wss://) on the relay WebSocket.
     * @param string|null         $tlsCert     Path to TLS certificate file (PEM); must be a
     *                                         readable file — enforced pre-bind by
     *                                         {@see self::assertTlsMaterialReadable()}.
     * @param string|null         $tlsKey      Path to TLS private key file (PEM), same law.
     * @param bool                $requireTls  M21 fail-closed posture: refuse to bind
     *                                         plaintext ws:// when true and TLS is off
     *                                         (config `relay_require_tls` /
     *                                         HUB_RELAY_REQUIRE_TLS, mirroring the
     *                                         server's PHLIX_RELAY_REQUIRE_TLS).
     * @param float              $handshakeTimeoutSeconds TCP-accept → WS-upgrade
     *                                         deadline from
     *                                         `relay.ws_handshake_timeout_seconds`
     *                                         ({@see InboundHandshakeGuard});
     *                                         <= 0 disables the guard.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = 8802,
        private readonly int $count = 1,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
        private readonly bool $useTls = false,
        private readonly ?string $tlsCert = null,
        private readonly ?string $tlsKey = null,
        private readonly bool $requireTls = false,
        private readonly float $handshakeTimeoutSeconds = InboundHandshakeGuard::DEFAULT_TIMEOUT_SECONDS,
    ) {
        ProcessLocalTopology::assertSingleProcess($count, 'phlix-hub-relay-ws (:8802)');
    }

    /**
     * Bind-time TLS posture for the :8802 listener (M21).
     *
     * Mirrors the server-side R17-01 tri-state (warn-only by default,
     * fail-closed when the operator mandates TLS) on the LISTEN end of the
     * same tunnel:
     *
     *  - `secure`   — TLS configured; bind wss:// (today's opt-in path).
     *  - `advisory` — plaintext default; bind ws:// after ONE loud boot
     *    warning (the shipped bind behavior plus the missing announcement).
     *  - `refused`  — `require_tls` set without TLS; never bind. The HELLO
     *    carries each media server's Ed25519 enrollment JWT — a durable
     *    bearer credential for its tunnel — and every relayed HTTP byte
     *    rides the same socket, so a mandated-secure posture must not stand
     *    up a credential-bearing cleartext listener at all.
     *
     * The default stays warn-only by estate law: the server sibling shipped
     * `PHLIX_RELAY_REQUIRE_TLS` default-off ("today's warn-only behavior",
     * phlix-server config/relay.php), so the hub listener does not
     * unilaterally flip majors-tier defaults here.
     *
     * @param bool $useTls     TLS configured on this listener (`relay_tls`).
     * @param bool $requireTls Operator mandate (`relay_require_tls`).
     *
     * @return 'secure'|'advisory'|'refused'
     */
    public static function tlsBootPosture(bool $useTls, bool $requireTls): string
    {
        if ($useTls) {
            return 'secure';
        }

        return $requireTls ? 'refused' : 'advisory';
    }

    /**
     * The one-per-boot cleartext advisory text (M21).
     *
     * Wording duty: it must name WHAT travels in the clear (enrollment JWT +
     * relayed HTTP), WHY it matters (JWT = durable tunnel bearer; this
     * listener is NOT HAProxy-fronted), and BOTH cures (HUB_RELAY_TLS to
     * secure the listener; HUB_RELAY_REQUIRE_TLS here or
     * PHLIX_RELAY_REQUIRE_TLS on servers to make plaintext fail closed).
     *
     * @param int $port Bound relay port.
     *
     * @return string Warning text for the relay log channel.
     */
    public static function plaintextAdvisory(int $port): string
    {
        return 'SECURITY — relay listener :' . $port . ' is binding PLAINTEXT ws:// '
            . '(relay_tls not enabled). The HELLO carries each media server\'s Ed25519 '
            . 'enrollment JWT — a durable bearer credential for that server\'s tunnel — '
            . 'and every relayed request/response afterwards, in the clear; an on-path '
            . 'capturer can re-play it to displace the incumbent. Unlike :8803 this '
            . 'listener is NOT fronted by HAProxy. Set HUB_RELAY_TLS=true '
            . '(+ HUB_RELAY_TLS_CERT/KEY) to serve wss://, and/or PHLIX_RELAY_REQUIRE_TLS=1 '
            . 'on the servers so they refuse plaintext dials. HUB_RELAY_REQUIRE_TLS=1 here '
            . 'turns this advisory into a fail-closed boot refusal.';
    }

    /**
     * The fail-closed refusal text (M21): require_tls + plaintext = no bind.
     *
     * @param int $port Requested relay port.
     *
     * @return string RuntimeException message.
     */
    public static function plaintextRefusal(int $port): string
    {
        return 'Refusing to bind relay listener :' . $port . ' as PLAINTEXT ws:// because '
            . 'relay_require_tls is enabled (HUB_RELAY_REQUIRE_TLS=1). The :8802 tunnel '
            . 'carries enrollment JWTs and relayed HTTP in the clear and is not '
            . 'proxy-fronted; under require_tls the hub will not start a '
            . 'credential-bearing cleartext listener. Enable TLS (HUB_RELAY_TLS=true + '
            . 'HUB_RELAY_TLS_CERT/KEY) or unset HUB_RELAY_REQUIRE_TLS to return to '
            . 'warn-only behaviour.';
    }

    /**
     * The canonical bind-time TLS context for the :8802 listener (M21).
     *
     * Single source of truth so the production bind, the unit shape law, and
     * the real-TLS loopback E2E child all speak from one builder — drift
     * between them is the failure mode this seam exists to prevent. The
     * hardening list (AEAD-only ciphers, secp384r1, compression off, OpenSSL
     * security level 2) is the server-side half of the estate TLS posture;
     * `allow_self_signed`/`verify_peer` here describe whether THIS listener
     * validates CLIENT certificates (it does not — tunnel authentication is
     * the enrollment JWT inside the HELLO, not mTLS). Peer validation of the
     * hub certificate is the dialing server's duty (`PHLIX_RELAY_TLS_VERIFY`,
     * phlix-server RelayConsumer::buildSslContext — strict by default).
     *
     * @param string $certPath local_cert PEM bundle; already validated by
     *                         {@see self::assertTlsMaterialReadable()}.
     * @param string $keyPath  local_pk PEM path.
     *
     * @return array{ssl: array<string, mixed>} Workerman socket context.
     */
    public static function relayTlsContext(string $certPath, string $keyPath): array
    {
        return [
            'ssl' => [
                'ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
                'ecdh_curve' => 'secp384r1',
                'disable_compression' => true,
                'security_level' => 2,
                'local_cert' => $certPath,
                'local_pk' => $keyPath,
                'allow_self_signed' => true,
                'verify_peer' => false,
            ],
        ];
    }

    /**
     * Fail fast, pre-bind, on TLS material that is not a readable file.
     *
     * Without this check a configured-but-missing PEM surfaces only when
     * Workerman's `stream_socket_server()` rejects the ssl context mid-boot —
     * possibly after other listeners already bound (a half-started hub) and
     * with an error text that never names the operator knob at fault. The
     * message must name the failing env var, the resolved path, the failed
     * condition, and both ways out (fix the path, or go back to plaintext
     * with the boot advisory). Red-first law: the parent happily built the
     * ssl context from nonexistent paths.
     *
     * @param string $certPath HUB_RELAY_TLS_CERT value.
     * @param string $keyPath  HUB_RELAY_TLS_KEY value.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When either path is not a readable file.
     */
    public static function assertTlsMaterialReadable(string $certPath, string $keyPath): void
    {
        $checks = ['HUB_RELAY_TLS_CERT' => $certPath, 'HUB_RELAY_TLS_KEY' => $keyPath];
        foreach ($checks as $envName => $path) {
            if (is_file($path) && is_readable($path)) {
                continue;
            }

            $state = is_file($path) ? 'exists but is not readable' : 'does not exist';
            throw new \InvalidArgumentException(
                'TLS enabled for relay worker but ' . $envName . ' (' . $path . ') ' . $state
                    . '. Point HUB_RELAY_TLS_CERT/HUB_RELAY_TLS_KEY at existing PEM files, '
                    . 'or set HUB_RELAY_TLS=false to bind plaintext ws:// with the boot advisory.',
            );
        }
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
        // M21: bind-time TLS posture. 'refused' throws before any socket
        // exists (the exception propagates out of Application::boot — no
        // try/catch wraps this construction site); 'advisory' is today's
        // default bind path plus the loud announcement it never had.
        $posture = self::tlsBootPosture($this->useTls, $this->requireTls);
        if ($posture === 'refused') {
            throw new \RuntimeException(self::plaintextRefusal($this->port));
        }
        if ($posture === 'advisory') {
            LoggerFactory::get(LogChannels::RELAY)->warning(self::plaintextAdvisory($this->port));
        }

        // Workerman's Worker::__construct() types $socketContext as a non-nullable
        // array, so default to an empty context (its own default for "no options")
        // rather than null — passing null throws a TypeError on the non-TLS path.
        $sslContext = [];

        // Enable TLS (wss://) when configured. The material is validated before
        // the Worker is even constructed: a configured-but-unreadable PEM must
        // fail the boot loudly and deterministically, never as a cryptic
        // OpenSSL digest from a half-booted hub (TLS-lane fail-fast law).
        if ($this->useTls) {
            if ($this->tlsCert === null || $this->tlsKey === null) {
                throw new \InvalidArgumentException(
                    'TLS enabled for relay worker but HUB_RELAY_TLS_CERT and/or HUB_RELAY_TLS_KEY env vars are not set',
                );
            }
            self::assertTlsMaterialReadable($this->tlsCert, $this->tlsKey);
            $sslContext = self::relayTlsContext($this->tlsCert, $this->tlsKey);
        }

        $worker = new Worker("websocket://0.0.0.0:{$this->port}", $sslContext);
        $worker->name = 'phlix-hub-relay-ws';
        $worker->count = $this->count;

        if ($this->useTls) {
            $worker->transport = 'ssl';
        }

        // Fired during the WS upgrade handshake (before any frames arrive).
        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // Minor 57: declared inbound package cap pinned at TCP-accept (law in
        // InboundPackageCap) — this surface no longer inherits it from vendor.
        // HOST-3 H3-3: the cap handler is wrapped in the repo-owned handshake
        // deadline — a socket that never completes its WS upgrade is closed
        // after relay.ws_handshake_timeout_seconds instead of held ~forever.
        $worker->onConnect = InboundHandshakeGuard::compose(
            InboundPackageCap::onConnect(),
            $this->handshakeTimeoutSeconds,
            'relay-ws :' . $this->port,
        );
        // Fired with each deframed WS message payload (HELLO text, then frames).
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        // Join the cross-process channel broker and wire the relay proxy so
        // HTTP workers can route browser requests through the tunnels this
        // worker owns.
        $worker->onWorkerStart = [$this, 'onWorkerStart'];

        // Close DB connections in onWorkerStop (still in coroutine context) so
        // hooked PDO sockets aren't destroyed at RSHUTDOWN outside a coroutine.
        \Phlix\Hub\Common\Database\ConnectionPool::armWorkerStopCleanup($worker);

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
                            // The relay worker is count=1 (single-instance,
                            // always started) so it is the designated single
                            // pruner: pass $shouldPrune=true so the shared-table
                            // retention DELETEs run from HERE ONLY. The HTTP and
                            // client-relay workers flush their own registries but
                            // pass false, so the DELETEs aren't multiplied by
                            // worker count ([H-W3]).
                            $flushService->flush(0, time(), true);
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
                        $maxBufferSize = 0;
                        foreach ($touchTunnelManager->allTunnels() as $tunnel) {
                            if (!$tunnel instanceof Tunnel) {
                                continue;
                            }
                            $ws = $tunnel->serverWs;
                            $collector->touchConnection($tunnel->tunnelId, $ws->bytesRead, $ws->bytesWritten);
                            $bufSize = $tunnel->getDecodeBufferSize();
                            if ($bufSize > $maxBufferSize) {
                                $maxBufferSize = $bufSize;
                            }
                        }
                        // Record the maximum decode buffer size across all tunnels.
                        $collector->setRelayDecodeBufferSize($maxBufferSize);
                    });
                }
            }
        } catch (Throwable $e) {
            $logger->error('Metrics: relay worker timer init failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // HB-4.6e: resolve this worker's relay-connect limiter into a field so
        // the WS-upgrade hook (onWebSocketConnect) can throttle inbound server
        // connects by remote IP. Resolved HERE (onWorkerStart, coroutine context)
        // rather than in the constructor. The limiter is a pure in-memory object
        // (no I/O), so onWebSocketConnect also lazily resolves it if this priming
        // was skipped. Guarded so a resolution failure never breaks the worker.
        $this->relayConnectLimiter = $this->resolveRelayConnectLimiter();

        // HB-2.6 (data-locality split): the DB-only reapers (stale-session reap,
        // server offline reaper, heartbeat/token prune, federation-session
        // reaper) run on the dedicated maintenance worker so their DB latency no
        // longer adds jitter to tunnel frame processing here. But the IN-MEMORY
        // reapers — the idle/half-open tunnel reaper (HB-0.1), the tunnel
        // keepalive heartbeat pinger, and the flush of the in-memory
        // byte/last-frame accumulators — scan the live TunnelManager registry +
        // accumulators that exist ONLY in THIS relay-worker process, so they must
        // be armed here (the maintenance fork's registry is empty). Armed once
        // (relay worker is count=1) from within the running loop (cid>=0).
        try {
            HubServicesProvider::startInMemoryReapers($this->container);
        } catch (Throwable $e) {
            $logger->error('Relay: failed to start in-memory reapers', ['error' => $e->getMessage()]);
        }
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
        // HOST-3 H3-3: the upgrade arrived — the handshake deadline has done
        // its job. Cancel FIRST, before any limiter/refuse branch, so a
        // rate-limited connection (closed below) can never have its deadline
        // fire against a reused object id later.
        InboundHandshakeGuard::cancel($connection);

        // HB-4.6e: throttle inbound server relay-connects by remote IP BEFORE any
        // HELLO processing, closing the H-H1 tunnel-displacement DoS surface (a
        // flood of connects on :8802). The server_id is not yet known here (it
        // arrives in the first deframed HELLO), so we key on the remote IP.
        $limiter = $this->relayConnectLimiter ??= $this->resolveRelayConnectLimiter();
        if ($limiter !== null) {
            $ip = $connection->getRemoteIp();
            $state = $limiter->hit('relay_connect:' . $ip);
            if ($state->limited) {
                LoggerFactory::get(LogChannels::RELAY)->warning(
                    'Relay: server relay-connect rate-limited',
                    ['remote_ip' => $ip, 'reset_at' => $state->resetAt],
                );
                // WS≠HTTP: there is no HTTP 429 envelope after the upgrade hook —
                // reject with a real WS close frame carrying code 1013 (try again
                // later) so the peer observes the code (L-4). The 429 mapping
                // (HB-4.6g) is HTTP-only.
                WebSocketCloseFrame::reject($connection, self::CLOSE_TRY_AGAIN_LATER);
                return;
            }
        }

        // Otherwise nothing to do on upgrade — the first deframed message carries
        // the JSON HELLO (and the server_id) which onMessage handles.
    }

    /**
     * Resolve the relay-connect rate limiter (`rate_limiter.relay_connect`) from
     * the container.
     *
     * Best-effort: returns null (unlimited connect hook) if the limiter is not
     * registered or cannot be resolved, so a container hiccup never breaks the
     * relay worker's connect path.
     *
     * @return RateLimiterInterface|null The relay-connect limiter, or null.
     */
    private function resolveRelayConnectLimiter(): ?RateLimiterInterface
    {
        try {
            /** @var mixed $limiter */
            $limiter = $this->container->get(RateLimitProfiles::RELAY_CONNECT);
        } catch (Throwable) {
            return null;
        }

        return $limiter instanceof RateLimiterInterface ? $limiter : null;
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
        } catch (Throwable $e) {
            $this->rejectHelloBeforeTunnel($connection, 'invalid_hello', 'json_decode_failed', $e->getMessage());
            return;
        }

        if (!is_array($hello)) {
            $this->rejectHelloBeforeTunnel($connection, 'invalid_hello', 'hello_json_not_an_object');
            return;
        }

        $serverId = $hello['server_id'] ?? null;
        if (!is_string($serverId) || $serverId === '') {
            $this->rejectHelloBeforeTunnel($connection, 'missing_server_id', 'server_id_absent_or_empty');
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

            // Let the tunnel process the HELLO. This validates the enrollment JWT
            // and, on success, transitions the tunnel to ACTIVE. On failure
            // Tunnel::handleHelloFrame calls close('unauthorized') and RETURNS —
            // it does NOT throw — so we MUST inspect the resulting state rather
            // than rely on an exception that never comes (the HB-2.2 defect).
            $tunnel->onServerMessage($data);

            if ($tunnel->status !== Tunnel::STATUS_ACTIVE) {
                // HELLO rejected (invalid/absent JWT, malformed payload). Any live
                // incumbent for this server_id stays routable and is NEVER evicted
                // by an unvalidated HELLO — this is the core of the HB-2.2 DoS fix.
                // Displacement is gated on validation, not on HELLO arrival.
                $tunnelManager->abortPendingConnection($serverId, $tunnel);
                return;
            }

            // JWT validated successfully — the new tunnel is now ACTIVE. Promote
            // it into the routing map and drain/displace any incumbent (HB-2.2).
            $tunnelManager->finalizeServerConnection($serverId);

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
     * Reject a HELLO that fails the wire-shape boundary, before any tunnel exists.
     *
     * F-4 (live-host observability review .reviews/2026-11-05/HOST-2.md): the
     * boundary rejects in {@see handleHello()} used to close the connection in
     * total silence while the Tunnel-side HELLO rejects ({@see
     * Tunnel::handleHelloFrame()}) logged theirs — an operator chasing a
     * misbehaving client saw nothing in the relay channel. These three paths now
     * warn with the reject reason and the shape-failure class, mirroring the
     * tunnel reject idiom. LOG-ONLY: the close reason on the wire is unchanged.
     *
     * REDACTION LAW: never add the raw payload or any value extracted from it to
     * the context. A HELLO carries the server's durable enrollment JWT (a bearer
     * credential); the shape-failure class and the parser message — which never
     * echoes payload bytes — are the entire observation. `remote_ip` is the only
     * correlator because server_id/tunnel_id do not exist yet: the payload
     * failed to parse far enough to trust them.
     *
     * @param TcpConnection $connection  The offending server connection.
     * @param string        $reason      Close reason sent on the wire (unchanged behavior).
     * @param string        $shapeFailure Machine class of the boundary failure.
     * @param string|null   $parserError json_decode error message, when one exists.
     *
     * @return void
     */
    private function rejectHelloBeforeTunnel(
        TcpConnection $connection,
        string $reason,
        string $shapeFailure,
        ?string $parserError = null,
    ): void {
        /** @var array<string, mixed> $context */
        $context = [
            'reason' => $reason,
            'shape_failure' => $shapeFailure,
            'remote_ip' => $connection->getRemoteIp(),
        ];
        if ($parserError !== null) {
            $context['parser_error'] = $parserError;
        }

        LoggerFactory::get(LogChannels::RELAY)->warning(
            'Relay: server HELLO rejected before tunnel creation',
            $context,
        );

        $connection->close($reason);
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
        // HOST-3 H3-3: a socket that died (or was rejected) before upgrading
        // must not leave its deadline armed — cancel is idempotent and keeps
        // the guard's map bounded to live pre-upgrade connections.
        InboundHandshakeGuard::cancel($connection);

        $connId = spl_object_id($connection);

        if (!isset(self::$connTunnels[$connId])) {
            // F-3 (live-host observability review .reviews/2026-11-05/HOST-2.md):
            // a stalled TLS handshake surfaces HERE and nothing used to be
            // logged. Workerman's ssl transport destroys the connection when a
            // handshake fails or the peer half-closes mid-handshake (vendor
            // TcpConnection::baseRead() -> doSslHandshake()/emitSslHandshakeFailure()
            // -> destroy()), so a peer that sent only a ClientHello and never
            // completed the handshake reaches onClose WITHOUT ever registering
            // a tunnel — the exact CLOSE_WAIT signature seen live on :8802.
            // Operators could not see these accepted connections being dropped;
            // this line names the lifecycle event. The byte counts carry the
            // fingerprint: ClientHello-only peers read ~1.5k and write ~0,
            // instant probes read/write 0. Nothing payload-derived is logged, so
            // no redaction duty attaches. LOG-ONLY: behavior is unchanged — the
            // unregistered connection was and remains simply ignored.
            LoggerFactory::get(LogChannels::RELAY)->info(
                'Relay: server connection closed without a tunnel',
                [
                    'remote_addr' => $connection->getRemoteAddress() ?: 'unknown',
                    'bytes_read' => $connection->bytesRead,
                    'bytes_written' => $connection->bytesWritten,
                ],
            );

            return;
        }

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

        // H-1: evict the dead tunnel from the manager's routing AND pending
        // maps. Without this the CLOSED tunnel lingers forever — the idle
        // reaper cannot see it (allTunnels() yields ACTIVE-only by design)
        // and nothing else removes it, so the resident relay worker's maps
        // grew unbounded with every disconnect. The identity check means a
        // promoted replacement is never evicted by the old connection's
        // deferred close landing after the swap.
        $this->evictTunnel($tunnel);
    }

    /**
     * Remove a (now dead) tunnel from the TunnelManager maps, by identity.
     *
     * Best-effort: a container hiccup must never break the WS close path, and
     * the periodic {@see IdleReaper::tick()} prune pass is the safety net that
     * catches anything this misses.
     *
     * @param Tunnel $tunnel The tunnel whose connection just closed.
     *
     * @return void
     */
    private function evictTunnel(Tunnel $tunnel): void
    {
        try {
            /** @var mixed $tunnelManager */
            $tunnelManager = $this->container->get(TunnelManagerInterface::class);
            if ($tunnelManager instanceof TunnelManagerInterface) {
                $tunnelManager->removeTunnel($tunnel->serverId, $tunnel);
            }
        } catch (Throwable) {
            // Swallowed by design: eviction is pure in-memory cleanup, and the
            // reaper's prune pass reclaims the entry on the next tick.
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
