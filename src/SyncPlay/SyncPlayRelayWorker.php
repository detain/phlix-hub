<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Channel\Client as ChannelClient;
use Psr\Container\ContainerInterface;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\TokenBucket;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function array_merge;
use function count;
use function explode;
use function is_numeric;
use function is_string;
use function json_decode;
use function microtime;
use function round;
use function spl_object_id;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;

/**
 * WebSocket worker that handles inbound SyncPlay relay connections.
 *
 * SyncPlay clients connect via WSS to `ws://hub:8804/syncplay/{server_id}` to
 * participate in synchronized playback sessions. This worker maintains room
 * state locally and broadcasts playback messages to all clients in a room.
 *
 * The relay token travels in the upgrade request's `Authorization: Bearer`
 * header or its `Sec-WebSocket-Protocol: bearer, <token>` subprotocol — the
 * carrier `:8803` adopted in S2b and this surface adopted in S237. It is NEVER
 * accepted from the query string: see {@see ClientRelayWorker::extractClientToken()}.
 *
 * This is separate from the main relay tunnel (ports 8802/8803) which uses
 * binary frames. SyncPlay uses native WebSocket JSON frames.
 *
 * ## Two vocabularies, one socket (owner decision #14)
 *
 * This endpoint speaks TWO JSON vocabularies and never confuses them:
 *
 * 1. The **bare room vocabulary** (`group_join` / `group_leave` /
 *    `playback_*` / `time_sync` and their `room_state` / `client_joined` /
 *    `client_left` / `time_sync_reply` replies) — the original :8804 dialect.
 *    Its only proven live consumers in the estate are NONE: every client's
 *    `:8804` code path is the `pending_command` family (a DIFFERENT,
 *    type-gated frame family this worker also carries), and the room frames
 *    were consumed by nobody (evidence: each hubRelay consumer gates strictly
 *    on `type === 'pending_command'` — mobile `hubRelay.ts`, console
 *    `HubRelayConsumer.php`, roku `HubCommandTask.brs`).
 * 2. The **canonical `syncplay_*` catalog** (phlix-syncplay SPEC.md §3, the
 *    vocabulary the server speaks on `:8097` and every syncplay client
 *    already parses) — learned inbound and answered in-kind so relay-mode
 *    clients need no dialect shim.
 *
 * A connection LATCHES its dialect with the first `syncplay_`-prefixed frame
 * it sends ({@see SyncPlayClient::$canonical}); every room reply is then
 * encoded for that dialect and fanned out only to same-dialect members. No
 * translation happens between the vocabularies, so a hypothetical mixed room
 * hears each speaker in its own tongue only — a deliberate, documented
 * boundary, not an oversight: there is no consumer to bridge today, and a
 * translator would be unverifiable fiction. The `pending_command` lane is
 * dialect-agnostic: it is addressed to the user's sockets, not to a room.
 *
 * @package Phlix\Hub\SyncPlay
 */
final class SyncPlayRelayWorker
{
    /**
     * Default SyncPlay relay WS port.
     */
    public const DEFAULT_PORT = 8804;

    /**
     * The ONE subprotocol-id this hub speaks: the marker half of the S237
     * `Sec-WebSocket-Protocol: bearer, <token>` carrier. The token that rides the
     * same header is a credential, never a protocol, and is therefore never a
     * selectable answer — see {@see self::negotiatedSubprotocolEcho()}.
     */
    private const BEARER_SUBPROTOCOL = 'bearer';

    /**
     * The exact 101 header line for {@see self::BEARER_SUBPROTOCOL}, ready for
     * `$connection->headers` (Workerman appends entries verbatim and drops any
     * carrying CR/LF, so this constant is deliberately a bare single line).
     * Composed from the id above so the two can never drift apart.
     */
    private const BEARER_SUBPROTOCOL_ECHO = 'Sec-WebSocket-Protocol: ' . self::BEARER_SUBPROTOCOL;

    /**
     * Sustained inbound budget per `:8804` client, in bytes/sec (audit M-3).
     *
     * SyncPlay control frames are small JSON (`group_join`, `playback_*`,
     * `time_sync`); a well-behaved client sends a handful per second — hundreds
     * of bytes. 128 KiB/sec leaves three orders of magnitude of headroom while
     * capping what ONE socket can force the worker — and, through the verbatim
     * relay of unknown types, EVERY member of its room — to parse and fan out.
     * The limiter is a {@see TokenBucket}, so the excess degrades to dropped
     * frames, never a disconnect: a briefly-over-eager client recovers as the
     * bucket refills.
     */
    public const INBOUND_RATE_BYTES_PER_SECOND = 131072.0;

    /**
     * Inbound burst budget per `:8804` client, in bytes (audit M-3) — two
     * seconds' sustained rate, enough for a reconnect-time state exchange,
     * negligible against a flood. The bucket starts FULL (TokenBucket
     * semantics), so the very first frame of a connection is never throttled.
     */
    public const INBOUND_BURST_BYTES = 262144.0;

    /**
     * Envelope `protocol_version` stamped on every canonical `syncplay_*`
     * frame, mirroring phlix-server `Messages::PROTOCOL_VERSION`
     * (phlix-syncplay SPEC.md §2). Two repos, one number, cited both ways.
     */
    public const CANONICAL_PROTOCOL_VERSION = 1;

    /**
     * Playback-queue ceiling for canonical rooms — mirrors phlix-server
     * `GroupState::MAX_QUEUE_SIZE = 1000` (its LOW-2 note: the same 1000-item
     * ceiling the repo's job stores use). An over-cap submission is refused
     * fail-loud with the stored queue UNTOUCHED, exactly like the server.
     */
    public const CANONICAL_MAX_QUEUE_SIZE = 1000;

    /**
     * Active SyncPlay client connections keyed by connection ID.
     *
     * @var array<int, SyncPlayClient>
     */
    private static array $clients = [];

    /**
     * Last playback anchor per scoped room — the distilled, bounded snapshot of
     * the most recent `playback_*` frame the room relayed (see
     * {@see self::playbackAnchor()}), or absent when the room has never played
     * anything.
     *
     * This is what makes the documented `room_state` contract ("the room's
     * members AND current playback state", openapi + docs/websockets.md)
     * actually true: a client joining mid-movie gets the position/state anchor
     * instead of sitting dark until the next control frame. Keyed by the SAME
     * scoped key as {@see self::$rooms} and swept with it (leave-to-empty and
     * the 60s timer), so a resident worker never accumulates anchors of dead
     * rooms.
     *
     * @var array<string, array{type: string, from_client_id: string, timestamp: int,
     *      position?: float, media_id?: string}>
     */
    private static array $roomPlayback = [];

    /**
     * Canonical-dialect room bookkeeping — the pieces the bare vocabulary
     * never needed and the `syncplay_*` catalog does. All keyed by the SAME
     * scoped room key as {@see self::$rooms}, unset when the last member
     * leaves and swept with the room by the 60s timer, so none of them can
     * outlive the session they describe (the resident-worker leak rule).
     *
     * Host model (minimal, honest): the first member of a canonical room is
     * its host (mirrors the server's "creator is host"); when the host leaves
     * the oldest remaining member is elected (mirrors `GroupState`'s
     * oldest-member election) and the room announces it with
     * `syncplay_host_elect`; `syncplay_host_transfer` lets the host hand the
     * role over voluntarily. Host-gated ops answer non-hosts with
     * `syncplay_error` — the loud refusal :8097 gives, never silence.
     *
     * @var array<string, string> scoped room key => host clientId
     */
    private static array $roomHosts = [];

    /** @var array<string, int> scoped room key => formed-at unix seconds (`group_state.created_at`) */
    private static array $roomCreatedAt = [];

    /** @var array<string, int> scoped room key => last canonical activity unix seconds (`group_state.last_activity_at`) */
    private static array $roomActivity = [];

    /** @var array<string, string> scoped room key => 'playing'|'paused'|'stopped' (`group_state.playback_state`) */
    private static array $roomStates = [];

    /** @var array<string, list<array{media_id: string, media_info: array<string, mixed>}>> scoped room key => normalized queue */
    private static array $roomQueues = [];

    /**
     * Map of SCOPED room key => [client_id => SyncPlayClient].
     *
     * The key is NOT the raw client-supplied room name: it is scoped to the
     * authenticated (server_id, owner) identity via {@see scopedRoomKey()} so
     * two different servers/owners that pick the same friendly room name resolve
     * to DIFFERENT internal rooms and can never control each other's playback.
     *
     * @var array<string, array<string, SyncPlayClient>>
     */
    private static array $rooms = [];

    /**
     * @param int                $port        SyncPlay WS port (default 8804).
     * @param int                $count       Number of worker processes.
     * @param ContainerInterface $container   PSR-11 container for lazy service access.
     * @param string             $channelHost `workerman/channel` broker host (S93).
     * @param int                $channelPort `workerman/channel` broker port (S93).
     *        Defaults to the SAME broker the relay proxy uses
     *        ({@see RelayProxyProtocol::DEFAULT_CHANNEL_PORT}) — there is one
     *        broker per hub process tree, not one per feature.
     * @param float              $inboundRateBytesPerSecond Per-client sustained
     *        inbound budget (audit M-3); see {@see self::INBOUND_RATE_BYTES_PER_SECOND}.
     * @param float              $inboundBurstBytes         Per-client inbound burst
     *        capacity; see {@see self::INBOUND_BURST_BYTES}. A non-positive value
     *        disables the per-client budget entirely (diagnostics only).
     *
     * The channel and inbound-budget parameters are TRAILING and DEFAULTED on
     * purpose: every existing call site (`Application::run()`, the unit suite)
     * constructs this worker positionally with three to five arguments and must
     * keep compiling unchanged.
     */
    public function __construct(
        private readonly int $port,
        private readonly int $count,
        private readonly ContainerInterface $container,
        private readonly string $channelHost = '127.0.0.1',
        private readonly int $channelPort = RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
        private readonly float $inboundRateBytesPerSecond = self::INBOUND_RATE_BYTES_PER_SECOND,
        private readonly float $inboundBurstBytes = self::INBOUND_BURST_BYTES,
    ) {
    }

    /**
     * Start the SyncPlay relay WebSocket worker.
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-syncplay-relay-ws';
        $worker->count = $this->count;

        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $worker->onWorkerStart = [$this, 'onWorkerStart'];

        // Close DB connections in onWorkerStop (still in coroutine context) so
        // hooked PDO sockets aren't destroyed at RSHUTDOWN outside a coroutine.
        \Phlix\Hub\Common\Database\ConnectionPool::armWorkerStopCleanup($worker);

        return $worker;
    }

    /**
     * Worker start hook — set up the room cleanup timer and join the channel
     * broker so HTTP workers can push pending commands into this process.
     *
     * The channel join is the S93 half: this worker is the ONLY process holding
     * the live client sockets ({@see self::$clients} is a per-process static), so
     * a pending command minted on an HTTP worker can only reach a socket by
     * crossing the `workerman/channel` broker. Mirrors
     * {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()}.
     *
     * The join is wrapped so a broker failure logs and CONTINUES: the room
     * cleanup timer and the whole SyncPlay surface must keep working even if no
     * pending command can be delivered, and a throw here would take down a
     * resident worker at boot.
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // Clean up empty rooms every 60 seconds. The playback anchor belongs to
        // the room, so a swept room loses its anchor in the same step — an
        // orphaned anchor is exactly the unbounded static growth a resident
        // worker must never accumulate.
        Timer::add(60, static function (): void {
            foreach (self::$rooms as $roomName => $clients) {
                if (count($clients) === 0) {
                    unset(
                        self::$rooms[$roomName],
                        self::$roomPlayback[$roomName],
                        self::$roomHosts[$roomName],
                        self::$roomCreatedAt[$roomName],
                        self::$roomActivity[$roomName],
                        self::$roomStates[$roomName],
                        self::$roomQueues[$roomName],
                    );
                }
            }
        });

        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            ChannelClient::connect($this->channelHost, $this->channelPort);

            $dispatcher = new PendingCommandDispatcher($logger);
            // The vendor Channel\Client::on() callback is typed with the legacy
            // `callback` pseudo-type, which Psalm resolves to an (undefined)
            // Channel\callback class that neither an array nor a Closure can
            // satisfy. The runtime only does is_callable(), so a first-class
            // callable is correct here.
            /** @psalm-suppress InvalidArgument */
            ChannelClient::on(PendingCommandProtocol::PUSH_EVENT, $dispatcher->onPush(...));

            $logger->info('SyncPlay pending command: relay worker joined channel broker', [
                'channel_host' => $this->channelHost,
                'channel_port' => $this->channelPort,
            ]);
        } catch (Throwable $e) {
            $logger->error('SyncPlay pending command: channel init failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write `$frame` to every live client of `$userId` bound to `$serverId`.
     *
     * ## Why the match is on BOTH identities
     *
     * The `serverId` half is not decoration. A user may own two servers, and a
     * client bound to server B cannot play a media id that only exists on server
     * A — delivering there would be a command that silently fails on arrival,
     * which is exactly the class of dishonesty the delivered count exists to
     * prevent. Matching on the user alone would inflate the count with sockets
     * that could never have acted on the frame.
     *
     * ## Why room membership is irrelevant
     *
     * Delivery happens regardless of whether the client has joined a SyncPlay
     * room. A pending command is addressed to a **user's open app**, not to a
     * room: a user who has just opened Phlix and asked Alexa to start something
     * has no room, and requiring one would make the feature unreachable in its
     * primary case. This is why it does NOT go through
     * {@see self::broadcastToRoom()}.
     *
     * An empty `$userId` or `$serverId` returns 0 immediately. An empty identity
     * must never fan out: `SyncPlayClient::$userId` is nullable, and a loose
     * comparison against an unauthenticated client would turn one blank field
     * into a broadcast to every socket on the hub.
     *
     * @param string $userId   Hub user id the command is addressed to.
     * @param string $serverId Server the media id belongs to.
     * @param string $frame    The JSON frame to write.
     *
     * @return int Number of sockets actually written to. A `send()` that returns
     *         false — a connection already closing or with a full write buffer —
     *         is NOT a delivery and does not count: the count is the ceiling on
     *         what the Alexa skill is allowed to claim, so an attempted write to
     *         a dead socket must never push it over reality (audit M-1; the
     *         contract {@see PendingCommandPusherInterface::pushPlayMedia()} and
     *         the openapi `:8804` delivery-gate note both promise "actually
     *         written", not "attempted").
     *
     * @since S93
     */
    public static function deliverToUser(string $userId, string $serverId, string $frame): int
    {
        if ($userId === '' || $serverId === '') {
            return 0;
        }

        $delivered = 0;
        foreach (self::$clients as $client) {
            if ($client->userId === null || $client->userId === '') {
                continue;
            }
            if ($client->userId !== $userId || $client->serverId !== $serverId) {
                continue;
            }
            $delivered += $client->connection->send($frame) ? 1 : 0;
        }

        return $delivered;
    }

    /**
     * Handle WebSocket upgrade for SyncPlay client.
     *
     * SV-4.7: Requires valid relay token + server ownership. Unauthenticated
     * clients may connect but cannot join rooms or control playback.
     *
     * @param TcpConnection    $connection Client connection.
     * @param WorkermanRequest $request    WS upgrade request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Parse server_id from path: /syncplay/{server_id}
        $serverId = self::parseServerId($request->path());
        if ($serverId === null) {
            $logger->warning('SyncPlay: rejected connection, missing server_id in path', [
                'path' => $request->path(),
            ]);
            $connection->close('', true);
            return;
        }

        $connId = spl_object_id($connection);
        $clientId = self::generateClientId();

        // SV-4.7 / S237: Authenticate via relay token, using the SAME CARRIER as
        // `:8803` — `ClientRelayWorker::extractClientToken()`, i.e. an
        // `Authorization: Bearer` header or a `Sec-WebSocket-Protocol: bearer,
        // <token>` subprotocol. One credential class must have exactly one
        // carrier; reading the token here a second way would be its own defect.
        //
        // ⚠ This REPLACES `$_GET['token']`, which was broken two ways at once:
        //   (1) security — the documented client URL put a live relay token in a
        //       query string, where it lands in access logs, proxy logs and
        //       `Referer` headers and outlives the token's own expiry. This is
        //       the exact form S2b removed from `:8803`.
        //   (2) function — Workerman 5 NEVER populates the `$_GET` superglobal
        //       (it carries no `$_GET` write anywhere in the package; the query
        //       is reachable only via `$request->get()`). So the read was
        //       unconditionally `null` and EVERY `:8804` connect fell through to
        //       `rejectUnauthorized()`. The surface authenticated nobody. The old
        //       unit tests passed only because they set `$_GET` by hand — a
        //       superglobal production never sets.
        $token = ClientRelayWorker::extractClientToken($request);
        $userId = null;
        if ($token !== null && $token !== '') {
            $userId = $this->validateClientAuth($token, $serverId);
        }

        if ($token === null || $token === '' || $userId === null) {
            $logger->warning('SyncPlay: rejected connection, invalid or missing relay token', [
                'server_id' => $serverId,
            ]);
            $this->rejectUnauthorized($connection);
            return;
        }

        // S355 — RFC 6455 §4.1/§4.2.2: a server that accepts a client's
        // subprotocol offer answers by selecting AT MOST ONE of the offered
        // protocol-ids and echoing it verbatim. A client that requested
        // subprotocols and is given none back fails the connection outright
        // (WHATWG §2.4 — the measured 1006 on the S298 ui carrier
        // `new WebSocket(url, ['bearer', token])`). Workerman composes the 101
        // from `$connection->headers` AFTER this callback returns, and that
        // array is the only extension point on this path (its own
        // Websocket::dealHandshake() appends the entries it finds there —
        // vendor/workerman/workerman/src/Protocols/Websocket.php:437-456). The
        // @internal/@deprecated tags on the property target the webman HTTP
        // `$response` API, which does not exist here.
        //
        // The selected protocol is `bearer` — the marker the S237 carrier is
        // named for — and NEVER the token. Two independent reasons, both
        // load-bearing:
        //   (1) a credential is not a protocol-id. Echoing the token answers the
        //       negotiation with a secret and re-publishes on the RESPONSE wire
        //       the very credential S2b and S237 removed from the query string
        //       to keep it out of access logs, proxy logs and `Referer` headers.
        //   (2) §4.2.2 permits only a protocol the server supports; the hub
        //       supports exactly one, `bearer`. Echoing the token would work
        //       only because that client smuggled it into the offer list — it
        //       is not a protocol the hub implements.
        //
        // The echo is therefore gated on the client HAVING OFFERED `bearer`:
        // selecting a protocol the client never offered is itself the §4.1
        // violation, so an `Authorization: Bearer` client that offers no
        // subprotocol — the roku/mobile carrier — must keep receiving no echo.
        // A client that offers `bearer` gets it, whichever carrier authenticated
        // the connection, because that is a negotiation the client really made.
        $subprotocolEcho = self::negotiatedSubprotocolEcho($request->header('sec-websocket-protocol'));
        if ($subprotocolEcho !== null) {
            /** @psalm-suppress InternalProperty, DeprecatedProperty */
            $connection->headers = [$subprotocolEcho];
        }

        // Create client state with authenticated userId
        $client = new SyncPlayClient(
            $connection,
            $serverId,
            $clientId,
            $userId,
        );

        // Audit M-3: every :8804 socket carries its own inbound byte budget from
        // birth. :8802/:8803 grew CONNECT limiters long ago but this surface
        // never bounded frames on an ESTABLISHED socket, so one client could
        // force the worker — and, via the verbatim relay, its whole room — to
        // process and fan out frames at wire speed. The bucket lives on the
        // CLIENT (not a static map) so it is GC'd the moment the socket is
        // dropped. A non-positive injected budget disables the bucket (null =
        // unbudgeted; the onMessage guard treats null as "diagnostics, allow").
        if ($this->inboundRateBytesPerSecond > 0.0 && $this->inboundBurstBytes > 0.0) {
            $client->inboundBucket = new TokenBucket(
                $this->inboundRateBytesPerSecond,
                $this->inboundBurstBytes,
            );
        }

        self::$clients[$connId] = $client;

        $logger->info('SyncPlay: client connected (authenticated)', [
            'client_id' => $clientId,
            'server_id' => $serverId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Decide the 101 `Sec-WebSocket-Protocol` answer for a raw request header.
     *
     * RFC 6455 §4.2.2 allows the server to select AT MOST ONE protocol-id from
     * the client's offer list, and §4.1 makes any selection the client did not
     * offer a connection the client must fail. This hub implements exactly one
     * protocol — {@see self::BEARER_SUBPROTOCOL} — so it answers only when the
     * client offered that id.
     *
     * The token sharing the header in the browser carrier is never a candidate.
     * It is a credential, not a protocol, and echoing it would put a live relay
     * token on the RESPONSE wire — the same exposure S2b and S237 removed from
     * the query string so it stops landing in access logs, proxy logs and
     * `Referer` headers.
     *
     * The offer is a comma-separated list with optional whitespace around each
     * id (RFC 7230), so the match is per-entry: `chat, bearer` offers it,
     * `bearer-chat` does not.
     *
     * @param mixed $offeredProtocols Raw `sec-websocket-protocol` header value.
     *
     * @return string|null The header line to append to the 101, or null for no echo.
     */
    private static function negotiatedSubprotocolEcho(mixed $offeredProtocols): ?string
    {
        if (!is_string($offeredProtocols) || $offeredProtocols === '') {
            return null;
        }

        foreach (explode(',', $offeredProtocols) as $protocolId) {
            if (trim($protocolId) === self::BEARER_SUBPROTOCOL) {
                return self::BEARER_SUBPROTOCOL_ECHO;
            }
        }

        return null;
    }

    /**
     * Validate a client relay token for SyncPlay access.
     *
     * Mirrors the validation in {@see ClientRelayWorker::validateClientAuth()}.
     *
     * @param string $token    The relay token, from the upgrade request's
     *                        `Authorization` header or `bearer` subprotocol
     *                        (S237 — never from the query string).
     * @param string $serverId The server_id the client wants to join.
     *
     * @return string|null The authenticated user id, or null on failure.
     */
    private function validateClientAuth(string $token, string $serverId): ?string
    {
        // Fetch auth services from container lazily (same pattern as ClientRelayWorker)
        /** @var ClientRelayTokenService $tokenService */
        $tokenService = $this->container->get(ClientRelayTokenService::class);
        /** @var ServerInfoHandler $serverInfo */
        $serverInfo = $this->container->get(ServerInfoHandler::class);

        // Validate token with ClientRelayTokenService
        $bound = $tokenService->validate($token);
        if ($bound === null) {
            return null;
        }

        // Token must be scoped to the requested server
        if ($bound['server_id'] !== $serverId) {
            return null;
        }

        // Re-confirm current ownership: the bound user must still own the server
        $owner = $serverInfo->getOwnerAndStatus($serverId);
        if ($owner === null) {
            return null;
        }

        if ($owner['userId'] !== $bound['user_id']) {
            return null;
        }

        return $bound['user_id'];
    }

    /**
     * Reject an unauthenticated connection.
     *
     * @param TcpConnection $connection The connection to close.
     *
     * @return void
     */
    private function rejectUnauthorized(TcpConnection $connection): void
    {
        $connection->close('', true);
    }

    /**
     * Handle incoming SyncPlay message.
     *
     * SyncPlay messages are JSON with a 'type' field indicating the message kind.
     *
     * @param TcpConnection $connection Client connection.
     * @param string        $data       Raw WebSocket frame payload (JSON text).
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);
        $client = self::$clients[$connId] ?? null;

        if ($client === null) {
            return;
        }

        // Audit M-3: charge the frame to the client's inbound budget BEFORE any
        // parsing or relay work, so junk costs the sender the same budget as
        // protocol and an over-budget client is refused the expensive path. The
        // balance may go negative (TokenBucket's oversized-frame rule: the debt
        // is paid off by later refills, a single large frame never deadlocks the
        // stream). Null bucket = disabled budget (diagnostics).
        $bucket = $client->inboundBucket;
        if ($bucket !== null && !$bucket->canSpend()) {
            $this->logInboundThrottleOnce($client);

            return;
        }
        $bucket?->spend((float) strlen($data));

        // Parse SyncPlay JSON message
        /** @var array<string, mixed>|null $message */
        $message = json_decode($data, true);
        if (!is_array($message)) {
            return;
        }

        /** @var mixed $messageType */
        $messageType = $message['type'] ?? null;
        $type = is_string($messageType) ? $messageType : null;

        // Audit M-3: a frame with NO usable string `type` is malformed, not an
        // extension. Dropping it here is load-bearing twice over: the unchecked
        // array access used to emit an E_WARNING on every keyless frame (log
        // noise a client can dial up at wire speed), and a null `$type` fell
        // THROUGH the switch default and got relayed VERBATIM to every member of
        // the sender's room — turning one junk frame into N. The catalog relay
        // exists for NAMED types the hub does not know (the catalog is a floor),
        // never for typeless payloads.
        if ($type === null) {
            return;
        }

        // Dialect latch (owner #14): choosing the `syncplay_*` vocabulary IS
        // the handshake — no extra hello frame, no capability negotiation to
        // get wrong. The latch decides which vocabulary this connection's
        // REPLIES use; see {@see SyncPlayClient::$canonical}.
        if (self::isCanonicalType($type)) {
            $client->canonical = true;
        }

        switch ($type) {
            case 'group_join':
                $this->handleGroupJoin($client, $message);
                break;

            case 'playback_play':
            case 'playback_pause':
            case 'playback_seek':
                $this->handlePlayback($client, $message, $type);
                break;

            case 'time_sync':
                $this->handleTimeSync($client, $message);
                break;

            case 'group_leave':
                // LEAVE routes by the connection's LATCHED dialect, not by the
                // frame name: a canonical-latched client asking to leave must
                // run the canonical teardown (host election, state cleanup,
                // canonical notices) or the room books would orphan its host
                // slot. A client speaking both vocabularies is self-contradictory;
                // its membership state follows the latch, faithfully.
                if ($client->canonical) {
                    $this->handleCanonicalLeave($client);
                } else {
                    $this->handleGroupLeave($client);
                }
                break;

            // ---- Canonical catalog (phlix-syncplay SPEC.md §3) -------------
            //
            // `syncplay_group_create` and `syncplay_group_join` share ONE
            // path: the hub holds no copy of the server's group registry
            // (groups are REST/:8097 truth), so its canonical rooms are
            // socket-side SHADOW rooms keyed by the supplied id/name inside
            // the caller's own (server_id, owner) scope. Auto-creating on a
            // first join is what keeps a relay client — which learns its
            // group id over REST, never over this socket — able to reach its
            // room at all. A REST-created group id rides in as the friendly
            // room name verbatim; identity law is untouched (§9: the
            // connection, never the payload, says who you are).

            case 'syncplay_group_create':
                $this->handleCanonicalJoin($client, $message, true);
                break;

            case 'syncplay_group_join':
                $this->handleCanonicalJoin($client, $message, false);
                break;

            case 'syncplay_group_leave':
                $this->handleCanonicalLeave($client);
                break;

            case 'syncplay_playback_play':
            case 'syncplay_playback_pause':
            case 'syncplay_playback_seek':
                $this->handleCanonicalPlaybackCommand($client, $message, $type);
                break;

            case 'syncplay_playback_sync':
                $this->handleCanonicalPlaybackSync($client);
                break;

            case 'syncplay_playback_queue':
                $this->handleCanonicalPlaybackQueue($client, $message);
                break;

            case 'syncplay_chat':
                $this->handleCanonicalChat($client, $message);
                break;

            case 'syncplay_typing':
                $this->handleCanonicalTyping($client, $message);
                break;

            case 'syncplay_time_ping':
                $this->handleCanonicalTimePing($client, $message);
                break;

            case 'syncplay_host_transfer':
                $this->handleCanonicalHostTransfer($client, $message);
                break;

            case 'syncplay_group_list':
                $this->handleCanonicalGroupList($client);
                break;

            case 'syncplay_time_sync':
                // Documented deferral, loud reply: the STATUS-QUERY arm needs
                // the server's own TimeSync authority (offset/latency/drift of
                // a clock the hub does not track). Refusing beats inventing a
                // confident-looking zero.
                $this->sendCanonicalError(
                    $client,
                    'hub.protocol_unsupported',
                    'The hub relay does not track server clock state; use syncplay_time_ping',
                );
                break;

            case 'syncplay_group_state':
            case 'syncplay_host_elect':
            case 'syncplay_time_pong':
            case 'syncplay_error':
            case 'syncplay_info':
                // Server→client direction (SPEC §3). A client SPEAKING these
                // is a protocol violation — refuse loudly, never fan the lie
                // out to the room. Same message the :8097 default gives.
                $this->sendCanonicalError($client, 'UNKNOWN_MESSAGE', 'Unknown message type');
                break;

            default:
                if (self::isCanonicalType($type)) {
                    // Unknown `syncplay_*` name: the CATALOG is understood here,
                    // so the floor is CLOSED for it — :8097 answers exactly this
                    // with the same error. Relaying an unreadable canonical name
                    // would let one typo'd frame masquerade as state to every
                    // canonical member of the room.
                    $this->sendCanonicalError($client, 'UNKNOWN_MESSAGE', 'Unknown message type');
                    break;
                }

                // Unrecognised but properly-typed message — the documented
                // extension seam: relay verbatim to the REST of the room (the
                // sender already holds the frame; echoing it back buys noise).
                // Bare vocabulary only (see the syncplay_ arm above); reaches
                // bare-dialect members only (see broadcastToRoom).
                if ($client->room !== null) {
                    $this->broadcastToRoom($client->room, $data, $client->clientId);
                }
        }
    }

    /**
     * Warn about an inbound-budget breach at most once per connection.
     *
     * The drop itself must stay silent-cheap — logging every refused frame
     * would hand a flooding client a log-noise amplifier, the very failure
     * class the budget exists to remove. The latch lives on the client, so it
     * costs nothing after the first trip and disappears with the socket.
     *
     * @param SyncPlayClient $client The throttled client.
     *
     * @return void
     */
    private function logInboundThrottleOnce(SyncPlayClient $client): void
    {
        if ($client->inboundThrottleLogged) {
            return;
        }

        $client->inboundThrottleLogged = true;

        LoggerFactory::get(LogChannels::RELAY)->warning('SyncPlay: inbound rate limit exceeded, dropping frames', [
            'client_id' => $client->clientId,
            'server_id' => $client->serverId,
            'rate_bytes_per_second' => $this->inboundRateBytesPerSecond,
            'burst_bytes' => $this->inboundBurstBytes,
        ]);
    }

    /**
     * Handle client connection close.
     *
     * @param TcpConnection $connection Client connection.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);
        $client = self::$clients[$connId] ?? null;

        if ($client === null) {
            return;
        }

        // Remove from room if in one — routed by the connection's latched
        // dialect so a canonical member's close still triggers election,
        // notice, and bookkeeping cleanup (mirrors the server's
        // onConnectionClose → leaveGroup path). The leave ack it sends is a
        // write against an already-closed socket — at worst a no-op false
        // return, same as production sends on dying connections elsewhere.
        if ($client->room !== null) {
            if ($client->canonical) {
                $this->handleCanonicalLeave($client);
            } else {
                $this->handleGroupLeave($client);
            }
        }

        unset(self::$clients[$connId]);

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->info('SyncPlay: client disconnected', [
            'client_id' => $client->clientId,
            'server_id' => $client->serverId,
        ]);
    }

    /**
     * Handle group_join message - client joins a SyncPlay room.
     *
     * @param SyncPlayClient     $client  The joining client.
     * @param array<string, mixed> $message Parsed JSON message.
     *
     * @return void
     */
    private function handleGroupJoin(SyncPlayClient $client, array $message): void
    {
        // SV-4.7: Require authentication to join a SyncPlay room.
        if ($client->userId === null) {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->warning('SyncPlay: rejected group_join from unauthenticated client', [
                'client_id' => $client->clientId,
            ]);
            $client->connection->close('', true);
            return;
        }

        $clientRoom = $message['room'] ?? null;
        if ($clientRoom === null) {
            return;
        }
        // @var guard: json message room field is string when not null
        if (!is_string($clientRoom)) {
            return;
        }

        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Leave current room if in one
        if ($client->room !== null) {
            $this->handleGroupLeave($client);
        }

        // Scope the room to the authenticated (server_id, owner) identity. The
        // client supplies a friendly name; two different servers/owners that
        // pick the same friendly name must land in DIFFERENT internal rooms so
        // a control/broadcast never crosses the (server_id, owner) boundary.
        $scopedRoom = self::scopedRoomKey($client, $clientRoom);

        // Join new room (keyed by the scoped key, never the raw client string)
        $client->room = $scopedRoom;
        /** @var mixed $messageDisplayName */
        $messageDisplayName = $message['display_name'] ?? null;
        $displayName = is_string($messageDisplayName) ? $messageDisplayName : 'Anonymous';
        $client->displayName = $displayName;

        if (!isset(self::$rooms[$scopedRoom])) {
            self::$rooms[$scopedRoom] = [];
        }
        self::$rooms[$scopedRoom][$client->clientId] = $client;

        // Send current room state to the joining client (echo the FRIENDLY name
        // back). `playback` carries the room's current playback anchor — the
        // last relayed control frame, or null in a room that has never played —
        // which is the second half of the documented contract ("members AND
        // current playback state"): without it a client joining mid-movie got no
        // position anchor and sat dark until the next control frame (audit M-2).
        $stateMessage = [
            'type' => 'room_state',
            'room' => $clientRoom,
            'clients' => $this->getRoomState($scopedRoom),
            'playback' => self::$roomPlayback[$scopedRoom] ?? null,
        ];
        $client->connection->send(json_encode($stateMessage, JSON_THROW_ON_ERROR));

        // Notify the OTHER members about the new joiner. The joiner is EXCLUDED
        // on purpose (audit L-1): both docs promise "Another client joined the
        // room", and the old call passed an exclude-id while asking for
        // self-inclusion — the flag won and the sender received its own
        // client_joined, making the sentence a lie. The joiner learns membership
        // from its own room_state above, so the echo was never information.
        $joinNotification = [
            'type' => 'client_joined',
            'client_id' => $client->clientId,
            'display_name' => $client->displayName,
        ];
        $this->broadcastToRoom(
            $scopedRoom,
            json_encode($joinNotification, JSON_THROW_ON_ERROR),
            $client->clientId,
        );

        $logger->info('SyncPlay: client joined room', [
            'client_id' => $client->clientId,
            'room' => $clientRoom,
            'server_id' => $client->serverId,
        ]);
    }

    /**
     * Handle group_leave message - client leaves their current room.
     *
     * @param SyncPlayClient $client The leaving client.
     *
     * @return void
     */
    private function handleGroupLeave(SyncPlayClient $client): void
    {
        if ($client->room === null) {
            return;
        }

        $room = $client->room;
        $clientId = $client->clientId;

        unset(self::$rooms[$room][$clientId]);
        $client->room = null;

        // Last member out: the room's playback anchor dies with the session, so
        // a room re-formed before the sweep starts from a truthful
        // `room_state.playback: null` instead of inheriting a dead session's
        // position. The emptied member bucket KEEPS its existing lifecycle — the
        // 60s sweep reaps it (pinned by the round-trip suite's timer proof),
        // and the anchor unset here is idempotent with that sweep.
        if (self::$rooms[$room] === []) {
            unset(self::$roomPlayback[$room]);
        }

        // Notify the REMAINING members about the departure. The leaver is
        // excluded (it is already out of the map; the explicit exclusion keeps
        // the call honest even if that ordering ever changes).
        $leaveNotification = [
            'type' => 'client_left',
            'client_id' => $clientId,
        ];
        $this->broadcastToRoom($room, json_encode($leaveNotification, JSON_THROW_ON_ERROR), $clientId);
    }

    /**
     * Handle playback messages (play, pause, seek) - broadcast to room.
     *
     * @param SyncPlayClient     $client  The sending client.
     * @param array<string, mixed> $message Parsed JSON message.
     * @param string             $type   Playback message type.
     *
     * @return void
     */
    private function handlePlayback(SyncPlayClient $client, array $message, string $type): void
    {
        $room = $client->room;
        if ($room === null) {
            return;
        }

        // Enrich with server-authoritative sender identity and hub clock. The
        // `from_client_id` overwrite is the SPEC-§9 discipline: whatever the
        // sender claimed about who it is, the connection's identity wins.
        // `timestamp` is UNIX MILLISECONDS (audit L-2) — the syncplay transport
        // clock contract (phlix-syncplay/SPEC.md §2: "timestamp … in
        // milliseconds") — so a client feeding this into its NTP/position math
        // never mixes a 1000x-scaled value with the ms positions it carries.
        $timestamp = self::nowMs();
        $message['type'] = $type;
        $message['from_client_id'] = $client->clientId;
        $message['timestamp'] = $timestamp;

        // Remember the distilled anchor BEFORE the broadcast: a client joining
        // later must be able to reconstruct "what is this room playing right
        // now" from its room_state alone (audit M-2).
        self::$roomPlayback[$room] = self::playbackAnchor($type, $client->clientId, $timestamp, $message);

        // Broadcast to EVERY member of the room, the sender included. This is a
        // deliberate contract, not the old comment's lie (audit L-1): the openapi
        // `:8804` direction says "broadcast to every client in the same room",
        // and a sender that sees its own control come back is how a client
        // confirms the hub accepted the frame. One parameter — exclusion by id,
        // null for none — because the previous (exclude-id, include-self) pair
        // let callers pass contradictory arguments and the comment drifted.
        $this->broadcastToRoom($room, json_encode($message, JSON_THROW_ON_ERROR), null);
    }

    /**
      * Handle time_sync message - respond with server time for sync.
      *
      * @param SyncPlayClient     $client  The requesting client.
      * @param array<string, mixed> $message Parsed JSON message.
      *
      * @return void
      */
    private function handleTimeSync(SyncPlayClient $client, array $message): void
    {
        // Respond with the server clock in UNIX MILLISECONDS (audit L-2): the
        // syncplay NTP model (SPEC.md §5) computes offsets and RTT from ms-scaled
        // quads, and a seconds-scale `server_time` would silently produce
        // offsets 1000x off. `client_time` is echoed back untouched — whatever
        // scale the client probes with is the scale it reads the echo against;
        // only the server-stamped fields are ms by contract.
        $reply = [
            'type' => 'time_sync_reply',
            'server_time' => self::nowMs(),
            'client_time' => $message['client_time'] ?? null,
        ];

        $client->connection->send(json_encode($reply, JSON_THROW_ON_ERROR));
    }

    // =====================================================================
    // Canonical `syncplay_*` dialect (owner decision #14)
    //
    // Every handler below mirrors the phlix-server `SyncPlayManager` arm for
    // the same message type — field names, unit law, exclusion law, guard
    // order and error codes — so a client that works against `:8097` works
    // against this relay with zero dialect shim. Where the relay honestly
    // CANNOT be the server (no group registry, no clock authority, no
    // per-member drift policy) the deviation is stated at the site, never
    // hidden.
    // =====================================================================

    /**
     * Is `$type` a name from the canonical catalog?
     *
     * The `syncplay_` prefix IS the catalog (every constant in phlix-server
     * `Messages.php` carries it), which makes dialect detection a prefix test
     * with no table to drift.
     */
    private static function isCanonicalType(string $type): bool
    {
        return str_starts_with($type, 'syncplay_');
    }

    /**
     * Build one canonical frame with the envelope the FACTORY owns, mirroring
     * phlix-server `Messages::frame()` (SPEC §2): `{type, protocol_version,
     * ...payload, timestamp}` with `timestamp` in unix MILLISECONDS. A payload
     * smuggling its own type/protocol_version/timestamp has those copies
     * STRIPPED, so a client can never forge the hub's clock stamp or version.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function canonicalFrame(string $frameType, array $payload): array
    {
        unset($payload['type'], $payload['protocol_version'], $payload['timestamp']);

        return array_merge(
            ['type' => $frameType, 'protocol_version' => self::CANONICAL_PROTOCOL_VERSION],
            $payload,
            ['timestamp' => self::nowMs()],
        );
    }

    /**
     * Write one canonical frame to a single client.
     *
     * @param array<string, mixed> $frame
     *
     * @return void
     */
    private function sendCanonical(SyncPlayClient $client, array $frame): void
    {
        $client->connection->send(json_encode($frame, JSON_THROW_ON_ERROR));
    }

    /**
     * Loud canonical refusal: `{type: syncplay_error, error_code, message, …}`
     * — the exact shape of phlix-server `Messages::error()`. Silence is not in
     * this family's vocabulary.
     */
    private function sendCanonicalError(SyncPlayClient $client, string $code, string $message): void
    {
        $this->sendCanonical($client, self::canonicalFrame('syncplay_error', [
            'error_code' => $code,
            'message' => $message,
        ]));
    }

    /**
     * Fan a canonical frame out to the CANONICAL members of a room.
     *
     * The dialect twin of {@see self::broadcastToRoom()}: same single encode,
     * same one exclusion parameter, plus the dialect guard — bare-latched
     * members never see `syncplay_*` frames, exactly as canonical members
     * never see bare ones.
     *
     * @param array<string, mixed> $frame
     *
     * @return void
     */
    private function broadcastCanonical(string $room, array $frame, ?string $excludeClientId = null): void
    {
        $encoded = json_encode($frame, JSON_THROW_ON_ERROR);
        foreach (self::$rooms[$room] ?? [] as $client) {
            if (!$client->canonical) {
                continue;
            }
            if ($excludeClientId !== null && $client->clientId === $excludeClientId) {
                continue;
            }
            $client->connection->send($encoded);
        }
    }

    /**
     * Recover the FRIENDLY room name a scoped key was built from.
     *
     * The scoped key is `serverId:userId:friendly` and the connection that
     * joined it knows both of its own identity halves, so stripping the
     * prefix it supplied is exact — the friendly name may itself contain
     * colons and survives untouched. The internal key is never echoed to the
     * wire, same law as the bare `room_state` echo.
     */
    private static function friendlyFromScoped(SyncPlayClient $client, string $scopedRoom): string
    {
        $prefix = $client->serverId . ':' . (string) $client->userId . ':';

        return str_starts_with($scopedRoom, $prefix)
            ? substr($scopedRoom, strlen($prefix))
            : $scopedRoom;
    }

    /**
     * Numeric coercion at the wire boundary (mirrors the server's private
     * `intFromMixed`): numeric-ish values (int or decimal string) become
     * ints, anything else takes the documented default.
     */
    private static function canonicalInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Elect the canonical room's next host: the OLDEST remaining member by
     * `roomJoinedAt`, mirroring phlix-server `GroupState`'s host-election
     * rule. Ties fall to roster order, which is insertion order — stable.
     */
    private static function electCanonicalHost(string $room): ?string
    {
        $elected = null;
        $electedAt = 0;

        foreach (self::$rooms[$room] ?? [] as $clientId => $member) {
            $joinedAt = $member->roomJoinedAt ?? time();
            if ($elected === null || $joinedAt < $electedAt) {
                $elected = $clientId;
                $electedAt = $joinedAt;
            }
        }

        return $elected;
    }

    /**
     * Build the `group` payload of `syncplay_group_state`, field-for-field
     * per phlix-server `GroupState::getState()` law: members DICT keyed by
     * member id with `{id, name, is_host, joined_at}`, the roster scalars,
     * and `joined_at`/`created_at`/`last_activity_at` in UNIX SECONDS
     * (SPEC §4) while `playback_position` rides wire MILLISECONDS (SPEC §2,
     * S441). `current_media_duration` is the server's uninitialised default
     * (0): a JSON relay does not inspect media.
     *
     * @return array<string, mixed>
     */
    private function canonicalGroupPayload(string $room, string $friendlyName): array
    {
        $now = time();
        $hostId = self::$roomHosts[$room] ?? null;

        $members = [];
        foreach (self::$rooms[$room] ?? [] as $clientId => $member) {
            $members[$clientId] = [
                'id' => $clientId,
                'name' => $member->displayName,
                'is_host' => $hostId !== null && $clientId === $hostId,
                'joined_at' => $member->roomJoinedAt ?? $now,
            ];
        }

        $anchor = self::$roomPlayback[$room] ?? null;

        return [
            'group_id' => $friendlyName,
            'group_name' => $friendlyName,
            'member_count' => count($members),
            'members' => $members,
            'host_id' => $hostId,
            'current_media_id' => $anchor['media_id'] ?? null,
            'current_media_duration' => 0,
            'playback_position' => isset($anchor['position']) ? (int) $anchor['position'] : 0,
            'playback_state' => self::$roomStates[$room] ?? 'stopped',
            'queue' => self::$roomQueues[$room] ?? [],
            'created_at' => self::$roomCreatedAt[$room] ?? $now,
            'last_activity_at' => self::$roomActivity[$room] ?? $now,
        ];
    }

    /**
     * Handle `syncplay_group_create` / `syncplay_group_join`.
     *
     * One path, two names (the server's `createGroup`/`joinGroup` collapse
     * here because the hub auto-creates the shadow room — see the switch-arm
     * comment). Law carried from the server:
     *  - identity is the CONNECTION, never the payload claim (§9);
     *    `member_name` is display metadata;
     *  - `password_hash`/`password` are ACCEPTED AND IGNORED, which is safe
     *    here for a stated reason rather than a luck: canonical rooms live
     *    inside the caller's own `(server_id, owner)` scope, and that scope
     *    is exactly what a group password protects — another user cannot
     *    address this room key at all, and the owner's own devices are the
     *    audience the owner chose by typing the password into them;
     *  - join implies leave (the server's MED-3): an identity moving to a
     *    second room detaches from the first with full teardown.
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalJoin(SyncPlayClient $client, array $message, bool $isCreate): void
    {
        // SV-4.7 twin for the canonical door: an unauthenticated socket must
        // not exist here at all (the pre-101 token gate is the real wall), so
        // this is belt-and-suspenders — same close the bare join performs.
        if ($client->userId === null) {
            $logger = LoggerFactory::get(LogChannels::RELAY);
            $logger->warning('SyncPlay: rejected canonical join from unauthenticated client', [
                'client_id' => $client->clientId,
            ]);
            $client->connection->close('', true);

            return;
        }

        /** @var mixed $roomField */
        $roomField = $message[$isCreate ? 'group_name' : 'group_id'] ?? null;
        if ($isCreate) {
            // Server default: a create without a usable name groups under 'New Group'.
            $friendly = is_string($roomField) ? $roomField : 'New Group';
        } else {
            if (!is_string($roomField) || $roomField === '') {
                $this->sendCanonicalError($client, 'syncplay.group_not_found', 'Group not found');

                return;
            }
            $friendly = $roomField;
        }

        /** @var mixed $nameField */
        $nameField = $message['member_name'] ?? null;
        $displayName = is_string($nameField) ? $nameField : ($isCreate ? 'Host' : 'User');

        if ($client->room !== null) {
            $this->handleCanonicalLeave($client);
        }

        $scopedRoom = self::scopedRoomKey($client, $friendly);
        if (!isset(self::$rooms[$scopedRoom])) {
            self::$rooms[$scopedRoom] = [];
        }

        $now = time();
        $isFirstMember = self::$rooms[$scopedRoom] === [];

        $client->room = $scopedRoom;
        $client->displayName = $displayName;
        $client->roomJoinedAt = $now;
        self::$rooms[$scopedRoom][$client->clientId] = $client;

        if ($isFirstMember) {
            // Creator (or first joiner of a shadow room) is host — the server's
            // "creator is host" rule.
            self::$roomHosts[$scopedRoom] = $client->clientId;
            self::$roomCreatedAt[$scopedRoom] = $now;
        }
        self::$roomActivity[$scopedRoom] = $now;

        // The joiner learns membership + identity: `your_id` is the hub-side
        // member id every later frame will be stamped with (SPEC §9).
        $this->sendCanonical($client, self::canonicalFrame('syncplay_group_state', [
            'group' => $this->canonicalGroupPayload($scopedRoom, $friendly),
            'your_id' => $client->clientId,
        ]));

        if (!$isFirstMember) {
            // SPEC §6: a join is announced as syncplay_info with TOP-LEVEL
            // member_id/member_name — the exact server prose, so a client
            // greeting on the join toast needs no relay-specific branch.
            $this->broadcastCanonical($scopedRoom, self::canonicalFrame('syncplay_info', [
                'message' => $displayName . ' joined the group',
                'member_id' => $client->clientId,
                'member_name' => $displayName,
            ]), $client->clientId);
        }

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->info('SyncPlay: canonical client joined room', [
            'client_id' => $client->clientId,
            'room' => $friendly,
            'server_id' => $client->serverId,
        ]);
    }

    /**
     * Handle `syncplay_group_leave` (and the leave half of close/rejoin).
     *
     * Server law: the leaver is acked with `syncplay_info`; a PLAIN leave is
     * reflected "in the next group_state" (SPEC §6) and the hub delivers that
     * NEXT immediately — a relay that waits for an unrelated event would let
     * stale rosters linger on every device; a host leave additionally fires
     * `syncplay_host_elect` before the state, exactly like `leaveGroup()`.
     * The emptied room's whole canonical bookkeeping (anchor, host, clocks,
     * state, queue) dies in this step so a re-formed room starts truthful;
     * the emptied bucket keeps the pinned 60s-sweep lifecycle.
     */
    private function handleCanonicalLeave(SyncPlayClient $client): void
    {
        $room = $client->room;
        if ($room === null) {
            // Server law: leaving without membership fails loud.
            $this->sendCanonicalError($client, 'syncplay.leave_failed', 'Not in any group');

            return;
        }

        $clientId = $client->clientId;
        $memberName = $client->displayName;
        $wasHost = (self::$roomHosts[$room] ?? null) === $clientId;
        $friendly = self::friendlyFromScoped($client, $room);

        unset(self::$rooms[$room][$clientId]);
        $client->room = null;
        $client->roomJoinedAt = null;

        if ((self::$rooms[$room] ?? []) === []) {
            unset(
                self::$roomPlayback[$room],
                self::$roomHosts[$room],
                self::$roomCreatedAt[$room],
                self::$roomActivity[$room],
                self::$roomStates[$room],
                self::$roomQueues[$room],
            );
        } else {
            self::$roomActivity[$room] = time();

            if ($wasHost) {
                $newHost = self::electCanonicalHost($room);
                if ($newHost !== null) {
                    self::$roomHosts[$room] = $newHost;
                } else {
                    unset(self::$roomHosts[$room]);
                }
                $this->broadcastCanonical($room, self::canonicalFrame('syncplay_host_elect', [
                    'elected_id' => $newHost,
                    'elected_by' => $clientId,
                ]));
            }

            $this->broadcastCanonical($room, self::canonicalFrame('syncplay_group_state', [
                'group' => $this->canonicalGroupPayload($room, $friendly),
            ]));
        }

        $this->sendCanonical($client, self::canonicalFrame('syncplay_info', [
            'message' => $memberName . ' left the group',
        ]));
    }

    /**
     * Handle `syncplay_playback_play` / `_pause` / `_seek` (host-gated).
     *
     * Server law carried per type: the sender's claim about WHO sent this is
     * overwritten with the connection's id (§9); `position`/`from_position`/
     * `to_position` are wire milliseconds sanitized to ints; `server_time`
     * passes through when the client supplied one (the :8097 arm echoes the
     * payload value — it is the sender's clock reference, not the relay's).
     * Outbound shape: PLAY returns to the host as a confirmation and to the
     * others by broadcast; PAUSE/SEEK reach only the others (the server's
     * exact asymmetry). The room anchor + state slot update BEFORE the fan-out,
     * shared with the bare dialect, so a late joiner's state is truthful.
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalPlaybackCommand(SyncPlayClient $client, array $message, string $type): void
    {
        $room = $client->room;
        if ($room === null) {
            $this->sendCanonicalError($client, 'NOT_IN_GROUP', 'You are not in a group');

            return;
        }

        if ((self::$roomHosts[$room] ?? null) !== $client->clientId) {
            $this->sendCanonicalError($client, 'NOT_HOST', 'Only the host can control playback');

            return;
        }

        $timestamp = self::nowMs();
        $serverTime = self::canonicalInt($message['server_time'] ?? null, $timestamp);

        if ($type === 'syncplay_playback_seek') {
            $payload = [
                'member_id' => $client->clientId,
                'from_position' => self::canonicalInt($message['from_position'] ?? null, 0),
                'to_position' => self::canonicalInt($message['to_position'] ?? null, 0),
                'server_time' => $serverTime,
            ];
        } else {
            $payload = [
                'member_id' => $client->clientId,
                'position' => self::canonicalInt($message['position'] ?? null, 0),
                'server_time' => $serverTime,
            ];
        }

        // Anchor before broadcast (the bare path's M-2 discipline, same slot,
        // same distilled shape): `position`/`to_position`/`media_id` distil
        // through the shared helper, so a mid-session joiner is anchored in
        // either vocabulary it speaks.
        self::$roomPlayback[$room] = self::playbackAnchor($type, $client->clientId, $timestamp, $message);
        if ($type === 'syncplay_playback_play') {
            self::$roomStates[$room] = 'playing';
        } elseif ($type === 'syncplay_playback_pause') {
            self::$roomStates[$room] = 'paused';
        }
        // SEEK deliberately leaves playback_state ALONE — the server's
        // setPlaybackPosition-vs-updatePlayback distinction, kept.
        self::$roomActivity[$room] = (int) ($timestamp / 1000);

        $frame = self::canonicalFrame($type, $payload);
        if ($type === 'syncplay_playback_play') {
            $this->sendCanonical($client, $frame);
        }
        $this->broadcastCanonical($room, $frame, $client->clientId);
    }

    /**
     * Handle `syncplay_playback_sync` (state report, anyone may send).
     *
     * Server law (S291/S441): the ANSWER carries the ROOM's current state
     * stamped with the HOST's id — not the reporter's position — and reaches
     * EVERY member including the reporter (this is the one frame family a
     * client must not echo-suppress; SPEC §9.1). Deviation stated: the server
     * stamps this payload's `server_time` with a seconds clock (`time()`);
     * this relay keeps its own pinned ms law (see {@see self::nowMs()}) for
     * every value IT stamps — the hub has never emitted a seconds `server_time`
     * and will not start by borrowing a server bug-shape.
     */
    private function handleCanonicalPlaybackSync(SyncPlayClient $client): void
    {
        $room = $client->room;
        if ($room === null) {
            $this->sendCanonicalError($client, 'NOT_IN_GROUP', 'You are not in a group');

            return;
        }

        self::$roomActivity[$room] = time();
        $anchor = self::$roomPlayback[$room] ?? null;

        $this->broadcastCanonical($room, self::canonicalFrame('syncplay_playback_sync', [
            'member_id' => self::$roomHosts[$room] ?? null,
            'group_id' => self::friendlyFromScoped($client, $room),
            'current_media_id' => $anchor['media_id'] ?? null,
            'position' => isset($anchor['position']) ? (int) $anchor['position'] : 0,
            'is_playing' => (self::$roomStates[$room] ?? 'stopped') === 'playing',
            'server_time' => self::nowMs(),
        ]));
    }

    /**
     * Handle `syncplay_playback_queue` (host-gated).
     *
     * Server law: parse the FULL replacement queue before touching live state
     * (LOW-2 all-or-nothing), same entry normalization (`media_id` must be a
     * string, `media_info` keeps only string keys), same cap and same
     * registered overflow code `syncplay.queue_limit_exceeded` with the queue
     * left UNTOUCHED on refusal. The accepted update broadcasts to everyone
     * including the host (the server excludes nobody).
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalPlaybackQueue(SyncPlayClient $client, array $message): void
    {
        $room = $client->room;
        if ($room === null) {
            $this->sendCanonicalError($client, 'NOT_IN_GROUP', 'You are not in a group');

            return;
        }

        if ((self::$roomHosts[$room] ?? null) !== $client->clientId) {
            $this->sendCanonicalError($client, 'NOT_HOST', 'Only the host can modify the queue');

            return;
        }

        /** @var mixed $queueRaw */
        $queueRaw = $message['queue'] ?? [];

        /** @var list<array{media_id: string, media_info: array<string, mixed>}> $parsed */
        $parsed = [];
        if (is_array($queueRaw)) {
            foreach ($queueRaw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                /** @var mixed $mediaId */
                $mediaId = $item['media_id'] ?? null;
                if (!is_string($mediaId)) {
                    continue;
                }
                /** @var array<string, mixed> $mediaInfo */
                $mediaInfo = [];
                /** @var mixed $mediaInfoRaw */
                $mediaInfoRaw = $item['media_info'] ?? [];
                if (is_array($mediaInfoRaw)) {
                    /**
                     * @var array-key $infoKey
                     * @var mixed      $infoValue
                     */
                    foreach ($mediaInfoRaw as $infoKey => $infoValue) {
                        if (is_string($infoKey)) {
                            /** @psalm-suppress MixedAssignment — wire-decoded value into a typed container, the house idiom */
                            $mediaInfo[$infoKey] = $infoValue;
                        }
                    }
                }
                $parsed[] = ['media_id' => $mediaId, 'media_info' => $mediaInfo];
            }
        }

        if (count($parsed) > self::CANONICAL_MAX_QUEUE_SIZE) {
            $this->sendCanonicalError(
                $client,
                'syncplay.queue_limit_exceeded',
                sprintf(
                    'Playback queue exceeds the %d-item cap; queue unchanged',
                    self::CANONICAL_MAX_QUEUE_SIZE,
                ),
            );

            return;
        }

        self::$roomQueues[$room] = $parsed;
        self::$roomActivity[$room] = time();

        $this->broadcastCanonical($room, self::canonicalFrame('syncplay_playback_queue', [
            'queue' => $parsed,
        ]));
    }

    /**
     * Handle `syncplay_chat` (any member).
     *
     * Server law: identity is the connection (S289), a blank message is
     * dropped in silence, and the fan-out includes the sender (the server's
     * broadcast carries no exclude list for chat).
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalChat(SyncPlayClient $client, array $message): void
    {
        $room = $client->room;
        if ($room === null) {
            $this->sendCanonicalError($client, 'NOT_IN_GROUP', 'You are not in a group');

            return;
        }

        /** @var mixed $messageField */
        $messageField = $message['message'] ?? null;
        $text = is_string($messageField) ? $messageField : '';
        if (trim($text) === '') {
            return;
        }

        $this->broadcastCanonical($room, self::canonicalFrame('syncplay_chat', [
            'member_id' => $client->clientId,
            'member_name' => $client->displayName,
            'message' => $text,
        ]));
    }

    /**
     * Handle `syncplay_typing` (any member).
     *
     * Server law: the guard misses are SILENT for typing (no error frames for
     * a transient indicator), the boolean is read the server's way
     * (`=== true` or the legacy `'1'` string), and the fan-out excludes the
     * sender.
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalTyping(SyncPlayClient $client, array $message): void
    {
        $room = $client->room;
        if ($room === null) {
            return;
        }

        /** @var mixed $isTypingRaw */
        $isTypingRaw = $message['is_typing'] ?? null;
        $isTyping = $isTypingRaw === true || $isTypingRaw === '1';

        $this->broadcastCanonical($room, self::canonicalFrame('syncplay_typing', [
            'member_id' => $client->clientId,
            'is_typing' => $isTyping,
        ]), $client->clientId);
    }

    /**
     * Handle `syncplay_time_ping`.
     *
     * NTP law (server `TimeSync::processPing`): echo `client_time` untouched,
     * stamp `server_time` with the hub clock in MILLISECONDS, reply to the
     * PINGER only. Room membership is NOT required — clock sync precedes and
     * outlives rooms, same as on :8097.
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalTimePing(SyncPlayClient $client, array $message): void
    {
        $this->sendCanonical($client, self::canonicalFrame('syncplay_time_pong', [
            'client_time' => self::canonicalInt($message['client_time'] ?? null, 0),
            'server_time' => self::nowMs(),
        ]));
    }

    /**
     * Handle `syncplay_host_transfer` (host-gated).
     *
     * Server law with the server's guard order and codes: NOT_IN_GROUP →
     * NOT_HOST → INVALID_NEW_HOST → MEMBER_NOT_FOUND → SAME_HOST; success
     * rebroadcasts `syncplay_group_state` to EVERY member (the server
     * excludes nobody), where the new host sees itself flagged.
     *
     * @param array<string, mixed> $message
     *
     * @return void
     */
    private function handleCanonicalHostTransfer(SyncPlayClient $client, array $message): void
    {
        $room = $client->room;
        if ($room === null) {
            $this->sendCanonicalError($client, 'NOT_IN_GROUP', 'You are not in a group');

            return;
        }

        if ((self::$roomHosts[$room] ?? null) !== $client->clientId) {
            $this->sendCanonicalError($client, 'NOT_HOST', 'Only the host can transfer ownership');

            return;
        }

        /** @var mixed $newHostField */
        $newHostField = $message['new_host_id'] ?? null;
        $newHostId = is_string($newHostField) ? $newHostField : '';
        if ($newHostId === '') {
            $this->sendCanonicalError($client, 'INVALID_NEW_HOST', 'Missing new host member ID');

            return;
        }

        if (!isset(self::$rooms[$room][$newHostId])) {
            $this->sendCanonicalError($client, 'MEMBER_NOT_FOUND', 'New host is not a member of this group');

            return;
        }

        if ($newHostId === $client->clientId) {
            $this->sendCanonicalError($client, 'SAME_HOST', 'Cannot transfer to yourself');

            return;
        }

        self::$roomHosts[$room] = $newHostId;
        self::$roomActivity[$room] = time();

        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->info('SyncPlay: canonical host transferred', [
            'room' => self::friendlyFromScoped($client, $room),
            'old_host' => $client->clientId,
            'new_host' => $newHostId,
        ]);

        $this->broadcastCanonical($room, self::canonicalFrame('syncplay_group_state', [
            'group' => $this->canonicalGroupPayload($room, self::friendlyFromScoped($client, $room)),
        ]));
    }

    /**
     * Handle `syncplay_group_list`.
     *
     * The relay's honest answer is its OWN shadow rooms inside the caller's
     * `(server_id, owner)` scope — the only rooms this socket could ever
     * join. Server summary shape (`id`, `name`, `member_count`,
     * `has_password`, `current_media`, `is_playing`) with two truthful
     * constants: `id`/`name` are the friendly key and `has_password` is
     * false because the hub gates by ownership scope, not passwords. Empty
     * (pre-sweep) buckets are not listed.
     */
    private function handleCanonicalGroupList(SyncPlayClient $client): void
    {
        $prefix = $client->serverId . ':' . (string) $client->userId . ':';

        $groups = [];
        foreach (self::$rooms as $scopedRoom => $members) {
            if (!str_starts_with($scopedRoom, $prefix) || $members === []) {
                continue;
            }
            $friendly = substr($scopedRoom, strlen($prefix));
            $anchor = self::$roomPlayback[$scopedRoom] ?? null;

            $groups[] = [
                'id' => $friendly,
                'name' => $friendly,
                'member_count' => count($members),
                'has_password' => false,
                'current_media' => $anchor['media_id'] ?? null,
                'is_playing' => (self::$roomStates[$scopedRoom] ?? 'stopped') === 'playing',
            ];
        }

        $this->sendCanonical($client, self::canonicalFrame('syncplay_group_list', [
            'groups' => $groups,
            'count' => count($groups),
        ]));
    }

    /**
     * Broadcast a message to the clients of a room.
     *
     * @param string      $room            Scoped room key (see {@see scopedRoomKey()}).
     * @param string      $message         JSON message to send.
     * @param string|null $excludeClientId Member to skip, or null to reach EVERY
     *        member including the sender. One parameter on purpose: the previous
     *        ($excludeId, $includeSelf) pair could contradict itself — callers
     *        passed an id to exclude AND asked for self-inclusion, the flag won,
     *        and the "other clients" comments on those calls became lies
     *        (audit L-1). Illegal state now has no representation.
     *
     * @return void
     */
    private function broadcastToRoom(
        string $room,
        string $message,
        ?string $excludeClientId = null,
    ): void {
        $clients = self::$rooms[$room] ?? [];
        foreach ($clients as $client) {
            // Dialect isolation: canonical-latched members receive only the
            // `syncplay_*` catalog (see {@see self::broadcastCanonical()}).
            // A room whose members are all bare — every room that exists in
            // production today — is untouched by this guard.
            if ($client->canonical) {
                continue;
            }
            if ($excludeClientId !== null && $client->clientId === $excludeClientId) {
                continue;
            }
            $client->connection->send($message);
        }
    }

    /**
     * Current wall clock in UNIX MILLISECONDS.
     *
     * The syncplay transport clock contract (phlix-syncplay/SPEC.md §2): every
     * `timestamp`/`server_time` this worker STAMPS on the `:8804` wire is
     * ms-scaled. Rounded rather than truncated so sub-millisecond jitter can
     * never report a value a whole millisecond low.
     *
     * The one deliberate seconds-scaled sibling is the S93 `pending_command`
     * `issued_at`, pinned to unix seconds by openapi AND by its only consumer
     * (@phlix/ui hubRelay.ts) — see PendingCommandDispatcher. Different frame
     * family, different pinned unit; do not "harmonise" one without its
     * consumer.
     */
    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Distil the joiner-facing playback anchor out of an enriched playback frame.
     *
     * Parse, don't hoard: the ROOM receives the sender's full payload verbatim,
     * but the per-room copy must not grow with whatever a client chooses to
     * attach, so only the fields a joining client needs to anchor survive —
     * type/state, sender, hub clock, and a numeric position (`to_position` on a
     * seek) or string media id when the sender supplied one.
     *
     * @param string               $type      Playback type as relayed.
     * @param string               $clientId  Authoritative sender client id.
     * @param int                  $timestamp Hub clock (ms) stamped on the frame.
     * @param array<string, mixed> $message   The enriched message being broadcast.
     *
     * @return array{type: string, from_client_id: string, timestamp: int, position?: float, media_id?: string}
     */
    private static function playbackAnchor(string $type, string $clientId, int $timestamp, array $message): array
    {
        $anchor = [
            'type' => $type,
            'from_client_id' => $clientId,
            'timestamp' => $timestamp,
        ];

        /** @var mixed $position */
        $position = $message['position'] ?? $message['to_position'] ?? null;
        if (is_numeric($position)) {
            $anchor['position'] = (float) $position;
        }

        /** @var mixed $mediaId */
        $mediaId = $message['media_id'] ?? null;
        if (is_string($mediaId) && $mediaId !== '') {
            $anchor['media_id'] = $mediaId;
        }

        return $anchor;
    }

    /**
     * Get current state of a room.
     *
     * @param string $room Scoped room key (see {@see scopedRoomKey()}).
     *
     * @return array<string, array{client_id: string, display_name: string}> Client list.
     */
    private function getRoomState(string $room): array
    {
        $clients = self::$rooms[$room] ?? [];
        $state = [];
        foreach ($clients as $client) {
            $state[$client->clientId] = [
                'client_id' => $client->clientId,
                'display_name' => $client->displayName,
            ];
        }
        return $state;
    }

    /**
     * Parse server_id from /syncplay/{server_id} path.
     *
     * @param string $path Request path.
     *
     * @return string|null Server ID or null if not found.
     */
    public static function parseServerId(string $path): ?string
    {
        if (preg_match('~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~', $path, $matches) !== 1) {
            return null;
        }
        $serverId = trim(rawurldecode($matches[1]));
        return $serverId !== '' ? $serverId : null;
    }

    /**
     * Compose the internal, scoped room key for a client's friendly room name.
     *
     * The effective room namespace is scoped to the authenticated
     * (server_id, owner) identity established in {@see validateClientAuth()}.
     * `server_id` and `owner` are UUIDs (hex + hyphens, never a colon), so the
     * first two `:` delimiters unambiguously separate the scope prefix from the
     * arbitrary client-supplied friendly name — two different servers/owners can
     * never resolve to the same internal room even with identical friendly names.
     *
     * @param SyncPlayClient $client     The authenticated client (server_id + owner).
     * @param string         $clientRoom The friendly room name from the client.
     *
     * @return string The scoped room key.
     */
    private static function scopedRoomKey(SyncPlayClient $client, string $clientRoom): string
    {
        return $client->serverId . ':' . (string) $client->userId . ':' . $clientRoom;
    }

    /**
     * Generate a unique client ID.
     *
     * @return string UUID-like client identifier.
     */
    private static function generateClientId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }

    /**
     * Get active connection count (diagnostics).
     *
     * @return int Active connection count.
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$clients);
    }

    /**
     * Get room count (diagnostics).
     *
     * @return int Active room count.
     */
    public static function getActiveRoomCount(): int
    {
        return count(self::$rooms);
    }

    /**
     * Clear all static state (for test isolation).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$clients = [];
        self::$rooms = [];
        self::$roomPlayback = [];
        self::$roomHosts = [];
        self::$roomCreatedAt = [];
        self::$roomActivity = [];
        self::$roomStates = [];
        self::$roomQueues = [];
    }
}
