<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrameType;
use RuntimeException;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;
use Workerman\Timer;

/**
 * Manages the persistent WebSocket connection from a leaf hub to the master hub.
 *
 * On leaf hubs this class:
 *   1. Connects to master hub's WS endpoint at
 *      `{scheme}://{master-host}[:{port}]/relay/federation/{leaf_hub_id}` —
 *      scheme and port come from the configured peer URL (M-8).
 *   2. Sends the `hub_hello` JSON text frame on connect
 *   3. Handles `hub_hello_ack` (stores session; cross-checks the reported
 *      `master_hub_id` against the master peer's bound identity when one is
 *      known, binding it on first use). H-4: the ack is CRYPTOGRAPHICALLY
 *      verified first — its Ed25519 signature over the canonical
 *      {session_id, master_hub_id, nonce} string must check against the
 *      master peer row's registered public key before ANY of its claims are
 *      trusted; a forged/unsigned ack closes the link with backoff. On
 *      acceptance the leaf answers with `hub_hello_auth`, proving possession of
 *      THIS hub's registered private key over {session_id, nonce,
 *      leaf_hub_id} (canonicals shared with the master via
 *      {@see FederationHandshake}).
 *   4. Sends HEARTBEAT (generic binary) every 15 seconds once verified
 *   5. Handles incoming DATA envelopes (library-share offers/revocations,
 *      admin-delegation payloads) — every inbound frame runs through the
 *      guarded seam in R2 order: shape gate first, then the shared :8805
 *      frame budget ({@see self::guardIncomingFrame()}, review
 *      hub__exploiter M3; validate-then-charge — a malformed frame drops
 *      FREE and can never spend a peer's budget) before dispatch, and
 *      payloads are refused before the channel is
 *      verified (H-4 leaf-side gate); admin delegations additionally run
 *      validation-before-write against the authenticated channel identity
 *      and the local users table (R17-07)
 *   6. Auto-reconnects with exponential backoff on disconnect — never after
 *      a deliberate disconnectFromMaster() (M-7); while federation is
 *      disabled the chain PARKS at the capped backoff (one gated re-check
 *      per ≤60 s — the only per-wake IO is the live `federation.enabled`
 *      settings read, no TCP attempt) instead of dying, so re-enabling
 *      re-dials automatically without a restart or an explicit trigger.
 *      The boot case lives in a RUNNING worker too:
 *      {@see self::bootstrapFromWorker()} (called from FederationWorker's
 *      onWorkerStart) arms or dials in the child's real event loop — a
 *      pre-fork master dial cannot connect and its pcntl-armed chain would
 *      fork-poison every child's reconnect latch (2026-10-05 lane).
 *   7. Pushes local library share changes to master when connected — and
 *      LOUDLY (log + counted) refuses them when the link is not live and
 *      verified: review hub__asphp retired the silent no-op return, which
 *      made a leaf push that never touched a socket indistinguishable from
 *      success in the HTTP caller's seat
 *   8. Per-generation stream isolation: the binary FrameDecoder is one
 *      shared instance, so establishConnection() resets it at the head of
 *      every dial — a link that tore mid-frame must never splice its
 *      residue into the next connection's byte stream (the decoder is
 *      reset-or-replaced nowhere else, and onClose is not guaranteed on
 *      every teardown path, so the establish seam is the convergence
 *      point of all generation transitions)
 *
 * ## The leaf link has exactly ONE owning process (review hub__asphp Critical)
 *
 * This class is container-built per process: every HTTP worker, every CLI run
 * and the `:8805` federation worker each hold a PRIVATE instance with private
 * socket state. Only the worker's instance ever calls
 * {@see self::bootstrapFromWorker()} — and that call is the sole claim of the
 * ownership stamp below. The lifecycle doors (connectToMaster,
 * disconnectFromMaster) and the outbound pushes REFUSE LOUDLY in every
 * non-owner, because an HTTP-side call there was never a request against the
 * live link at all: it either dialed a rogue SECOND AsyncTcpConnection under
 * this hub's identity (the master's hubId-keyed registration evicts the
 * legitimate :8805 link and the two processes' backoff ladders ping-pong the
 * link between them forever) or theater-touched a dead instance's fields
 * while the real link and ladder kept running unbothered. HTTP processes
 * REQUEST leaf lifecycle and share pushes across the durable channel
 * boundary instead — {@see FederationPushBridge::dispatchLeaf()} →
 * {@see FederationPushDispatcher::onCommand()}, executed in the worker on the
 * one instance that owns the socket. The reconnect ladder needs no request:
 * it already lives in the owner and ticks itself.
 *
 * @package Phlix\Hub\Federation
 */
class FederationPeerManager
{
    /**
     * Seconds to wait for `hub_hello_ack` after TCP connect before declaring the
     * link a zombie and dropping it so the reconnect path re-runs the
     * handshake (M-6 — previously isConnected() was true forever while the
     * master silently ignored a half-registered leaf).
     */
    private const int HELLO_ACK_TIMEOUT_SECONDS = 10;

    /**
     * Upper bound of the reconnect backoff ladder (seconds) — 5, 10, 20, 40,
     * then held here. ALSO the parked cadence during a
     * `federation.enabled=false` window: the reconnect tick's disabled branch
     * re-arms at exactly this delay, so a long off-window costs one wake per
     * cap — zero TCP attempts, no ladder restart (BEHAVIOR lane). The wake
     * is not literally free: each re-check performs the live
     * `federation.enabled` settings-store read (one `hub_settings` SELECT per
     * cap; the resolver's live-read law), which is everything the park does
     * besides resleeping.
     */
    private const int RECONNECT_DELAY_CAP_SECONDS = 60;

    /**
     * Leaf-side connection to master hub (null when disconnected).
     *
     * @var AsyncTcpConnection|null
     */
    private ?AsyncTcpConnection $masterConnection = null;

    /**
     * Review hub__asphp (Critical): does THIS process own the leaf link?
     *
     * Set exclusively by {@see self::bootstrapFromWorker()} — the call only
     * the `:8805` federation worker makes in its onWorkerStart, on the single
     * count=1 child that can hold a live event-loop dial. Every other
     * process's container-built instance keeps this false, which is what the
     * loud refusals in connectToMaster()/disconnectFromMaster()/the push sites
     * test. Production code outside the worker never sets it; the public
     * claim seam exists so unit harnesses (and any future testable boot
     * venue) can simulate the owner honestly.
     */
    private bool $leafLinkOwned = false;

    /**
     * Count of leaf lifecycle/push calls REFUSED because this process does not
     * own the link (review hub__asphp; diagnostics — a rising count outside the
     * `:8805` worker names a caller that forgot to cross the boundary).
     */
    private int $refusedUnownedCalls = 0;

    /**
     * Count of outbound leaf pushes refused for lacking a LIVE VERIFIED link
     * (no socket / H-4 ceremony unverified / no origin hub id). Sits beside
     * {@see self::$droppedOutboundFrames} — that one counts frames the socket
     * itself refused AFTER acceptance; this counts refusals BEFORE any frame
     * existed. Review hub__asphp: silence was the defect, this is the cure's
     * telemetry.
     */
    private int $refusedOutboundPushes = 0;

    /**
     * M-HUB-01: leaf→master frames Workerman REFUSED to take (send() ===
     * false: closing socket or the 1 MB sendBufferLimit reached). Before
     * this counter existed every such loss was invisible: all six push
     * sites discarded the verdict and the master simply never received the
     * share offer / heartbeat / handshake frame. Process-local; each drop
     * also warning-logs with its context.
     */
    private int $droppedOutboundFrames = 0;

    /**
     * M-HUB-01: total leaf→master outbound frames refused this process.
     */
    public function getDroppedOutboundFrames(): int
    {
        return $this->droppedOutboundFrames;
    }

    /**
     * M-HUB-01: interpret one Workerman send() verdict.
     *
     * Vendor contract (TcpConnection::send): true = flushed to the socket,
     * null = taken into the send buffer (delivered later or the link dies —
     * not this call's loss), false = REFUSED now (closed or buffer full).
     * Only false is counted-and-logged; the verdict is passed through so
     * callers that care can branch on it.
     *
     * @param bool|null $verdict  the raw send() return.
     * @param string    $context  what was being sent (log attribution).
     *
     * @return bool true when Workerman took the frame at all.
     */
    private function trackOutboundSend(?bool $verdict, string $context): bool
    {
        if ($verdict !== false) {
            return true;
        }

        ++$this->droppedOutboundFrames;
        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: outbound frame dropped',
            [
                'context' => $context,
                'total_dropped' => $this->droppedOutboundFrames,
            ],
        );

        return false;
    }

    /**
     * The live, VERIFIED link a leaf push may ride — or null after a loud,
     * counted refusal (review hub__asphp: pushes without a link must fail
     * LOUD and measurably, never as a silent return that looks like success).
     *
     * Returning the narrowed handle keeps the push sites phpstan-honest
     * without re-reading the property behind the check.
     *
     * ## Why channelVerified gates OUTBOUND pushes too
     *
     * The H-4 law is that transport liveness authorises nothing before the
     * signed ceremony completes — the inbound side enforces it per frame.
     * Offering shares over a half-handshake link would send claims the master
     * must (and does) refuse, making the leaf's push theater for exactly the
     * window the ceremony exists to quarantine.
     *
     * @param string $operation push site name (log/counter attribution).
     * @param string $detail    the share id being pushed (log attribution).
     */
    private function pushableLink(string $operation, string $detail): ?AsyncTcpConnection
    {
        if (!$this->leafLinkOwned) {
            $this->refuseUnownedLeafAction($operation);

            return null;
        }

        $connection = $this->masterConnection;
        if ($connection === null) {
            $this->refuseOutboundPush($operation, $detail, 'no leaf link in this process');

            return null;
        }

        if (!$this->channelVerified) {
            $this->refuseOutboundPush($operation, $detail, 'H-4 channel not verified');

            return null;
        }

        return $connection;
    }

    /**
     * One counted-and-logged refusal of an outbound push (see
     * {@see self::pushableLink()} and the origin-id arm in pushLibraryShare()).
     */
    private function refuseOutboundPush(string $operation, string $detail, string $why): void
    {
        ++$this->refusedOutboundPushes;
        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: outbound leaf push refused — ' . $why,
            [
                'operation' => $operation,
                'share_id' => $detail,
                'total_refused' => $this->refusedOutboundPushes,
            ],
        );
    }

    /**
     * The ownership refusal shared by the lifecycle doors and the pushes
     * (review hub__asphp). LOUD by construction — an error line naming the
     * cure, because a caller that reaches here is a wiring bug in the making:
     * leaf intents belong across the boundary
     * ({@see FederationPushBridge::dispatchLeaf()}), never in a non-owner.
     * Zero DB reads, zero sockets: nothing here can touch the link.
     */
    private function refuseUnownedLeafAction(string $operation): void
    {
        ++$this->refusedUnownedCalls;
        LoggerFactory::get(LogChannels::RELAY)->error(
            'FederationPeerManager: ' . $operation . ' refused — this process does not own the leaf '
            . 'link (the :8805 federation worker does, via bootstrapFromWorker()). Request it across '
            . 'the boundary instead: FederationPushBridge::dispatchLeaf() with the matching '
            . 'FederationPushProtocol::ACTION_LEAF_* action.',
            ['total_refused_unowned' => $this->refusedUnownedCalls],
        );
    }

    /**
     * Claim leaf-link ownership for THIS process (review hub__asphp).
     *
     * The ONLY production caller is {@see self::bootstrapFromWorker()} itself;
     * unit harnesses use this seam to exercise owner-side behavior honestly
     * instead of poking the property. An HTTP process that calls this has
     * defeated the guard — which is precisely why the controller no longer
     * even holds this class (see the wiring guard in
     * FederationLeafLinkOwnershipTest).
     */
    public function claimLeafLinkOwnership(): void
    {
        $this->leafLinkOwned = true;
    }

    /**
     * Whether THIS process's instance owns the leaf link.
     */
    public function ownsLeafLink(): bool
    {
        return $this->leafLinkOwned;
    }

    /**
     * Current reconnect delay in seconds (exponential backoff).
     *
     * @var int
     */
    private int $reconnectDelaySeconds = 5;

    /**
     * Whether a reconnection is scheduled.
     *
     * @var bool
     */
    private bool $reconnectScheduled = false;

    /**
     * True once the CURRENT disabled-window park has been announced on the
     * RELAY log. Throttles the hold message to one line per off-window —
     * reset when a tick proceeds past the enabled gate or the chain is torn
     * down deliberately — instead of one line every capped re-check.
     *
     * @var bool
     */
    private bool $reconnectHoldAnnounced = false;

    /**
     * True between a deliberate disconnectFromMaster() and the next explicit
     * connectToMaster(). scheduleReconnect() refuses to arm while set — the
     * close it performs fires its own onClose, which used to resurrect the
     * link the operator just dropped (M-7).
     *
     * @var bool
     */
    private bool $intentionalDisconnect = false;

    /**
     * Frame decoder for incoming binary frames.
     *
     * One instance for the object's lifetime, but GENERATION-scoped in
     * effect: {@see self::establishConnection()} calls
     * {@see FrameDecoder::reset()} at the head of every dial, so partial
     * bytes from a link that tore mid-frame can never desynchronize the
     * next connection's stream.
     *
     * @var FrameDecoder
     */
    private FrameDecoder $decoder;

    /**
     * Frame encoder for outgoing binary frames.
     *
     * @var FrameEncoder
     */
    private FrameEncoder $encoder;

    /**
     * Active heartbeat timer ID (Workerman).
     *
     * @var int|null
     */
    private ?int $heartbeatTimerId = null;

    /**
     * Stored session ID from `hub_hello_ack` handshake.
     *
     * @var string|null
     */
    private ?string $sessionId = null;

    /**
     * Active reconnect timer ID (Workerman) — deleted on deliberate
     * disconnect instead of being orphaned until it fires (M-7).
     *
     * @var int|null
     */
    private ?int $reconnectTimerId = null;

    /**
     * Active `hub_hello_ack` timeout timer ID (Workerman).
     *
     * @var int|null
     */
    private ?int $ackTimeoutTimerId = null;

    /**
     * Local federation_peers row id of the master peer being dialed
     * (captured at dial time). Sessions, heartbeats and incoming offers are
     * addressed through this id — never re-derived from liveness status,
     * which is exactly what silently dropped leaf-side session bookkeeping
     * before (C-1(1)/H-2).
     *
     * @var string
     */
    private string $masterPeerId = '';

    /**
     * M-HUB-14 (review hub__exploiter M3, leaf role): inbound frame budget
     * for the master link, installed once by {@see
     * \Phlix\Hub\Relay\FederationWorker::onWorkerStart()} — the SAME shared
     * `rate_limiter.federation_frame` instance the master-side listener uses,
     * keyed `federation_frame:<remote peer hub id>` so the leaf's budget is
     * the MASTER's per-link budget viewed from the other side. Null (never
     * wired, or the container could not provide one) = unlimited: the
     * documented fail-open of the whole federation limiter family — a broken
     * limiter may not sever an established leaf link's traffic.
     */
    private ?RateLimiterInterface $receiveFrameLimiter = null;

    /**
     * H-4 leaf-side channel gate: true only after a SIGNED `hub_hello_ack` verified
     * against the master peer's registered key AND this leaf's `hub_hello_auth` proof
     * was sent. Every inbound DATA payload (offers, shares, admin-delegation)
     * is refused while false — transport liveness authorises nothing. Reset at
     * the start of every ceremony and on any link teardown.
     *
     * @var bool
     */
    private bool $channelVerified = false;

    /**
     * @param FederationHubRepository            $hubRepo       Hub + peer repository.
     * @param FederationSessionManager           $sessions      Federation session manager.
     * @param FederationLibraryShareRepository $libraryShares Library shares repository.
     * @param FederationAdminDelegationRepository $adminDel     Admin delegation repository.
     * @param AuditLogger                       $audit         Audit logger.
     * @param Ed25519KeyManager                 $keyManager    This leaf's Ed25519 keypair (`hub_hello_auth` proof).
     * @param (callable(): bool)|null           $enabledResolver LIVE reader of
     *        `federation.enabled` (HubSettingsResolvers::bool, fail-safe to the
     *        boot flag); null keeps the pre-W5 always-on dial behavior.
     * @param (callable(): bool)|null           $tlsExtensionProbe testable seam
     *        over `extension_loaded('openssl')` checked at DIAL time (owner
     *        2026-10-06 spec: a TLS master in a process without the extension
     *        must fail loud naming openssl, never as an opaque stream error);
     *        null reads the real extension state.
     * @param (callable(string): bool)|null     $localUserProbe R17-07 seam over
     *        THIS hub's `users` table (wired to UserRepository::userExists):
     *        an admin-delegation payload's user_id is only persisted when the
     *        probe resolves it. Null FAILS CLOSED — an unwired probe refuses
     *        every delegation with its own audit reason, so a construction
     *        site that forgets the wiring can never silently open the
     *        dangling-row write path (the column has no FK to catch it).
     */
    public function __construct(
        private readonly FederationHubRepository $hubRepo,
        private readonly FederationSessionManager $sessions,
        private readonly FederationLibraryShareRepository $libraryShares,
        private readonly FederationAdminDelegationRepository $adminDel,
        private readonly AuditLogger $audit,
        private readonly Ed25519KeyManager $keyManager,
        // W5: LIVE reader of `federation.enabled` (HubSettingsResolvers::bool,
        // fail-safe to the boot flag). Null (unit tests) keeps the pre-W5
        // always-on behavior. Checked at dial time. Re-check law (BEHAVIOR
        // lane — lifts the one-shot death limitation recorded by the
        // docs-truth pass @5a048a6): a reconnect chain does NOT die during a
        // disabled window. The tick callback tests the gate itself and, while
        // closed, parks at RECONNECT_DELAY_CAP_SECONDS — one wake per cap,
        // zero TCP attempts — so re-enabling re-dials a link dropped
        // off-window within one capped tick, no restart or explicit trigger
        // needed. An explicit connectToMaster() refused while disabled arms
        // the same parked probe (idempotent; never while a socket is live or
        // the operator dropped the link — M-7), and so does a DISABLED BOOT
        // — in the federation worker's child, via bootstrapFromWorker()
        // (master-side boot dialing cannot live or self-heal: no event loop
        // pre-fork, poisoned latch post-fork). Links that survive the
        // off-window still re-admit traffic instantly (per-frame gates); the
        // ≤60s exponential backoff ladder on the ENABLED path is unchanged.
        private readonly mixed $enabledResolver = null,
        // Owner lane 2026-10-06: dial-time probe over extension_loaded('openssl').
        // S258 (scripts/assert-required-extensions.php) asserts the extension in
        // CI and the image ships it; this is the RUNTIME voice for a production
        // host provisioned without it — see tlsExtensionAvailable() and the
        // guard at the top of establishConnection(). Null (every production and
        // pre-existing construction site) reads the real extension state.
        private readonly mixed $tlsExtensionProbe = null,
        // R17-07: local-user resolution seam for admin-delegation payloads —
        // see the constructor @param and handleAdminDelegation()'s law.
        private readonly mixed $localUserProbe = null,
    ) {
        $this->decoder = new FrameDecoder();
        $this->encoder = new FrameEncoder();
    }

    /**
     * Whether the federation subsystem currently dials peers.
     */
    private function federationEnabled(): bool
    {
        $resolver = $this->enabledResolver;

        return is_callable($resolver) ? $resolver() : true;
    }

    /**
     * Whether THIS process can speak TLS on a dial: php-openssl must be
     * loaded. S258 asserts the extension for CI and the Docker image, but a
     * production host built without it would otherwise meet only an opaque
     * stream-socket error at connect() — this seam turns that into one loud,
     * named line at dial time. Mirrors the {@see self::federationEnabled()}
     * injectable shape so the refused branch stays unit-provable on hosts
     * where the extension (conveniently) IS present.
     */
    private function tlsExtensionAvailable(): bool
    {
        $probe = $this->tlsExtensionProbe;

        return is_callable($probe) ? $probe() : extension_loaded('openssl');
    }

    /**
     * Initiate the persistent WebSocket connection to the master hub.
     *
     * Only operates when this hub is configured as a leaf hub with an
     * active relay-enabled peer (the master). Idempotent — if already
     * connected this is a no-op. An explicit call re-arms auto-reconnect
     * after a deliberate disconnect.
     *
     * OWNER-ONLY (review hub__asphp): in a process that did not claim the
     * link via {@see self::bootstrapFromWorker()} this refuses LOUD with zero
     * side effects — no settings read, no DB read, no socket. The HTTP admin
     * path reaches it exclusively via the channel commands the `:8805` worker
     * executes on its own claimant instance; the reconnect ladder calls it
     * inside the owner.
     *
     * @return void
     */
    public function connectToMaster(): void
    {
        // Review hub__asphp (Critical): a non-owner dial here was the rogue
        // second link — same hub identity, and the master's hubId-keyed
        // registration EVICTS the legitimate :8805 link, after which the two
        // processes' backoff ladders ping-pong the connection forever. The
        // refusal is unconditional and first: an unowned instance must not
        // even reach the enabled-gate arming below, or every HTTP process
        // would park its own private ladder against a link it cannot own.
        if (!$this->leafLinkOwned) {
            $this->refuseUnownedLeafAction('connectToMaster()');

            return;
        }

        // W5 kill switch: no dials while federation is off. Deliberately
        // BEFORE the enabled path's state mutation (intentionalDisconnect
        // stays as-is) so toggling the setting cannot silently re-arm a link
        // the operator dropped (M-7). BEHAVIOR lane: the refusal no longer
        // lets the chain end silently — when nothing is live and no tick is
        // already armed, arm the parked disabled-hold probe (scheduleReconnect
        // owns the single-in-flight-timer guard). Outside a Workerman timer
        // runtime Timer::add throws — swallowed exactly like the src/Relay
        // sites, so in a plain CLI/unit context the observable behavior stays
        // the silent return it always was.
        if (!$this->federationEnabled()) {
            if ($this->masterConnection === null && !$this->intentionalDisconnect && !$this->reconnectScheduled) {
                try {
                    $this->scheduleReconnect();
                } catch (Throwable) {
                    // No Workerman runtime — nothing to arm; the next boot or
                    // explicit trigger dials as before.
                }
            }

            return;
        }

        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig === null) {
            return;
        }

        $role = is_string($hubConfig['role'] ?? null) ? $hubConfig['role'] : 'leaf';
        /** @var mixed $isActiveRaw */
        $isActiveRaw = $hubConfig['is_active'] ?? null;
        $isActive = is_int($isActiveRaw) ? $isActiveRaw === 1 : (is_string($isActiveRaw) && $isActiveRaw === '1');

        if (!$isActive || $role !== 'leaf') {
            return;
        }

        // Explicit dial intent re-enables the auto-reconnect loop.
        $this->intentionalDisconnect = false;

        // C-1(1): dial ELIGIBLE peers (pending/connected/disconnected), not
        // 'connected' ones — status 'connected' only ever appears AFTER a
        // successful `hub_hello_ack`, so filtering on it could never bootstrap or
        // recover a link.
        $peers = $this->hubRepo->getDialablePeers();
        if ($peers === []) {
            return;
        }

        if (count($peers) > 1) {
            // M-8 fail-safe posture: the leaf protocol supports exactly one
            // master. Multiple non-suspended peers is an operator ambiguity;
            // the first (by name) is dialed, loudly.
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: multiple dialable peers, dialing first as master',
                ['peer_count' => count($peers), 'selected' => $peers[0]['id'] ?? null],
            );
        }

        /** @var array<string, mixed> $masterPeer */
        $masterPeer = $peers[0];
        /** @var string $masterPeerId */
        $masterPeerId = is_string($masterPeer['id'] ?? null) ? $masterPeer['id'] : '';
        /** @var string $masterUrl */
        $masterUrl = is_string($masterPeer['url'] ?? null) ? $masterPeer['url'] : '';

        if ($masterPeerId === '' || $masterUrl === '') {
            return;
        }

        /** @var string $leafHubId */
        $leafHubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
        if ($leafHubId === '') {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: refusing to dial — this hub has no configured id',
            );
            return;
        }

        $this->masterPeerId = $masterPeerId;
        $this->establishConnection($this->buildMasterDialPlan($masterUrl, $leafHubId), $hubConfig);
    }

    /**
     * Child-process boot entry: (re-)establish this hub's leaf link from a
     * RUNNING worker's event loop. Called by
     * {@see \Phlix\Hub\Relay\FederationWorker::onWorkerStart()} — the single
     * boot dialer, because that worker is count=1 (the same law the push-
     * dispatcher subscription documents). The call is ALSO the sole claim of
     * leaf-link ownership (review hub__asphp): from this point the lifecycle
     * doors in this class open for THIS process's instance and stay shut,
     * loudly, for every other process's instance of it.
     *
     * Why the boot dial cannot live in the master (STEP-0 repro, 2026-10-05):
     * {@see \Workerman\Worker::runAll()} only creates the event loop INSIDE
     * each child ({@see \Workerman\Worker::$globalEvent} is null in the
     * master), and {@see \Workerman\Worker::getEventLoop()} is typed
     * non-nullable. A master-context dial therefore has two failure shapes,
     * both terminal for self-heal:
     *   1. `ws://` (http-scheme peer): `AsyncTcpConnection::connect()`
     *      TypeErrors on getEventLoop() BEFORE the socket is created; the
     *      established-path catch logs it and arms the backoff chain — in
     *      the MASTER, via Workerman's pcntl task table. Master ticks fire
     *      forever (one per ≤60 s once parked), each dying on the same
     *      TypeError; and every forked child inherits
     *      `reconnectScheduled = true` pointing at a master-owned task-table
     *      entry that {@see \Workerman\Timer::signalHandle()} never ticks
     *      once `Timer::$event` is set (children), so the child can neither
     *      re-arm (dedup guard) nor inherit a live link. POISONED LATCH.
     *   2. `wss://` (https-scheme peer, pre-2026-10-06): the vendored
     *      workerman v5.2.2 has no `Protocols\Wss` class, so even the ASYNC
     *      CONNECTION CONSTRUCTOR threw RuntimeException at
     *      {@see self::establishConnection()} — outside that method's
     *      connect() try/catch — escaping connectToMaster() entirely and
     *      being swallowed silently by the pre-lane master-side boot
     *      try/catch: no chain, no error log, link never attempted. FIXED
     *      forward: https peers now map to the `ws://` + `transport='ssl'`
     *      idiom in {@see self::buildMasterDialPlan()}, so this shape can
     *      no longer occur — the catch below stays for the remaining
     *      pre-socket throws (a malformed peer URL still fails in the
     *      constructor; a dead settings/DB read throws before the plan is
     *      ever built).
     *
     * Neither shape can occur inside this worker: the loop exists before
     * onWorkerStart fires (Worker::run() sets globalEvent + Timer::init
     * first), so a dial here either lives or fails into a backoff chain
     * that ACTUALLY TICKS. That is what makes the boot self-heal law true
     * in both the enabled and disabled case.
     *
     * @return void
     */
    public function bootstrapFromWorker(): void
    {
        // Review hub__asphp (Critical): THE CLAIM. First act, ahead of the
        // latch reset and every branch below — including the
        // intentionalDisconnect early return: an owner that holds a dropped
        // link must keep its doors actionable (a later connect/disconnect
        // REQUEST lands on this instance), while non-owners stay refused.
        // The worker's onWorkerStart runs per child after fork, so the single
        // child re-claims for itself; no other process ever reaches this
        // method, which is exactly what makes the flag the boundary it is.
        $this->leafLinkOwned = true;

        // Latch-reset law: fork copies master process state, and any
        // master-armed chain state a child inherits is untrustworthy by
        // construction — the timer it names belongs to a scheduler that can
        // never fire here (see the repro note above). Clear the two chain
        // fields BEFORE doing anything else so every arming site below (and
        // every later onClose/error re-arm) can take the dedup guard
        // honestly. The inherited timer id is never del()'d: it names a
        // MASTER task-table slot; del()'ing that id value against the
        // child's event-loop driver would cancel an unrelated timer.
        $this->reconnectScheduled = false;
        $this->reconnectTimerId = null;

        // M-7 across fork: a deliberate disconnect lives in THIS process's
        // trigger path (HTTP worker), and boot()'s only master-side call was
        // connectToMaster() — so at fork time this flag is false in every
        // reachable production state. Honored defensively: if a future
        // master-context ever sets it before forking, the child must not
        // resurrect a link the operator dropped.
        if ($this->intentionalDisconnect) {
            return;
        }

        // Defensive (unreachable today, per the repro): a fork-inherited
        // connection object cannot be a live socket — both master dial shapes
        // die before a socket exists — but if a non-null handle ever did
        // arrive via a future code path, drop the OBJECT without close():
        // any fd behind it belongs to the master's context, and closing it
        // here would tear a shared descriptor down under the parent. The
        // dial below then creates this process's own socket.
        $this->masterConnection = null;

        if (!$this->federationEnabled()) {
            // Booted disabled: park the chain HERE — this worker's real loop
            // ticks it, so re-enabling re-dials within one capped tick. The
            // fresh chain arms at the initial delay and parks at the cap on
            // the first gated tick (identical ladder to the gate site).
            try {
                $this->scheduleReconnect();
            } catch (Throwable) {
                // No Workerman timer runtime (CLI/unit context) — an
                // explicit trigger dials as before.
            }

            return;
        }

        try {
            $this->connectToMaster();
        } catch (Throwable $e) {
            // Constructor-class throws still escape establishConnection's
            // connect() catch (the legacy repro used the missing Wss protocol;
            // since the transport=ssl mapping an https peer no longer throws
            // there, but a malformed peer URL still can, and the settings/DB
            // reads before the plan is built always can): a boot dial must
            // never die silently the way the master-side one did — log LOUDLY
            // and hand the chain to the backoff, so the link is retried on
            // this loop (the same posture an unreachable master already gets)
            // instead of a single swallowed attempt.
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: boot dial threw before the socket existed — arming backoff',
                ['error' => $e->getMessage()],
            );
            try {
                $this->scheduleReconnect();
            } catch (Throwable) {
                // No timer runtime — nothing to arm; explicit trigger path
                // remains the recovery door.
            }
        }
    }

    /**
     * Parse the configured peer URL into a connection plan (M-8).
     *
     * Honors the configured scheme (the plaintext pair http/ws → plain ws,
     * anything else → TLS) and the configured port; the pre-M-8 code
     * hard-coded 'wss://' + bare host, so a peer URL like
     * `https://master.example:8443` silently dialed the wrong endpoint.
     *
     * TLS is expressed the way the vendored workerman v5.2.2 requires: it
     * has NO `Protocols\Wss` class, so a `wss://` URI throws at the
     * AsyncTcpConnection CONSTRUCTOR — the scheme is not a protocol, it is
     * a stream transport. The documented idiom (and the one phlix-server's
     * RelayConsumer already ships for its hub dial) is a `ws://` URI with
     * `$connection->transport = 'ssl'` set before connect(). The scheme
     * `ws` resolves the real client protocol (`Workerman\Protocols\Ws`),
     * the TLS handshake runs under the hood, and the WS upgrade then flows
     * through the encrypted stream — see {@see self::establishConnection()}.
     *
     * The ssl stream context states the verification posture EXPLICITLY:
     * `verify_peer`, `verify_peer_name` and `SNI_enabled` are true, and
     * `peer_name` names the configured host — because the URI scheme is
     * `ws://`, the certificate must still be checked against the real
     * master hostname, not whatever the raw TCP dial happened to target.
     * Strict verification is law here: there is deliberately NO hub-side
     * escape hatch (no verify_peer=false, no allow_self_signed toggle —
     * unlike phlix-server's documented relay mirror).
     *
     * Trust anchor: by default the context carries NO `cafile`, so the
     * wrapper falls back to the openssl.cafile / openssl.capath php.ini
     * entries and the system store. An operator whose master presents a
     * private-CA certificate sets `PHLIX_FEDERATION_CA_BUNDLE` to a PEM
     * bundle — that SELECTS a trust anchor, it never weakens
     * verification (a missing/unreadable bundle fails the dial LOUD at
     * plan time rather than silently degrading to the system store;
     * runtime `ini_set('openssl.cafile', ...)` is not a usable seam
     * because that entry is not user-modifiable at runtime).
     *
     * The port is only embedded when the peer URL carries one. A portless
     * `https://` master yields `ws://{host}` + transport `ssl`, and
     * `AsyncTcpConnection::connect()` resolves that pairing to :443
     * itself; portless plaintext keeps the :80 default — the same numbers
     * the old wss/ws URLs implied.
     *
     * @param string $masterUrl Configured peer URL (e.g. https://host:port).
     * @param string $leafHubId This hub's own federation_hubs.id.
     *
     * @return array{uri: string, transport: string, socket_context: array<string, mixed>}
     *   uri: the ws:// dial URL (never wss:// — TLS rides on `transport`);
     *   transport: 'ssl' for TLS masters, '' for plaintext (property left
     *   at workerman's default);
     *   socket_context: ssl stream options for TLS, [] for plaintext.
     */
    private function buildMasterDialPlan(string $masterUrl, string $leafHubId): array
    {
        $scheme = parse_url($masterUrl, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';

        $host = parse_url($masterUrl, PHP_URL_HOST);
        $masterHost = is_string($host) && $host !== '' ? $host : $masterUrl;

        $port = parse_url($masterUrl, PHP_URL_PORT);
        $portSuffix = is_numeric($port) ? ':' . $port : '';

        $uri = 'ws://' . $masterHost . $portSuffix
            . '/relay/federation/' . rawurlencode($leafHubId);

        if ($scheme === 'http' || $scheme === 'ws') {
            return ['uri' => $uri, 'transport' => '', 'socket_context' => []];
        }

        // https, a bare host (the legacy secure-by-default fallback), and
        // any scheme outside the plaintext http/ws pair — including an
        // operator-written `wss://` — all mean TLS: the exact mapping table
        // the old code sent to the nonexistent Wss protocol class.
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $masterHost,
        ];

        $caBundle = getenv('PHLIX_FEDERATION_CA_BUNDLE');
        if (is_string($caBundle) && $caBundle !== '') {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                // Fail loud, never degrade: a configured bundle that cannot
                // be read must abort the dial, not quietly fall back to the
                // system store (which would either fail confusingly later or,
                // worse, succeed against a different trust root).
                throw new RuntimeException(
                    'FederationPeerManager: PHLIX_FEDERATION_CA_BUNDLE is set to "' . $caBundle
                    . '" but no readable file exists there — refusing to dial rather than'
                    . ' falling back to the system trust store.',
                );
            }
            $ssl['cafile'] = $caBundle;
        }

        return [
            'uri' => $uri,
            'transport' => 'ssl',
            'socket_context' => ['ssl' => $ssl],
        ];
    }

    /**
     * Actively disconnect from the master hub and cancel timers.
     *
     * Deliberate: the flag is set BEFORE the close so the connection's own
     * onClose callback cannot schedule a reconnect, and any already-armed
     * reconnect timer is deleted rather than left to fire (M-7).
     *
     * OWNER-ONLY (review hub__asphp): from a non-owner this was theater — it
     * flipped flags on a private instance that had never held the worker's
     * socket, so the real link AND its ladder kept running while the HTTP
     * response reported success. The refusal is total: a non-owner may not
     * even set intentionalDisconnect, or it would poison its own harmless
     * instance state against a future legitimate claim.
     *
     * @return void
     */
    public function disconnectFromMaster(): void
    {
        if (!$this->leafLinkOwned) {
            $this->refuseUnownedLeafAction('disconnectFromMaster()');

            return;
        }

        $this->intentionalDisconnect = true;
        $this->cancelHeartbeatTimer();
        $this->cancelAckTimeoutTimer();

        if ($this->reconnectTimerId !== null) {
            try {
                Timer::del($this->reconnectTimerId);
            } catch (Throwable) {
                // Already fired or invalid.
            }
            $this->reconnectTimerId = null;
        }

        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore close errors
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->masterPeerId = '';
        $this->channelVerified = false;
        $this->reconnectScheduled = false;
        $this->reconnectHoldAnnounced = false;
        $this->reconnectDelaySeconds = 5;
    }

    /**
     * Push an outgoing library share offer to the master hub.
     *
     * Review hub__asphp (Critical): the old shape was `void` with a SILENT
     * `masterConnection === null` return — an HTTP caller could not tell a
     * delivered offer from one that never existed, and in production it was
     * ALWAYS the latter, because this instance lived in a different process
     * from the link. Now: owner law, H-4 verified law (a half-handshake link
     * gets loud refusals, not claims the master must drop), every refusal
     * counted + logged, and the send verdict returned for the dispatcher that
     * owes an explicit `delivered` reply.
     *
     * @param string $shareId    Share UUID.
     * @param string $libraryId   Library UUID.
     * @param string $libraryName Library name.
     * @param string $permission Permission level ('read'|'readwrite').
     *
     * @return bool true only when the live verified link took the frame.
     */
    public function pushLibraryShare(
        string $shareId,
        string $libraryId,
        string $libraryName,
        string $permission,
    ): bool {
        $connection = $this->pushableLink('library_share_offer', $shareId);
        if ($connection === null) {
            return false;
        }

        // M-5: every offer carries `peer_id` = THIS hub's own
        // federation_hubs.id (the originating identity). The master rewrites
        // it to its local peer-row FK before persisting; a local row id from
        // this database would be meaningless — and FK-dangerous — over there.
        $hubConfig = $this->hubRepo->getHubConfig();
        /** @var string $originHubId */
        $originHubId = is_array($hubConfig) && is_string($hubConfig['id'] ?? null)
            ? $hubConfig['id']
            : '';
        if ($originHubId === '') {
            $this->refuseOutboundPush('library_share_offer', $shareId, 'this hub has no configured id');

            return false;
        }

        $payload = json_encode([
            'shares' => [
                [
                    'id' => $shareId,
                    'peer_id' => $originHubId,
                    'library_id' => $libraryId,
                    'library_name' => $libraryName,
                    'permission' => $permission,
                    'status' => 'active',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);

        return $this->trackOutboundSend($connection->send($frame), 'library_share_offer');
    }

    /**
     * Push a library share revocation to the master hub if connected.
     *
     * Same loud/measured law as {@see self::pushLibraryShare()} (review
     * hub__asphp).
     *
     * @param string $shareId Share UUID being revoked.
     *
     * @return bool true only when the live verified link took the frame.
     */
    public function pushLibraryShareRevoked(string $shareId): bool
    {
        $connection = $this->pushableLink('library_share_revoked', $shareId);
        if ($connection === null) {
            return false;
        }

        $payload = json_encode([
            'share_id' => $shareId,
        ], JSON_THROW_ON_ERROR);

        $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);

        return $this->trackOutboundSend($connection->send($frame), 'library_share_revoked');
    }

    /**
     * Re-push every ACTIVE outgoing share to the master right after the H-4
     * ceremony verified this link (mirrors the master-side
     * {@see FederationFrameHandler::pushLibrarySharesToLeaf()} replay, which
     * the same review cites as the precedent this side lacked).
     *
     * Review hub__asphp: leaf→master share pushes created during a link gap
     * used to evaporate — the silent no-op — and the master never learned of
     * them until the operator re-saved. With the replay, a share created while
     * the link was down (or refused because it was) converges at the next
     * successful hello; the master's handleIncomingOffer is an
     * INSERT..ON DUPLICATE KEY UPDATE on the same (id, peer) row, so replaying
     * what already arrived is idempotent BY DESIGN.
     *
     * Fail-soft by law: this runs INSIDE the ack handler on the resident
     * worker's hot path — a repository or encode throw may never take the
     * freshly verified link down (the heartbeat and the frame gates are the
     * link's actual job). One loud line and a counted skip is the ceiling.
     *
     * @param array<array-key, mixed> $hubConfig this hub's own config row
     */
    private function replayActiveOutgoingShares(array $hubConfig): void
    {
        $connection = $this->masterConnection;
        if ($connection === null || !$this->channelVerified || $this->masterPeerId === '') {
            return;
        }

        try {
            $activeShares = $this->libraryShares->getActiveOutgoingSharesForPeer($this->masterPeerId);
            if ($activeShares === []) {
                return;
            }

            /** @var string $originHubId */
            $originHubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
            if ($originHubId === '') {
                $this->refuseOutboundPush('library_share_replay', '', 'this hub has no configured id');

                return;
            }

            $shares = [];
            foreach ($activeShares as $share) {
                /** @var array<string, mixed> $share */
                $shareId = self::shareColumn($share, 'id');
                $libraryId = self::shareColumn($share, 'library_id');
                if ($shareId === '' || $libraryId === '') {
                    // Corrupt row: skipping it loudly beats poisoning the
                    // one batched frame the healthy shares ride.
                    $this->refuseOutboundPush(
                        'library_share_replay',
                        $shareId,
                        'stored row is missing id or library_id',
                    );

                    continue;
                }

                $status = self::shareColumn($share, 'status');
                $shares[] = [
                    'id' => $shareId,
                    // Wire identity: the ORIGINATING hub's own id (M-5).
                    'peer_id' => $originHubId,
                    'library_id' => $libraryId,
                    'library_name' => self::shareColumn($share, 'library_name'),
                    'permission' => self::shareColumn($share, 'permission'),
                    'status' => $status !== '' ? $status : 'active',
                ];
            }

            if ($shares === []) {
                return;
            }

            $payload = json_encode(['shares' => $shares], JSON_THROW_ON_ERROR);
            $frame = $this->encoder->encode(RelayFrameType::DATA, 0, $payload);
            $this->trackOutboundSend($connection->send($frame), 'library_share_replay');
        } catch (Throwable $e) {
            // Fail-soft law above: the link stays up; the loss is loud.
            $this->refuseOutboundPush('library_share_replay', '', 'replay threw: ' . $e->getMessage());
        }
    }

    /**
     * A share row column as the string the wire needs, '' when the driver
     * handed back anything else (defensive read, mirrors the controller's
     * coercion idiom).
     *
     * @param array<string, mixed> $row
     */
    private static function shareColumn(array $row, string $key): string
    {
        /** @var mixed $value */
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Check whether we are currently connected to the master hub.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->masterConnection !== null;
    }

    /**
     * Live leaf socket OR an armed re-dial — the measured state a leaf
     * connect/disconnect command answers with (review hub__asphp): a connect
     * that only armed the ladder (dial refused while disabled parks it) is
     * honestly "armed", and a disconnect that leaves neither is "down". NOT a
     * hope-flag: every branch of connectToMaster()/disconnectFromMaster()
     * leaves this reading the truth of what THIS owner's instance now does.
     */
    public function linkLiveOrArmed(): bool
    {
        return $this->masterConnection !== null || $this->reconnectScheduled;
    }

    /**
     * Total leaf lifecycle/push calls refused for lacking ownership
     * (diagnostics; review hub__asphp).
     */
    public function getRefusedUnownedCalls(): int
    {
        return $this->refusedUnownedCalls;
    }

    /**
     * Total outbound leaf pushes refused without a live verified link
     * (diagnostics; review hub__asphp).
     */
    public function getRefusedOutboundPushes(): int
    {
        return $this->refusedOutboundPushes;
    }

    /**
     * Get the current session ID (null when not connected).
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Install the shared :8805 frame-budget limiter for the leaf leg.
     *
     * Called once from {@see \Phlix\Hub\Relay\FederationWorker::onWorkerStart()}
     * with the SAME container instance the master-side listener hits — this
     * manager has no container of its own (construction law: nine value
     * collaborators, no service locator), so the wiring crosses at the single
     * process that owns both roles. Passing null is legal and means
     * "unlimited" (fail-open family law).
     */
    public function setReceiveFrameLimiter(?RateLimiterInterface $limiter): void
    {
        $this->receiveFrameLimiter = $limiter;
    }

    /**
     * M-HUB-14 (review hub__exploiter M3, leaf role): budget ONE inbound
     * frame from the master link, charged by the guarded seam only for a
     * PROTOCOL-SHAPED frame (R2 cure: the seam's shape gate runs first, so
     * malformed junk is dropped free and never reaches this budget).
     *
     * Returns true when the frame may proceed. When the shared per-peer
     * budget is exhausted: one warning (carrying the frame's byte size for
     * the flood forensics the master side cannot see from its own bucket)
     * and the link is dropped — the plain-close {@see
     * self::failHelloTimeout()} idiom, not a 1013 reject envelope: 1013 is
     * the INBOUND-server refusal shape on :8805; on the outbound leg the
     * honest refusal is drop-and-backoff, and the drop itself arms the
     * capped ladder (R2 revision: the identity-guarded close arm refuses
     * this slot-first teardown, so the explicit {@see
     * self::scheduleReconnect()} below replaced the historical
     * destroy()-side-effect re-arm; the 60 s cap re-dials precisely when
     * the window rolls over). Self-healing by construction; the budget can
     * never strand the link permanently.
     *
     * Vacuity arms pinned deliberately: null limiter (fail-open) or an
     * empty {@see self::$masterPeerId} (no identity to key — belt for
     * hand-built/ceremony-pre states where the guard cannot run meaningfully)
     * both pass frames through unlimited.
     *
     * @internal Visible for tests (mirrors {@see self::failHelloTimeout()}'s
     *           law); not part of the dial API — production calls come only
     *           from the onMessage closure installed in
     *           {@see self::establishConnection()}.
     */
    public function guardIncomingFrame(string $data): bool
    {
        $limiter = $this->receiveFrameLimiter;
        if ($limiter === null || $this->masterPeerId === '') {
            return true;
        }

        $state = $limiter->hit('federation_frame:' . $this->masterPeerId);
        if (!$state->limited) {
            return true;
        }

        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: inbound master frame budget exceeded — dropping link for backoff',
            [
                'master_peer_id' => $this->masterPeerId,
                'frame_bytes'    => strlen($data),
                'reset_at'       => $state->resetAt,
            ],
        );

        $conn = $this->masterConnection;
        $this->masterConnection = null;
        try {
            $conn?->close();
        } catch (Throwable $e) {
            // Same belt as failHelloTimeout(): a throw while tearing down a
            // dying socket must not escape the frame callback — the ladder
            // is armed by the block right below, whatever the close did.
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: frame-budget link teardown failed',
                ['error' => $e->getMessage()],
            );
        }

        if ($conn !== null) {
            // Self-heal law, R2 revision (review hub__asphp): the drop arms
            // its OWN ladder. The historical re-arm rode the unguarded
            // onClose side-effect; the identity-guarded arms now REFUSE a
            // teardown whose slot was already cleared (this one), so the
            // explicit call replaces the side-effect — reconnectScheduled
            // keeps the whole thing single-shot either way. A null slot was
            // never a socket: no ladder, no side effects (pinned).
            $this->scheduleReconnect();
        }

        return false;
    }

    /**
     * Establish a new WebSocket connection to the master hub.
     *
     * The single AsyncTcpConnection construction site for master dials.
     * TLS rides the workerman idiom parsed upstream in
     * {@see self::buildMasterDialPlan()}: a `ws://` URI (so the real
     * `Workerman\Protocols\Ws` client protocol resolves — there is no
     * `Protocols\Wss` in the vendored version and constructing one is the
     * RuntimeException this seam exists to prevent) plus `transport = 'ssl'`
     * and the strict ssl stream context set BEFORE connect(), so the TCP
     * dial, the TLS handshake (SNI + peer_name from the plan) and the WS
     * upgrade all inherit the same identity.
     *
     * Before any construction, a TLS plan in a process without php-openssl
     * is refused LOUD — one named error line plus a backoff re-arm, never a
     * throw and never the opaque stream error it replaces (owner spec
     * 2026-10-06). Plaintext plans never consult the extension.
     *
     * @param array{uri: string, transport: string, socket_context: array<string, mixed>} $dialPlan
     *        Connection plan from {@see self::buildMasterDialPlan()}.
     * @param array<string, mixed> $hubConfig This hub's configuration row.
     *
     * @return void
     */
    private function establishConnection(array $dialPlan, array $hubConfig): void
    {
        // Prevent multiple simultaneous connection attempts
        if ($this->masterConnection !== null) {
            return;
        }

        // Law 8 (class header): a new generation starts clean — the stream-side
        // twin of the channelVerified reset in sendHubHello(). The manager is a
        // process-lifetime singleton and the FrameDecoder accumulates partial
        // frames, so a link that tore mid-frame left its residue in the shared
        // buffer: the fresh, authenticated connection then spliced stale bytes
        // into its first frames (garbage DATA delivered, real frames
        // swallowed) until an overflow/invalid-type decode cleared the buffer
        // by accident. Resetting HERE covers every teardown path — including
        // closeLinkAndReschedule(), whose comment records that onClose may not
        // have fired — and precedes the first byte the new socket can deliver.
        $this->decoder->reset();

        if ($dialPlan['transport'] === 'ssl' && !$this->tlsExtensionAvailable()) {
            // Owner 2026-10-06 spec: fail LOUD at dial time, not later as an
            // opaque stream error. Log + early return — deliberately NOT a
            // throw — and re-arm the backoff so the chain stays alive exactly
            // like every other dial failure; the link recovers after the
            // operator enables php-openssl and restarts, no explicit trigger.
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: refusing TLS dial — the openssl PHP extension is not '
                . 'loaded, so no TLS master can be reached; enable php-openssl to dial this peer',
            );
            $this->scheduleReconnect();

            return;
        }

        $this->masterConnection = new AsyncTcpConnection($dialPlan['uri'], $dialPlan['socket_context']);

        // M-B (review hub__asphp): pin the BINARY opcode (0x82) on the leaf
        // dial immediately at the SOLE construction site (the same law the
        // dial-construction tripwire enforces). The vendored Ws encoder
        // defaults every send to the TEXT opcode and DATA payloads routinely
        // carry bytes that are not valid UTF-8 — a strict RFC 6455 peer
        // fails such links. The client handshake itself is sent RAW (bypasses
        // the encoder), and the vendor only `??=`-defaults the type after
        // handshake completion, so a pre-set pin governs every later send.
        // Magic vendor @property (see FederationRelayController) — Psalm
        // does not resolve it on this pinned workerman.
        /** @psalm-suppress UndefinedPropertyAssignment */
        $this->masterConnection->websocketType = Ws::BINARY_TYPE_ARRAYBUFFER;

        if ($dialPlan['transport'] !== '') {
            // Must precede connect(): connect() reads the transport to pick
            // the default port (ssl → 443) and checkConnection() runs the
            // TLS handshake off it before any WS bytes flow.
            $this->masterConnection->transport = $dialPlan['transport'];
        }

        $hubConfigFinal = $hubConfig;
        $self = $this;

        // On successful connect — send `hub_hello`, under the S312 barrier:
        // the hello encode/read runs on the resident loop; an escape takes
        // the count=1 worker down, so a failure is contained into the
        // identity-safe link teardown and the capped ladder re-runs the
        // ceremony from scratch.
        $this->masterConnection->onConnect = static function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn
        ) use (
            $hubConfigFinal,
            $self
        ): void {
            try {
                $self->sendHubHello($hubConfigFinal);
            } catch (Throwable $e) {
                $self->containLeafCallback($e, 'hello send');
            }
        };

        // On message — route through the guarded seam: shape gate first
        // (malformed drops are FREE — R2 cure), then the per-peer budget,
        // then parse/dispatch under the S312 barrier. The seam is the law's
        // seat; the closure must stay a bare call to it.
        $this->masterConnection->onMessage = function (
            /** @scrutinizer ignore-unused */ AsyncTcpConnection $conn,
            string $data
        ): void {
            $this->handleInboundLeafFrame($data);
        };

        // On close — route through the identity-guarded handler (review
        // hub__asphp R2 Major): a stale event from a superseded socket is
        // refused THERE, because scheduleReconnect() closes whatever
        // occupies the slot — honoring a dead connection's event would
        // tear down the live successor link.
        $this->masterConnection->onClose = function (AsyncTcpConnection $conn): void {
            $this->handleLeafClose($conn);
        };

        // On error — the same identity law as the close arm (R2 Major twin)
        $this->masterConnection->onError = function (
            AsyncTcpConnection $conn,
            int $code,
            string $reason
        ): void {
            $this->handleLeafError($conn, $code, $reason);
        };

        try {
            $this->masterConnection->connect();
        } catch (Throwable $e) {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->error('FederationPeerManager: failed to connect', [
                'error' => $e->getMessage(),
            ]);
            $this->masterConnection = null;
            $this->scheduleReconnect();
            return;
        }

        // M-6: TCP up ≠ handshake done. If no `hub_hello_ack` lands within the
        // timeout the link is a zombie (isConnected() true, session absent,
        // master already moved on) — drop it so onClose re-runs the handshake.
        $connForTimeout = $this->masterConnection;
        $selfRef = $this;
        $this->ackTimeoutTimerId = Timer::add(
            self::HELLO_ACK_TIMEOUT_SECONDS,
            static function () use ($selfRef, $connForTimeout): void {
                $selfRef->clearAckTimeoutTimerId();
                // S312: the tick rides the resident loop; a throw out of the
                // zombie-trip path would kill the count=1 process.
                try {
                    $selfRef->failHelloTimeout($connForTimeout);
                } catch (Throwable $e) {
                    $selfRef->containLeafCallback($e, 'hello-ack timeout tick');
                }
            },
            [],
            false,
        );
    }

    /**
     * `hub_hello_ack` timeout fired: log and close the half-open connection.
     *
     * @param AsyncTcpConnection $conn Connection that failed to complete the handshake.
     *
     * @internal Visible for tests; not part of the public dial API.
     */
    public function failHelloTimeout(AsyncTcpConnection $conn): void
    {
        if ($this->sessionId !== null || $this->masterConnection !== $conn) {
            return; // Handshake completed (or connection already replaced).
        }

        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: HELLO_ACK timeout — dropping half-open connection',
            ['timeout_seconds' => self::HELLO_ACK_TIMEOUT_SECONDS],
        );

        try {
            $conn->close();
        } catch (Throwable) {
            $this->masterConnection = null;
            $this->scheduleReconnect();
        }
    }

    /**
     * Clear the ack-timeout timer id slot once the one-shot fires.
     *
     * @internal
     */
    public function clearAckTimeoutTimerId(): void
    {
        $this->ackTimeoutTimerId = null;
    }

    /**
     * Send the `hub_hello` JSON text frame to the master hub.
     *
     * @param array<string, mixed> $hubConfig This hub's configuration.
     *
     * @return void
     */
    private function sendHubHello(array $hubConfig): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        // A new ceremony starts untrusted (H-4): nothing carried over from
        // the previous handshake — especially its verified stamp.
        $this->channelVerified = false;

        /** @var string $hubId */
        $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
        /** @var string $hubName */
        $hubName = is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : '';
        /** @var string $publicKey */
        $publicKey = is_string($hubConfig['public_key'] ?? null) ? $hubConfig['public_key'] : '';

        $payload = json_encode([
            'type' => 'hub_hello',
            FederationHandshake::PROTOCOL_VERSION_FIELD => FederationHandshake::PROTOCOL_VERSION,
            'hub_id' => $hubId,
            'hub_name' => $hubName,
            'public_key' => $publicKey,
            'role' => 'leaf',
            'capabilities' => ['library_shares', 'relay', 'admin_delegation'],
        ], JSON_THROW_ON_ERROR);

        $this->trackOutboundSend($this->masterConnection->send($payload), 'hub_hello');
    }

    /**
     * Handle an incoming WebSocket message (text JSON or binary frame).
     *
     * @param string $data Raw frame payload.
     *
     * @return void
     */
    private function handleMessage(string $data): void
    {
        // Detect text vs binary frame by checking for valid JSON UTF-8
        if ($this->isTextFrame($data)) {
            $this->handleTextFrame($data);
        } else {
            $this->handleBinaryFrame($data);
        }
    }

    /**
     * The guarded inbound-frame seam of the leaf link (review hub__asphp,
     * R1 Major — fourth arm of the M-A finding; R2 Major — charge order):
     * VALIDATE-THEN-CHARGE, then dispatch under the S312/M-HUB-03 barrier.
     *
     * R1 shaped the barrier; R2 shaped the budget. A frame the protocol can
     * never dispatch is dropped FREE before the limiter is consulted:
     * charging malformed junk handed a hostile peer the victim's own
     * per-peer budget as the lever to sever a healthy verified link
     * (DoS amplification). Shape is settled without touching the stateful
     * FrameDecoder buffer, so the validate-first reorder moves no expensive
     * work ahead of a gate.
     *
     * Everything the fan-out reaches is live DB work on the count=1 :8805
     * loop — `handleHelloAck` reads and writes the peer/session stores, the
     * share path persists offers — and ONE throwing read used to run
     * Worker::stopAll(250) through the process, dropping every other link
     * and every verified stamp with it. A throw here is LINK-scoped damage:
     * contain it into {@see self::containLeafCallback()}.
     *
     * @internal Public for the barrier harness; the socket callback is the
     *           production caller.
     *
     * @param string $data Raw frame payload.
     *
     * @return void
     */
    public function handleInboundLeafFrame(string $data): void
    {
        if (!$this->isProtocolShapedFrame($data)) {
            return;
        }

        if (!$this->guardIncomingFrame($data)) {
            return;
        }

        try {
            $this->handleMessage($data);
        } catch (Throwable $e) {
            $this->containLeafCallback($e, 'inbound frame handler');
        }
    }

    /**
     * Cheap, non-consuming shape gate of the validate-then-charge law
     * (review hub__asphp R2 Major).
     *
     * Text leg: exactly what {@see self::handleTextFrame()} requires to
     * attempt dispatch — JSON (depth parity) decoding to an array carrying
     * a string `type`. An array with an UNKNOWN type is well-formed:
     * forward-compatibility means dispatchability is not shape, and the
     * budget treats it like every other real frame.
     *
     * Binary leg: the envelope head law the classifier itself states —
     * big-endian sequence head byte `0x00`, minimum 7-byte header. The
     * decoder is deliberately NOT consulted: its buffer accumulates partial
     * frames, so a probe would CONSUME the bytes it screens. Frames past
     * the head gate keep the historical charge-before-decode posture,
     * honestly bounded: their failure leg (InvalidFrameType) is
     * self-limiting — the first undecodable frame recycles the link.
     *
     * Vacuous inputs (empty string, non-UTF-8 junk, short bodies, wrong
     * head) are all refused here, before any parse or charge.
     */
    private function isProtocolShapedFrame(string $data): bool
    {
        if ($this->isTextFrame($data)) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return false;
            }

            return is_array($decoded) && is_string($decoded['type'] ?? null);
        }

        return strlen($data) >= 7 && $data[0] === "\x00";
    }

    /**
     * One contained leaf-link callback failure: a single attributed error
     * line (message AND exception class), then the identity-safe teardown —
     * timers cancelled, verified latch dropped, poisoned socket closed,
     * capped ladder re-armed. The worker SURVIVES; only the link that threw
     * is recycled.
     *
     * @param Throwable $e       The escaped throw being contained.
     * @param string    $surface Human name of the failing callback.
     *
     * @return void
     */
    private function containLeafCallback(Throwable $e, string $surface): void
    {
        LoggerFactory::get(LogChannels::RELAY)->error(
            'FederationPeerManager: ' . $surface . ' threw; containing leaf link; the worker survived',
            ['error' => $e->getMessage(), 'class' => get_class($e)],
        );

        $this->closeLinkAndReschedule();
    }

    /**
     * Identity-guarded close arm of the leaf link (review hub__asphp,
     * R2 Major): act ONLY when the event's connection is still the one this
     * process tracks. scheduleReconnect() closes whatever occupies the
     * slot, so a late close from a superseded socket would tear down the
     * successor link — the same cross-generation law the master-side
     * hello-auth guard states: capture the object, compare by identity, a
     * stale carrier is inert. The retained backstop catch is the barrier
     * pin against a future throwing callee.
     *
     * @internal Public for the barrier harness; the socket callback is the
     *           production caller.
     *
     * @param AsyncTcpConnection $conn The connection whose close event fired.
     *
     * @return void
     */
    public function handleLeafClose(AsyncTcpConnection $conn): void
    {
        if ($conn !== $this->masterConnection) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: stale close event on a superseded socket — refused, live link intact',
            );

            return;
        }

        try {
            $this->scheduleReconnect();
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: close callback threw; the worker survived',
                ['error' => $e->getMessage(), 'class' => get_class($e)],
            );
        }
    }

    /**
     * Identity-guarded error arm (R2 Major twin of {@see
     * self::handleLeafClose()}): a stale error event may neither log as if
     * the live link errored — payload and all — nor schedule the
     * slot-owning teardown. Live events keep the historical posture
     * (attributed warning, then the capped ladder) under the same backstop
     * law.
     *
     * @internal Public for the barrier harness.
     *
     * @param AsyncTcpConnection $conn   The connection whose error event fired.
     * @param int                $code   Vendor error code.
     * @param string             $reason Vendor error reason.
     *
     * @return void
     */
    public function handleLeafError(AsyncTcpConnection $conn, int $code, string $reason): void
    {
        if ($conn !== $this->masterConnection) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: stale error event on a superseded socket — refused, live link intact',
            );

            return;
        }

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->warning('FederationPeerManager: connection error', [
            'code' => $code,
            'reason' => $reason,
        ]);

        try {
            $this->scheduleReconnect();
        } catch (Throwable $e) {
            $logger->error(
                'FederationPeerManager: error callback threw; the worker survived',
                ['error' => $e->getMessage(), 'class' => get_class($e)],
            );
        }
    }

    /**
     * Handle an incoming text (JSON) frame.
     *
     * @param string $data Raw JSON string.
     *
     * @return void
     */
    private function handleTextFrame(string $data): void
    {
        try {
            /** @var array<string, mixed>|null $msg */
            $msg = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (!is_array($msg)) {
            return;
        }

        /** @var string $type */
        $type = is_string($msg['type'] ?? null) ? $msg['type'] : '';

        match ($type) {
            'hub_hello_ack' => $this->handleHelloAck($msg),
            default => null,
        };
    }

    /**
     * Handle `hub_hello_ack` — verify the master's signature, then establish
     * the session, answer with our own `hub_hello_auth` proof, start heartbeat.
     *
     * M-8: the ack's `master_hub_id` is cross-checked against the master
     * peer's bound identity (`leaf_hub_id`, migration 046). A mismatch means
     * we are talking to a hub that is not the one this peer row represents —
     * the link is refused, never silently adopted. Unbound → bind on first
     * use.
     *
     * H-4: the ack is trusted ONLY after its Ed25519 `signature` verifies
     * over the canonical {session_id, master_hub_id, nonce} string against
     * the master peer row's registered public key. Unsigned, malformed or
     * foreignly-signed acks are refused before any of the ack's claims
     * (identity binding included) touch state — close + audit-fail + backoff.
     *
     * @param array<string, mixed> $msg Decoded JSON payload.
     *
     * @return void
     */
    private function handleHelloAck(array $msg): void
    {
        $this->cancelAckTimeoutTimer();

        /** @var mixed $sessionIdRaw */
        $sessionIdRaw = $msg['session_id'] ?? null;
        $sessionId = is_string($sessionIdRaw) ? $sessionIdRaw : null;

        /** @var mixed $rawMasterHubId */
        $rawMasterHubId = $msg['master_hub_id'] ?? null;
        $ackHubId = is_string($rawMasterHubId) ? $rawMasterHubId : '';

        /** @var mixed $rawNonce */
        $rawNonce = $msg['nonce'] ?? null;
        $nonce = is_string($rawNonce) ? $rawNonce : '';

        /** @var mixed $rawSignature */
        $rawSignature = $msg['signature'] ?? null;
        $signature = is_string($rawSignature) ? $rawSignature : '';

        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig === null) {
            return;
        }

        /** @var string $hubId */
        $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';

        if ($this->masterPeerId === '') {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: HELLO_ACK without a dialed peer — ignored',
            );
            $this->audit->logHubConnect($hubId, 'master', $ackHubId, false);
            return;
        }

        $masterPeer = $this->hubRepo->getPeerById($this->masterPeerId);
        if ($masterPeer === null) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'FederationPeerManager: HELLO_ACK for unknown peer — closing',
                ['master_peer_id' => $this->masterPeerId],
            );
            $this->closeLinkAndReschedule();
            return;
        }

        /** @var string $boundHubId */
        $boundHubId = is_string($masterPeer['leaf_hub_id'] ?? null) ? $masterPeer['leaf_hub_id'] : '';
        /** @var string $peerName */
        $peerName = is_string($masterPeer['name'] ?? null) ? $masterPeer['name'] : 'master';
        /** @var string $masterPublicKey */
        $masterPublicKey = is_string($masterPeer['public_key'] ?? null) ? $masterPeer['public_key'] : '';

        // Minors campaign M-HUB-11: version gate BEFORE proof parsing — a
        // master speaking a protocol this build does not implement is refused
        // outright (the refusal is about the FRAME, not any claim inside it),
        // with the same loud close + audit + backoff as every other ack
        // refusal. An absent field is a legacy v1 master and proceeds.
        /** @var mixed $rawAckVersion */
        $rawAckVersion = $msg[FederationHandshake::PROTOCOL_VERSION_FIELD] ?? null;
        if (!FederationHandshake::acceptsProtocolVersion($rawAckVersion)) {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'protocol_version_mismatch');

            return;
        }

        // H-4: proof before claims. A signed handshake is the ONLY thing that
        // makes this ack the master's word — until it verifies, not one of
        // its assertions (identity binding included) may mutate state.
        if ($sessionId === null || $sessionId === '' || $nonce === '' || $signature === '') {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_missing_proof_fields');
            return;
        }

        $canonical = FederationHandshake::helloAckCanonical($sessionId, $ackHubId, $nonce);
        if (!FederationHandshake::verify($canonical, $signature, $masterPublicKey)) {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_signature_invalid');
            return;
        }

        if ($boundHubId !== '' && $ackHubId !== '' && $boundHubId !== $ackHubId) {
            $this->refuseHelloAck($hubId, $peerName, $ackHubId, 'hello_ack_identity_mismatch');
            return;
        }

        if ($boundHubId === '' && $ackHubId !== '') {
            $this->hubRepo->setPeerLeafHubId($this->masterPeerId, $ackHubId);
        }

        $this->sessionId = $sessionId;

        // Register the LOCAL mirror session row under the dialed peer id.
        // (The old code re-read getConnectedPeers() — empty for a 'pending'
        // master row at first ack — so leaf-side session bookkeeping never
        // existed at bootstrap. H-2/C-1(1).)
        $this->sessions->registerSession($this->masterPeerId);

        $this->audit->logHubConnect($hubId, $peerName, $ackHubId, true);

        // Reset backoff on successful handshake
        $this->reconnectDelaySeconds = 5;
        $this->reconnectScheduled = false;

        // H-4 leaf leg: prove possession of THIS hub's registered private key
        // over the same session nonce, then open the channel for payloads.
        $this->sendHelloAuth($hubId, $sessionId, $nonce);
        $this->channelVerified = true;

        // Review hub__asphp: the leaf side of the hello-time replay the master
        // has always performed (pushLibrarySharesToLeaf). Shares created while
        // this link was down were pushed into a null socket and gone forever;
        // now the active set converges the moment the ceremony verifies. After
        // the stamp, before the heartbeat: the replay rides the just-opened
        // channel, and a replay failure must never gate the heartbeat itself
        // (fail-soft law inside the method).
        $this->replayActiveOutgoingShares($hubConfig);

        // Start heartbeat timer
        $this->startHeartbeatTimer();
    }

    /**
     * Loud, audited refusal of a `hub_hello_ack` that failed the H-4 proof or the
     * M-8 identity cross-check — drops the link and arms exponential backoff.
     *
     * @param string $hubId    This leaf's hub UUID.
     * @param string $peerName Master peer display name.
     * @param string $ackHubId Master hub id claimed by the ack.
     * @param string $reason   Machine-readable refusal reason.
     */
    private function refuseHelloAck(string $hubId, string $peerName, string $ackHubId, string $reason): void
    {
        LoggerFactory::get(LogChannels::RELAY)->error(
            'FederationPeerManager: HELLO_ACK refused — ' . $reason,
            [
                'peer_id' => $this->masterPeerId,
                'ack_hub_id' => $ackHubId,
            ],
        );
        $this->audit->logHubConnect($hubId, $peerName, $ackHubId, false, $reason);
        $this->closeLinkAndReschedule();
    }

    /**
     * Send `hub_hello_auth` — the leaf's proof-of-key over the canonical
     * {session_id, nonce, leaf_hub_id} string (H-4 ceremony, leaf leg).
     *
     * The master verifies this against OUR public key as registered on its
     * peer row; only then does it flip the channel to VERIFIED and release
     * share pushes. Signing the SAME canonical bytes the master reconstructs
     * is guaranteed by the shared {@see FederationHandshake} helper — the two
     * ends cannot drift their wire formats.
     *
     * @param string $ownHubId  This leaf's hub UUID.
     * @param string $sessionId Session UUID from the verified ack.
     * @param string $nonce     Nonce from the verified ack (echoed in proof).
     */
    private function sendHelloAuth(string $ownHubId, string $sessionId, string $nonce): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $signature = FederationHandshake::sign(
            FederationHandshake::helloAuthCanonical($sessionId, $nonce, $ownHubId),
            $keyPair['private'],
        );

        $payload = json_encode([
            'type' => 'hub_hello_auth',
            'leaf_hub_id' => $ownHubId,
            'session_id' => $sessionId,
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR);

        $this->trackOutboundSend($this->masterConnection->send($payload), 'hub_hello_auth');
    }

    /**
     * Close the current leaf→master link and let the reconnect path retry
     * (used by the ack identity refusal and the `hub_hello_ack` timeout).
     *
     * @return void
     */
    private function closeLinkAndReschedule(): void
    {
        $this->cancelAckTimeoutTimer();
        $this->cancelHeartbeatTimer();
        $this->channelVerified = false;

        if ($this->masterConnection !== null) {
            $conn = $this->masterConnection;
            $this->masterConnection = null;
            try {
                $conn->close();
            } catch (Throwable) {
                // Already gone.
            }
            // onClose may or may not have fired depending on where the close
            // happened in the lifecycle — schedule defensively; the
            // reconnectScheduled flag keeps it single-shot.
            $this->scheduleReconnect();
        }
    }

    /**
     * Handle an incoming binary relay frame.
     *
     * @param string $data Raw binary frame data.
     *
     * @return void
     */
    private function handleBinaryFrame(string $data): void
    {
        try {
            $frame = $this->decoder->decode($data);
        } catch (InvalidFrameTypeException $e) {
            // Undecodable frame or a decode-buffer overflow (H-R7) from the
            // master hub. Escaping here would fatal out of the Workerman message
            // callback; close the connection cleanly and let the reconnect timer
            // re-establish a known-good session.
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: undecodable frame from master, closing connection',
                ['error' => $e->getMessage()],
            );
            try {
                $this->masterConnection?->close();
            } catch (Throwable) {
                // Connection already gone — no-op.
            }
            return;
        }
        if ($frame === null) {
            return;
        }

        try {
            $type = RelayFrameType::fromValue($frame->type->value);
        } catch (Throwable) {
            return;
        }

        match ($type) {
            RelayFrameType::HEARTBEAT => $this->handleHeartbeat(),
            RelayFrameType::DISCONNECTED => $this->handleDisconnected($frame->payload),
            RelayFrameType::DATA => $this->handleDataFrame($frame->payload),
            default => null,
        };
    }

    /**
     * Handle a heartbeat — respond with our own heartbeat.
     *
     * @return void
     */
    private function handleHeartbeat(): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $frame = $this->encoder->encode(RelayFrameType::HEARTBEAT, 0, '');
        $this->trackOutboundSend($this->masterConnection->send($frame), 'heartbeat');

        // H-2: the LOCAL mirror session row is addressed by peer id — the
        // master's session UUID (kept in $this->sessionId for diagnostics)
        // is a row id in the MASTER's database and matches nothing here.
        if ($this->masterPeerId !== '') {
            try {
                $this->sessions->touchHeartbeatByPeerId($this->masterPeerId);
            } catch (Throwable) {
                // Session not found — ignore
            }
        }
    }

    /**
     * Handle a DISCONNECTED (0x07) frame from master.
     *
     * @param string $payload Frame payload (JSON {reason}).
     *
     * @return void
     */
    private function handleDisconnected(string $payload): void
    {
        $reason = 'master_disconnect';
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($payload, true, 2, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && isset($decoded['reason'])) {
                /** @var mixed $rawReason */
                $rawReason = $decoded['reason'];
                if (is_string($rawReason)) {
                    $reason = $rawReason;
                }
            }
        } catch (Throwable) {
            // Use default
        }

        $this->cancelHeartbeatTimer();

        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->channelVerified = false;
        $this->scheduleReconnect();

        // Audit log
        $hubConfig = $this->hubRepo->getHubConfig();
        if ($hubConfig !== null) {
            /** @var string $hubId */
            $hubId = is_string($hubConfig['id'] ?? null) ? $hubConfig['id'] : '';
            /** @var string $peerName */
            $peerName = is_string($hubConfig['name'] ?? null) ? $hubConfig['name'] : 'master';
            $this->audit->logHubDisconnect($hubId, $peerName, $reason);
        }
    }

    /**
     * Handle incoming DATA frame — contains library share updates or admin delegations.
     *
     * @param string $payload Raw JSON payload.
     *
     * @return void
     */
    private function handleDataFrame(string $payload): void
    {
        // H-4 unverified-channel gate (leaf side): a live socket alone proves
        // nothing — offers, revocations and admin-delegation payloads are all
        // dropped until the signed handshake ceremony completes.
        if (!$this->channelVerified) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: DATA frame dropped — channel not yet verified',
                ['peer_id' => $this->masterPeerId],
            );
            $this->audit->logFailedAuth('federation_data_frame_before_verification', [
                'peer_id' => $this->masterPeerId,
            ]);
            return;
        }

        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        // Handle library share updates (incoming offers from master).
        if (isset($data['shares']) && is_array($data['shares'])) {
            /** @var mixed $share */
            foreach ($data['shares'] as $share) {
                if (!is_array($share)) {
                    continue;
                }

                $offer = $this->rebaseOfferIdentity($share);
                if ($offer === null) {
                    continue;
                }

                $this->libraryShares->handleIncomingOffer($offer);
            }
        }

        // Handle library share revocation notification
        if (isset($data['share_id']) && is_string($data['share_id'])) {
            $this->handleLibraryShareRevoked($data['share_id']);
        }

        // Handle admin delegation push
        if (isset($data['user_id']) && isset($data['action'])) {
            $this->handleAdminDelegation($data);
        }
    }

    /**
     * Rewrite a master-pushed offer's wire `peer_id` to this database's
     * local FK (the master peer row we dialed) — M-5.
     *
     * The wire value is the master's OWN hub id; it is used only to
     * cross-check against the bound identity from `hub_hello_ack`. A mismatch is
     * refused loudly instead of writing an offer against the wrong peer row.
     *
     * @param array<array-key, mixed> $offer Offer as received (JSON-decoded,
     *                                      so keys are array-key until the
     *                                      repository re-parses each field).
     *
     * @return array<array-key, mixed>|null Rebasing offer, or null to drop it.
     */
    private function rebaseOfferIdentity(array $offer): ?array
    {
        if ($this->masterPeerId === '') {
            return null;
        }

        $masterPeer = $this->hubRepo->getPeerById($this->masterPeerId);
        if ($masterPeer === null) {
            return null;
        }

        /** @var string $boundHubId */
        $boundHubId = is_string($masterPeer['leaf_hub_id'] ?? null) ? $masterPeer['leaf_hub_id'] : '';
        /** @var mixed $wirePeerId */
        $wirePeerId = $offer['peer_id'] ?? null;

        if (is_string($wirePeerId) && $boundHubId !== '' && $wirePeerId !== $boundHubId) {
            LoggerFactory::get(LogChannels::RELAY)->warning(
                'FederationPeerManager: share offer skipped — peer_id mismatch',
                [
                    'peer_id' => $this->masterPeerId,
                    'bound_hub_id' => $boundHubId,
                    'wire_peer_id' => $wirePeerId,
                ],
            );
            return null;
        }

        // Stamp the local FK before returning — never forward the wire value
        // (M-5). The repository parses every field defensively, so the map
        // keeps its array-key shape through this boundary.
        $offer['peer_id'] = $this->masterPeerId;

        return $offer;
    }

    /**
     * Handle an incoming library share revoked notification from master.
     *
     * M-5: the master's outgoing-share row id IS the offer id it pushed to
     * us, so revocation drops the local offer row outright (the pending/
     * accepted/rejected enum has no 'revoked' state to degrade into).
     *
     * @param string $shareId Share UUID that was revoked.
     *
     * @return void
     */
    private function handleLibraryShareRevoked(string $shareId): void
    {
        // Scoped to the master's local peer row — offers from the master are
        // stamped with it at rebase time (:931), and a wire id from any OTHER
        // peer's row space must never delete this hub's offer rows.
        $this->libraryShares->deleteIncomingOffer($shareId, $this->masterPeerId);
    }

    /**
     * Handle an incoming admin delegation push from master (R17-07: validated
     * before any write).
     *
     * Wire-law for this RESERVED envelope (docs/websockets.md `:8805` DATA
     * table): the frame's `peer_id` carries the MASTER's own hub uuid — the
     * identity the signed `hub_hello_ack` ceremony bound onto the master peer row —
     * and it is used ONLY as a cross-check. Every write stamps the LOCAL
     * channel peer row id (`masterPeerId`), never a frame-carried string, so
     * a delegation can neither bind to nor dangle away from a row this
     * database did not authenticate. Validation-before-write, in order:
     *   1. shape — user_id / peer_id / action must be non-empty strings and
     *      action must be 'grant' or 'revoke';
     *   2. channel — the dialed master peer row must exist AND carry its
     *      bound hub identity. Unlike the M-5 offer rebase (which tolerates
     *      an unbound row), a privilege write refuses an unbound channel
     *      outright — a verified ceremony always leaves a binding behind
     *      (handleHelloAck persists it before setting channelVerified), so an
     *      unbound row here is an anomalous state, not a bootstrap race;
     *   3. identity — the frame peer_id must equal that bound hub uuid;
     *   4. user — user_id must resolve in THIS hub's `users` table via the
     *      localUserProbe seam (the column has no FK, so a dangling id would
     *      otherwise persist silently; unwired probe fails closed).
     * Any refusal logs + audits a snake_case reason and persists nothing.
     *
     * Write-only feature today, honestly: the master-side producer of this
     * envelope is unimplemented (FederationMasterPusher has no delegation
     * push) and FederationAdminDelegationRepository::isUserAdminOnPeer has
     * zero request-path consumers, so nothing reads these rows into an
     * authorization decision yet. The law above is the contract the rows
     * will honour the day both ends light up; if a real producer ever adopts
     * peer-namespaced user ids instead of local ones, step 4 is the clause to
     * revisit — deliberately choosing local resolution per the owner ruling
     * that minted this lane (2026-10-15).
     *
     * @param array<string, mixed> $data Payload with user_id, peer_id, action.
     *
     * @return void
     */
    private function handleAdminDelegation(array $data): void
    {
        /** @var string $userId */
        $userId = is_string($data['user_id'] ?? null) ? $data['user_id'] : '';
        /** @var string $peerId */
        $peerId = is_string($data['peer_id'] ?? null) ? $data['peer_id'] : '';
        /** @var string $action */
        $action = is_string($data['action'] ?? null) ? $data['action'] : '';

        if ($userId === '' || $peerId === '') {
            $this->refuseAdminDelegation('admin_delegation_missing_fields', ['action' => $action]);

            return;
        }

        if ($action !== 'grant' && $action !== 'revoke') {
            $this->refuseAdminDelegation('admin_delegation_unknown_action', [
                'peer_id' => $peerId,
                'user_id' => $userId,
            ]);

            return;
        }

        if ($this->masterPeerId === '') {
            $this->refuseAdminDelegation('admin_delegation_no_bound_peer', [
                'wire_peer_id' => $peerId,
                'user_id' => $userId,
            ]);

            return;
        }

        $masterPeer = $this->hubRepo->getPeerById($this->masterPeerId);
        if ($masterPeer === null) {
            $this->refuseAdminDelegation('admin_delegation_no_bound_peer', [
                'peer_id' => $this->masterPeerId,
                'user_id' => $userId,
            ]);

            return;
        }

        /** @var string $boundHubId */
        $boundHubId = is_string($masterPeer['leaf_hub_id'] ?? null) ? $masterPeer['leaf_hub_id'] : '';
        if ($boundHubId === '') {
            $this->refuseAdminDelegation('admin_delegation_no_bound_peer', [
                'peer_id' => $this->masterPeerId,
                'user_id' => $userId,
            ]);

            return;
        }

        if ($peerId !== $boundHubId) {
            $this->refuseAdminDelegation('admin_delegation_peer_id_mismatch', [
                'peer_id' => $this->masterPeerId,
                'bound_hub_id' => $boundHubId,
                'wire_peer_id' => $peerId,
            ]);

            return;
        }

        $probe = $this->localUserProbe;
        if (!is_callable($probe)) {
            $this->refuseAdminDelegation('admin_delegation_user_probe_unwired', [
                'peer_id' => $this->masterPeerId,
                'user_id' => $userId,
            ]);

            return;
        }

        if ($probe($userId) !== true) {
            $this->refuseAdminDelegation('admin_delegation_unknown_user', [
                'peer_id' => $this->masterPeerId,
                'user_id' => $userId,
            ]);

            return;
        }

        // Past this line the payload is bound to THIS authenticated channel:
        // every write and audit stamp the local peer row id — the frame's
        // identity strings never reach the database.
        if ($action === 'grant') {
            $this->adminDel->grant($this->generateUuid(), $this->masterPeerId, $userId);
            $this->audit->logAdminDelegation($this->masterPeerId, $userId, 'grant');

            return;
        }

        $delegations = $this->adminDel->getActiveDelegationsForUser($userId);
        foreach ($delegations as $d) {
            /** @var string $dId */
            $dId = is_string($d['id'] ?? null) ? $d['id'] : '';
            /** @var string $dPeerId */
            $dPeerId = is_string($d['peer_id'] ?? null) ? $d['peer_id'] : '';
            if ($dId !== '' && $dPeerId === $this->masterPeerId) {
                $this->adminDel->revoke($dId);
                $this->audit->logAdminDelegation($this->masterPeerId, $userId, 'revoke');
                break;
            }
        }
    }

    /**
     * Loud, audited refusal of an admin-delegation payload that failed the
     * validation-before-write law — mirrors refuseHelloAck()'s posture at the
     * payload layer: RELAY warning + snake_case audit reason, zero persistence.
     *
     * @param array<string, string> $context Machine-readable refusal detail.
     */
    private function refuseAdminDelegation(string $reason, array $context): void
    {
        LoggerFactory::get(LogChannels::RELAY)->warning(
            'FederationPeerManager: admin delegation refused — ' . $reason,
            $context,
        );
        $this->audit->logFailedAuth($reason, $context);
    }

    /**
     * Start the heartbeat timer — sends the generic HEARTBEAT (0x06) every 15 seconds.
     *
     * @return void
     */
    private function startHeartbeatTimer(): void
    {
        $this->cancelHeartbeatTimer();

        $self = $this;
        $this->heartbeatTimerId = Timer::add(
            15,
            static function () use ($self): void {
                // S312: the tick rides the resident loop; a throwing
                // encode/send/store read is contained into the link
                // teardown (the ladder re-establishes), never into
                // Worker::stopAll(250).
                try {
                    $self->sendHeartbeat();
                } catch (Throwable $e) {
                    $self->containLeafCallback($e, 'heartbeat tick');
                }
            },
        );
    }

    /**
     * Send a generic HEARTBEAT (0x06) binary frame to the master hub.
     *
     * Liveness on the federation link rides the envelope-neutral HEARTBEAT
     * frame, not the retired HUB_HEARTBEAT (0x0B) type — see the
     * "Federation envelope law" paragraph of docs/websockets.md (the
     * retired 0x09-0x0F wire types and HEARTBEAT 0x06 carrying liveness
     * for every surface are stated there, with the 0x06 row in that doc's
     * "Frame type catalog" table) and the @deprecated annotations on
     * RelayFrameType::HUB_HEARTBEAT in phlix-shared.
     *
     * @return void
     */
    private function sendHeartbeat(): void
    {
        if ($this->masterConnection === null) {
            return;
        }

        $frame = $this->encoder->encode(RelayFrameType::HEARTBEAT, 0, '');
        $this->trackOutboundSend($this->masterConnection->send($frame), 'heartbeat');

        // H-2: refresh the LOCAL mirror session row by peer id (see
        // handleHeartbeat for why the master's session UUID matches nothing
        // in this database).
        if ($this->masterPeerId !== '') {
            try {
                $this->sessions->touchHeartbeatByPeerId($this->masterPeerId);
            } catch (Throwable) {
                // Ignore
            }
        }
    }

    /**
     * Cancel the active heartbeat timer.
     *
     * @return void
     */
    private function cancelHeartbeatTimer(): void
    {
        if ($this->heartbeatTimerId !== null) {
            try {
                Timer::del($this->heartbeatTimerId);
            } catch (Throwable) {
                // Already cancelled or invalid
            }
            $this->heartbeatTimerId = null;
        }
    }

    /**
     * Cancel the armed `hub_hello_ack` timeout (handshake completed or link gone).
     *
     * @return void
     */
    private function cancelAckTimeoutTimer(): void
    {
        if ($this->ackTimeoutTimerId !== null) {
            try {
                Timer::del($this->ackTimeoutTimerId);
            } catch (Throwable) {
                // One-shot already fired or invalid.
            }
            $this->ackTimeoutTimerId = null;
        }
    }

    /**
     * Schedule a reconnection attempt with exponential backoff.
     *
     * Backoff sequence: 5, 10, 20, 40, max RECONNECT_DELAY_CAP_SECONDS.
     *
     * M-7: a DELIBERATE disconnectFromMaster() closes the socket, which fires
     * this path through onClose — while the intentional flag is set, no
     * reconnect may be armed, and any already-armed one-shot is cancelled
     * when it finally fires.
     *
     * Disabled-window park (BEHAVIOR lane): when the tick fires while
     * `federation.enabled` is false, the callback re-arms at the cap instead
     * of letting the gated dial end the chain — one wake per cap, zero TCP
     * attempts, zero FEDERATION-repository reads. Precision (2026-10-05
     * review): each wake does perform ONE live `federation.enabled`
     * settings-store read (the resolver's per-call `hub_settings` SELECT —
     * uncached by design; the only memoized half is the config-file
     * default), so "zero repository reads" was never literally true of the
     * settings store, only of the peer/hub transport path. The ENABLED-path
     * ordering inside the
     * callback (clear flags → ladder increment → intentional check → dial)
     * is byte-preserved; the disabled check sits strictly between the M-7
     * guard and the dial, so every previously-reachable enabled sequence
     * still executes exactly the same statements in the same order.
     *
     * @return void
     */
    private function scheduleReconnect(): void
    {
        if ($this->intentionalDisconnect) {
            return;
        }

        // Prevent duplicate scheduling
        if ($this->reconnectScheduled) {
            return;
        }

        $this->reconnectScheduled = true;
        $this->cancelHeartbeatTimer();
        $this->cancelAckTimeoutTimer();

        // Clean up existing connection
        if ($this->masterConnection !== null) {
            try {
                $this->masterConnection->close();
            } catch (Throwable) {
                // Ignore
            }
            $this->masterConnection = null;
        }

        $this->sessionId = null;
        $this->channelVerified = false;

        $delay = $this->reconnectDelaySeconds;
        $self = $this;

        try {
            $this->reconnectTimerId = Timer::add(
                $delay,
                static function () use ($self, $delay): void {
                    $self->reconnectTimerId = null;
                    $self->reconnectScheduled = false;
                    $self->reconnectDelaySeconds = min($delay * 2, self::RECONNECT_DELAY_CAP_SECONDS);

                    if ($self->intentionalDisconnect) {
                        return; // Operator dropped the link while we were waiting.
                    }

                    if (!$self->federationEnabled()) {
                        // Kill switch is down: park the chain at the cap rather
                        // than letting the gated dial be this chain's last tick.
                        $self->reconnectDelaySeconds = self::RECONNECT_DELAY_CAP_SECONDS;
                        $self->announceDisabledReconnectHold();
                        $self->scheduleReconnect();

                        return;
                    }

                    $self->reconnectHoldAnnounced = false;
                    // The dial may THROW before any socket exists (the
                    // establishConnection constructor line is outside its
                    // own catch — e.g. a malformed peer URL; the https
                    // mapping since rides transport=ssl instead) or
                    // inside the guard chain (a settings/DB read failing at
                    // boot). An escape here dies the chain at best and
                    // crash-loops the worker at worst, so a throw re-arms
                    // the ladder instead of ending it. The enabled
                    // statement order inside this callback is unchanged.
                    try {
                        $self->connectToMaster();
                    } catch (Throwable $e) {
                        LoggerFactory::get(LogChannels::RELAY)->error(
                            'FederationPeerManager: reconnect tick threw — chain re-armed',
                            ['error' => $e->getMessage()],
                        );
                        $self->scheduleReconnect();
                    }
                },
                [],
                false,
            );
        } catch (Throwable $e) {
            // No Workerman timer runtime: roll the in-flight latch back before
            // propagating, so a swallowed arm attempt (the gated-dial site in
            // connectToMaster) can never strand the chain with
            // reconnectScheduled stuck true and no timer behind it.
            $this->reconnectScheduled = false;
            $this->reconnectTimerId = null;

            throw $e;
        }
    }

    /**
     * Announce — once per disabled window — that the reconnect chain is
     * parked at the capped backoff until federation is re-enabled.
     *
     * Deliberately a plain RELAY-channel log line, not an audit entry: the
     * enabled path's audit trail is untouched, the inbound
     * `FEDERATION_DISABLED` refusal law lives in FederationFrameHandler, and
     * the per-cap wake cadence must not spam. The reconnectHoldAnnounced
     * throttle resets when a tick proceeds past the gate (real dial attempt)
     * or the chain is torn down deliberately.
     *
     * @return void
     */
    private function announceDisabledReconnectHold(): void
    {
        if ($this->reconnectHoldAnnounced) {
            return;
        }

        $this->reconnectHoldAnnounced = true;

        LoggerFactory::get(LogChannels::RELAY)->info(
            'FederationPeerManager: reconnect chain parked — federation disabled; re-checking every '
            . self::RECONNECT_DELAY_CAP_SECONDS . 's, dials on the next tick after re-enable',
        );
    }

    /**
     * Determine whether an incoming frame payload is a text (JSON) frame.
     *
     * Text frames are valid UTF-8 JSON objects/arrays (the handshake
     * messages are all JSON objects). The assoc flag is load-bearing:
     * `json_decode(..., false)` hands back a stdClass for every object and
     * an array-or-scalar acceptance test then silently misroutes EVERY
     * hub_hello_ack to the binary decoder — the real-TLS E2E
     * (FederationMasterTlsDialTest) is what surfaced that.
     *
     * @param string $data Raw frame payload.
     *
     * @return bool True for text/JSON frames, false for binary.
     */
    private function isTextFrame(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        $firstByte = ord($data[0]);

        // Binary frames start with 0x00 (big-endian seq number) and are at least 7 bytes
        if ($firstByte === 0x00 && strlen($data) >= 7) {
            return false;
        }

        // Otherwise check if it's a valid UTF-8 JSON object/array
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
