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

use function count;
use function explode;
use function is_numeric;
use function is_string;
use function json_decode;
use function microtime;
use function round;
use function spl_object_id;
use function strlen;
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
                    unset(self::$rooms[$roomName], self::$roomPlayback[$roomName]);
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
                $this->handleGroupLeave($client);
                break;

            default:
                // Unrecognised but properly-typed message — the documented
                // extension seam: relay verbatim to the REST of the room (the
                // sender already holds the frame; echoing it back buys noise).
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

        // Remove from room if in one
        if ($client->room !== null) {
            $this->handleGroupLeave($client);
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
    }
}
