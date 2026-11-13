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
use Phlix\Hub\Common\Http\TrustedProxyResolver;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Common\Support\ProcessLocalTopology;
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
use function time;
use function trim;

/**
 * WebSocket worker that handles hub-to-hub federation connections.
 *
 * Leaf hubs connect to `ws://master-hub:8805/relay/federation/{hub_id}` —
 * `wss://` only when `federation_tls` (HUB_FEDERATION_TLS) is enabled on this
 * listener — see {@see self::tlsBootPosture()} for the bind-time TLS law
 * (M22). The sentence above used to claim "connect via WSS" while the worker
 * could only ever bind plaintext: confidentiality of the hub↔hub link was
 * silently delegated to an upstream proxy nothing in the repo verified.
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
 *      On failure, close with reason `unknown_hub`.
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
 * Exception-barrier boundary (S312/M-HUB-03, extended by HUB-S31/HUB-56):
 * the contain-per-dispatch barrier is attested here at :8805 —
 * {@see self::onMessage()} carries the law and its reasoning — and at the
 * sibling relay workers' frame dispatch: :8802 {@see RelayWorker::onMessage()}
 * and :8803 {@see ClientRelayWorker::onMessage()} contain a throw with one
 * error line and a `handler_error` close; :8804
 * {@see \Phlix\Hub\SyncPlay\SyncPlayRelayWorker::onMessage()} carries its
 * own M-HUB-03 dispatch guard (handleFrameFailure). All four venues run
 * count=1, so the same single-process death vector applies to each.
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

    /** RFC 6455 policy violation (1008): bad path / unknown peer at connect. */
    public const CLOSE_POLICY = 1008;

    /** RFC 6455 internal error (1011): connect-time server-side failure. */
    public const CLOSE_INTERNAL = 1011;

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
     * @param bool               $useTls      Enable TLS (wss://) on the federation WS listener.
     * @param string|null        $tlsCert     Path to TLS certificate file (PEM).
     * @param string|null        $tlsKey      Path to TLS private key file (PEM).
     * @param bool               $requireTls  M22 fail-closed posture: refuse to bind
     *                                         plaintext ws:// when true and TLS is off
     *                                         (config `federation_require_tls` /
     *                                         HUB_FEDERATION_REQUIRE_TLS, mirroring the
     *                                         :8802 sibling's relay_require_tls).
     * @param string             $bindHost    Listen host (config `federation_bind_host` /
     *                                         HUB_FEDERATION_BIND); default 0.0.0.0 is the
     *                                         historical literal (no wire change).
     * @param float              $handshakeTimeoutSeconds Pre-upgrade deadline
     *                                         ({@see InboundHandshakeGuard}); <= 0 disables.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly int $count = 1,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
        private readonly bool $useTls = false,
        private readonly ?string $tlsCert = null,
        private readonly ?string $tlsKey = null,
        private readonly bool $requireTls = false,
        private readonly string $bindHost = '0.0.0.0',
        private readonly float $handshakeTimeoutSeconds = InboundHandshakeGuard::DEFAULT_TIMEOUT_SECONDS,
    ) {
        ProcessLocalTopology::assertSingleProcess($count, 'phlix-hub-federation-ws (:8805)');
    }

    /**
     * Bind-time TLS posture for the :8805 federation listener (M22).
     *
     * Mirrors the :8802 sibling law shipped at c32bb524 (review hub__secnet
     * M21) on the federation surface, tri-state, same names for greppable
     * parity:
     *
     *  - `secure`   — TLS configured; bind wss:// (opt-in native path).
     *  - `advisory` — plaintext bind after ONE loud boot warning. This is the
     *    documented common production shape (a managed install fronts :8805
     *    through HAProxy and terminates TLS upstream), but the listener
     *    cannot know its own topology, so the cleartext bind must announce
     *    itself — review hub__secnet M-3.
     *  - `refused`  — `require_tls` set without TLS; never bind. The H-4
     *    ceremony is signature-based, yet nothing in its canonical strings
     *    binds the TLS channel: an active on-path adversary on a plaintext
     *    link relays both ceremony legs untouched, gets both hubs to mark
     *    VERIFIED, and then reads and mutates every federation payload
     *    (share offers' library metadata included). A mandated-secure
     *    posture must not stand that listener up at all.
     *
     * The default stays warn-only by estate law — c32bb524 shipped the
     * :8802 side exactly this way ("flip both sides in one wave, never one
     * alone"), and a default-on mandate here would break the safe
     * proxy-terminated topology the installer documents.
     *
     * @param bool $useTls     TLS configured on this listener (`federation_tls`).
     * @param bool $requireTls Operator mandate (`federation_require_tls`).
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
     * The one-per-boot cleartext advisory text (M22).
     *
     * Wording duty: name WHAT travels in the clear (the H-4 ceremony and
     * every federation payload afterwards), WHY the signature-based handshake
     * does not save a cleartext link (nothing binds the canonical strings to
     * the TLS channel — active on-path = transparent relay), when the
     * plaintext bind IS the intended shape (managed-install HAProxy fronts
     * and terminates upstream), and BOTH cures (HUB_FEDERATION_TLS to serve
     * wss:// natively; HUB_FEDERATION_REQUIRE_TLS to make plaintext fail
     * closed).
     *
     * @param int $port Bound federation port.
     *
     * @return string Warning text for the relay log channel.
     */
    public static function plaintextAdvisory(int $port): string
    {
        return 'SECURITY — federation listener :' . $port . ' is binding PLAINTEXT ws:// '
            . '(federation_tls not enabled). The hub-to-hub link carries the H-4 handshake '
            . 'and every federation payload afterwards in the clear; the ceremony is '
            . 'signature-based, but nothing in its canonical strings binds the TLS channel, '
            . 'so an ACTIVE on-path adversary rides a transparent relay: both ceremony legs '
            . 'flow untouched, both hubs mark VERIFIED, and share offers\' library metadata '
            . '(and all future payloads) are readable and mutable from then on. If this '
            . 'master is fronted by the managed install\'s HAProxy (TLS terminates upstream, '
            . '443 only at the firewall) the bind is loopback-side and this is the expected '
            . 'shape — otherwise set HUB_FEDERATION_TLS=true (+ HUB_FEDERATION_TLS_CERT/KEY) '
            . 'to serve wss:// natively. HUB_FEDERATION_REQUIRE_TLS=1 turns this advisory '
            . 'into a fail-closed boot refusal.';
    }

    /**
     * The fail-closed refusal text (M22): require_tls + plaintext = no bind.
     *
     * Named after the consequence at boot(): the exception leaves start() and
     * lands in HubServicesProvider::boot()'s named skip trace (error_log
     * "Federation worker skipped"), so the message itself must state what
     * happened — no federation listener started — since that log line is the
     * operator's only boot-time evidence. The fail-closed property holds: a
     * mandated-secure hub never stands up a cleartext :8805.
     *
     * @param int $port Requested federation port.
     *
     * @return string RuntimeException message.
     */
    public static function plaintextRefusal(int $port): string
    {
        return 'Refusing to bind federation listener :' . $port . ' as PLAINTEXT ws:// because '
            . 'federation_require_tls is enabled (HUB_FEDERATION_REQUIRE_TLS=1): the hub-to-hub '
            . 'link would carry the H-4 ceremony and every federation payload in the clear, and '
            . 'nothing in the ceremony binds the TLS channel. Federation inbound is NOT started. '
            . 'Enable federation TLS (HUB_FEDERATION_TLS=true + HUB_FEDERATION_TLS_CERT/KEY), '
            . 'or — only if this master is proxy-fronted with TLS terminated upstream, which '
            . 'this listener cannot verify from inside — unset HUB_FEDERATION_REQUIRE_TLS to '
            . 'return to warn-only behaviour.';
    }

    /**
     * Start the federation WebSocket worker.
     *
     * @return Worker The configured worker instance.
     *
     * @throws \RuntimeException On the `refused` posture (M22): mandate set,
     *         TLS off — thrown BEFORE any Worker/socket construction.
     * @throws \InvalidArgumentException TLS on without cert/key (M22): the
     *         named env vars are missing — thrown BEFORE any bind too, the
     *         byte-shape of the :8802 sibling's cert law.
     */
    public function start(): Worker
    {
        // M22: bind-time TLS posture, mirroring the :8802 sibling —
        // 'refused' throws before any socket exists, 'advisory' is the
        // historical bind plus the loud announcement it never had. Under
        // boot()'s skip catch the throw disables federation inbound
        // (fail-closed by construction: no cleartext listener is ever
        // bound) while the hub keeps serving its other surfaces.
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

        // Enable TLS (wss://) when configured; same server-context options as
        // the :8802 relay listener family (M21 review i-row: ECDHE+AEAD only,
        // secp384r1, compression off, security level 2). Audit §3.2 adds what
        // this surface never had: a boot material decision (missing PEM fails
        // fast pre-bind like its :8802 sibling; exists-but-unreadable degrades
        // to a loud boot warning) and a per-connection TlsMaterialWatch so an
        // off-pipeline key delivery fails LOUD on every handshake instead of
        // closing silently.
        $materialWatch = null;
        if ($this->useTls) {
            if ($this->tlsCert === null || $this->tlsKey === null) {
                throw new \InvalidArgumentException(
                    'TLS enabled for federation worker but HUB_FEDERATION_TLS_CERT and/or '
                    . 'HUB_FEDERATION_TLS_KEY env vars are not set',
                );
            }
            $envPaths = ['HUB_FEDERATION_TLS_CERT' => $this->tlsCert, 'HUB_FEDERATION_TLS_KEY' => $this->tlsKey];
            $decision = TlsMaterialWatch::bootDecision($envPaths, 'federation worker', 'HUB_FEDERATION_TLS');
            if ($decision['fatal'] !== null) {
                throw TlsMaterialWatch::failFast($decision['fatal']);
            }

            foreach ($decision['warnings'] as $bootWarning) {
                LoggerFactory::get(LogChannels::RELAY)->warning($bootWarning);
            }

            $materialWatch = new TlsMaterialWatch('federation-ws :' . $this->port, $envPaths);
            $sslContext = [
                'ssl' => [
                    'ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
                    'ecdh_curve' => 'secp384r1',
                    'disable_compression' => true,
                    'security_level' => 2,
                    'local_cert' => $this->tlsCert,
                    'local_pk' => $this->tlsKey,
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                ],
            ];
        }

        $worker = new Worker(WsTlsContext::listenString($this->bindHost, $this->port), $sslContext);
        $worker->name = 'phlix-hub-federation-ws';
        $worker->count = $this->count;

        if ($this->useTls) {
            $worker->transport = 'ssl';
        }

        $worker->onWorkerStart = [$this, 'onWorkerStart'];
        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // Minor 57: declared inbound package cap pinned at TCP-accept (law in
        // InboundPackageCap) — this surface no longer inherits it from vendor.
        // The cap handler is wrapped in the repo-owned pre-upgrade deadline
        // (HOST-3 H3-3, extended to :8805 by HUB-S32): a socket that never
        // completes its WS upgrade is closed after
        // relay.ws_handshake_timeout_seconds instead of being held.
        $capHandler = InboundHandshakeGuard::compose(
            InboundPackageCap::onConnect(),
            $this->handshakeTimeoutSeconds,
            'federation-ws :' . $this->port,
        );
        if ($materialWatch !== null) {
            // Audit §3.2 runtime observability (see the :8802 sibling's
            // identical wiring): accept-time material re-check + enable_crypto
            // failure notices, rate-gated, ERROR-level, never silent.
            $watch = $materialWatch;
            $worker->onConnect = static function (TcpConnection $connection) use ($capHandler, $watch): void {
                $notice = $watch->materialNotice(time());
                if ($notice !== null) {
                    LoggerFactory::get(LogChannels::RELAY)->error($notice);
                }

                $capHandler($connection);
            };
            $worker->onError = static function (
                TcpConnection $connection,
                int $code,
                string $message
            ) use ($watch): void {
                $notice = $watch->handshakeFailureNotice(time(), $message);
                if ($notice !== null) {
                    LoggerFactory::get(LogChannels::RELAY)->error($notice);
                }
            };
        } else {
            $worker->onConnect = $capHandler;
        }
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
     * the handshake callbacks stamp — AND, since review hub__asphp, the same
     * is true of the LEAF side: the dispatcher is wired to THIS process's
     * {@see \Phlix\Hub\Federation\FederationPeerManager} (again container-
     * identical, the very instance the ARM above bootstrapped and claimed),
     * so leaf connect/disconnect/share commands execute on the one object
     * that may legally open and close the outbound link. Mirrors
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
            $channelHost = $this->channelHost;
            $channelPort = $this->channelPort;
            // Vendor property is typed with the undefined legacy `callback` pseudo-type.
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            // @phpstan-ignore assign.propertyType
            ChannelClient::$onConnect = static function () use ($logger, $channelHost, $channelPort): void {
                $logger->info('Federation master push: worker joined channel broker', [
                    'channel_host' => $channelHost,
                    'channel_port' => $channelPort,
                    'joined' => true,
                ]);
            };
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
            // S312 barrier (review hub__asphp M-A): leaf verbs run live DB
            // work in the owner seat; a throw out of this callback would kill
            // the count=1 process exactly like the WS-dispatch vectors. Route
            // through the named barrier so the subscription stays greppable
            // and the containment is unit-testable on its own. (Plain closure,
            // not `$this->onPushCommand($dispatcher, ...)`: first-class
            // callable argument binding is not parseable on this PHP build —
            // only the bare `f(...)` form exists.)
            /** @psalm-suppress InvalidArgument */
            ChannelClient::on(
                FederationPushProtocol::COMMAND_EVENT,
                function (mixed $data) use ($dispatcher): void {
                    $this->onPushCommand($dispatcher, $data);
                },
            );

            // Boot-time truth: only the subscription is REGISTERED here; the
            // broker TCP link is async. "Joined" is logged from the vendor
            // onConnect callback, when the broker link is actually up.
            $logger->info('Federation master push: registering channel subscription', [
                'channel_host' => $this->channelHost,
                'channel_port' => $this->channelPort,
                'joined' => false,
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
        // The upgrade arrived: cancel the handshake deadline FIRST.
        InboundHandshakeGuard::cancel($connection);

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $connId = spl_object_id($connection);

        $hubId = self::parseHubId($request->path());
        if ($hubId === null) {
            $logger->warning('Federation: WS rejected, missing hub_id in path', [
                'path' => $request->path(),
            ]);
            WebSocketCloseFrame::reject($connection, self::CLOSE_POLICY);
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
        // read. Limiter failure fails OPEN (unlimited) by sibling law — R2
        // makes that claim TRUE for both failure arms: resolution is caught in
        // resolveConnectLimiter(), and a SUPPLIED limiter whose hit() (or the
        // trusted-proxy resolve feeding it) throws is contained right here,
        // named on the log, and the connect falls through to validation.
        $limiter = $this->connectLimiter();
        if ($limiter !== null) {
            $state = null;
            // A non-null $state below implies the resolve+hit pair ran to
            // completion, so $clientIp is always assigned when it is read in
            // the limited branch; this initialisation exists for psalm's flow
            // analysis (PossiblyUndefinedVariable across the catch boundary).
            $clientIp = '';
            try {
                $this->trustedProxyResolver ??= new TrustedProxyResolver();
                $clientIp = $this->trustedProxyResolver->resolve(
                    $connection->getRemoteIp(),
                    self::upgradeHeader($request, 'x-forwarded-for'),
                    self::upgradeHeader($request, 'x-real-ip'),
                );

                $state = $limiter->hit('federation_connect:' . $clientIp);
            } catch (Throwable $e) {
                $logger->error('Federation: connect limiter threw — failing open (unlimited)', [
                    'hub_id' => $hubId,
                    'error'  => $e->getMessage(),
                ]);
                $state = null;
            }

            if ($state !== null && $state->limited) {
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
                WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL);
                return;
            }

            $peer = $hubRepo->getPeerById($hubId);
            if ($peer === null) {
                $logger->warning('Federation: WS rejected, unknown hub', [
                    'hub_id' => $hubId,
                ]);
                WebSocketCloseFrame::reject($connection, self::CLOSE_POLICY);
                return;
            }
        } catch (Throwable $e) {
            $logger->error('Federation: WS internal error during connect', [
                'hub_id' => $hubId,
                'error' => $e->getMessage(),
            ]);
            WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL);
            return;
        }

        self::$connHubIds[$connId] = $hubId;

        try {
            $controller = $this->resolveController();
            if ($controller === null) {
                WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL);
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
            WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL);
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
        // leaf's ladder re-dials. Fails OPEN (unlimited) when unresolvable —
        // and, R2-symmetric with the connect gate above, when the supplied
        // limiter's hit() THROWS: contained with one named error line, the
        // frame still dispatches, the resident worker stays alive.
        $hubId = self::$connHubIds[spl_object_id($connection)] ?? null;
        if ($hubId !== null) {
            $limiter = $this->frameLimiter();
            if ($limiter !== null) {
                try {
                    $state = $limiter->hit('federation_frame:' . $hubId);
                } catch (Throwable $e) {
                    LoggerFactory::get(LogChannels::RELAY)->error(
                        'Federation: frame limiter threw — failing open (unlimited)',
                        [
                            'hub_id' => $hubId,
                            'error'  => $e->getMessage(),
                        ],
                    );
                    $state = null;
                }

                if ($state !== null && $state->limited) {
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

        // S312/M-HUB-03 exception-barrier law (review hub__asphp M-A): this
        // dispatch runs in the count=1 :8805 process. A throwing DB call
        // inside the controller (getPeerByPublicKey et al.) escaping this
        // callback takes Worker::stopAll(250) through the process — a silent
        // crash-loop that kills every live leaf link. Contain per dispatch:
        // one error line, drop the poisoned socket (its own onClose does the
        // map cleanup), keep the worker alive for every other peer.
        try {
            $controller->onMessage($connection, $data);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Federation worker: dispatch threw; kept alive',
                [
                    'hub_id'    => $hubId,
                    'direction' => 'message',
                    'error'     => $e->getMessage(),
                    'class'     => get_class($e),
                ],
            );
            $connection->close('handler_error');
        }
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
        // A socket that died before upgrading must not leave its deadline armed.
        InboundHandshakeGuard::cancel($connection);

        $connId = spl_object_id($connection);
        $hubId = self::$connHubIds[$connId] ?? null;
        unset(self::$connHubIds[$connId]);

        $controller = $this->resolveController();
        if ($controller === null) {
            return;
        }

        // Same S312 barrier (review hub__asphp M-A): handleConnectionClosed
        // performs live DB work; a throw escaping here would crash-loop the
        // process on the teardown path itself. The controller guarantees its
        // own map cleanup under try/finally, so containment is log-only —
        // there is no surviving socket to close.
        try {
            $controller->onClose($connection);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Federation worker: dispatch threw; kept alive',
                [
                    'hub_id'    => $hubId,
                    'direction' => 'close',
                    'error'     => $e->getMessage(),
                    'class'     => get_class($e),
                ],
            );
        }
    }

    /**
     * Exception barrier for the Channel-bridge command dispatch.
     *
     * The :8805 worker is count=1 and {@see FederationPushDispatcher::onCommand()}
     * executes leaf verbs that touch the database in the owner seat
     * (connectToMaster / pushLibraryShare et al.). A Throwable escaping the
     * broker callback runs Worker::stopAll(250) through the process exactly
     * like the WS-dispatch vectors (S312/M-HUB-03 law, review hub__asphp M-A).
     * Contain, log, and publish NO reply — the no-fabricated-verdict doctrine
     * of the malformed-command path: the bridge's publisher side degrades to
     * a measured false on its own timeout rather than being handed a lie.
     *
     * @param FederationPushDispatcher $dispatcher The resolved command executor.
     * @param mixed                    $data       Raw broker command payload.
     *
     * @return void
     */
    public function onPushCommand(FederationPushDispatcher $dispatcher, mixed $data): void
    {
        try {
            $dispatcher->onCommand($data);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Federation worker: push command threw; kept alive',
                [
                    'direction' => 'push_command',
                    'error'     => $e->getMessage(),
                    'class'     => get_class($e),
                ],
            );
        }
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
     * Mirrors {@see \Phlix\Hub\Relay\RelayWorker::resolveRelayConnectLimiter()}
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
