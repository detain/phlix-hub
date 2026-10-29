<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\WebSocketCloseFrame;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function getenv;
use function spl_object_id;

/**
 * Handles inbound client WebSocket connections to reach servers via the relay tunnel.
 *
 * Clients connect via WSS to `GET /client/{server_id}` and authenticate with
 * a per-user relay token. The hub validates the token, looks up the tunnel for
 * the server_id, and if found, would create a ClientConnection and attach it
 * to the tunnel for frame multiplexing.
 *
 * ⚠ DECLARED UNAVAILABLE in the deployed multi-process topology (M2, review
 * hub__asphp). A mount can only be served when the client socket and the
 * server's Tunnel live in the SAME process — the tunnel's per-client queues,
 * throttle buckets, and drain timers are keyed on same-process object
 * identity. Tunnels are registered exclusively via
 * {@see \Phlix\Hub\Relay\TunnelManager::acceptServer()}, whose only production
 * caller is {@see \Phlix\Hub\Relay\RelayWorker} on port `:8802` (count=1).
 * This controller runs inside {@see \Phlix\Hub\Relay\ClientRelayWorker} on
 * port `:8803` — a SEPARATE Workerman process whose container resolves its
 * own fresh {@see TunnelManagerInterface} (per-process PHP-DI factory). That
 * registry can never hold an entry, so the bind lookup is structurally dead
 * here: {@see onWebSocketConnect()} refuses every mount LOUDLY with a real WS
 * close code 1011 (do-not-retry) and an explicit topology reason in the
 * relay log instead of pretending to consider it. The conformant live path
 * for client→server traffic is the HTTP-over-relay proxy
 * (`/api/v1/servers/{id}/proxy/*`, bridged over the workerman/channel broker).
 * A real fix is an owner-level architecture decision (fold the client WS into
 * the :8802 worker, or build a per-mount bidirectional byte bridge over the
 * broker — the current broker protocol is request/response shaped); see the
 * CHANGELOG entry for this refusal.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ClientMountController
{
    /**
     * RFC 6455 §7.4.1 — "Unexpected Condition": server aborted (mount errors,
     * unknown connection state). L-4: delivered as a real WS close frame.
     *
     * M2 (review hub__asphp): THE mount-refusal code. The :8803 surface refuses
     * every mount with this code because the bind cannot be served in this
     * process at all — unlike 1013, 1011 does not invite retry loops against a
     * structurally dead surface.
     */
    public const CLOSE_INTERNAL_ERROR = 1011;

    /**
     * RFC 6455 §7.4.1 — "Try Again Later": transient server-side refusal; the
     * client should back off and retry. Emitted by the :8803 worker's mount
     * RATE LIMITER ({@see \Phlix\Hub\Relay\ClientRelayWorker::CLOSE_TRY_AGAIN_LATER})
     * — NOT by this controller since M2: mount refusals here are structural
     * ({@see CLOSE_INTERNAL_ERROR}), never "try again".
     */
    public const CLOSE_TRY_AGAIN_LATER = 1013;

    /**
     * Map of connection ID => ClientConnection for active client connections.
     *
     * M2 (review hub__asphp): written only by a successful mount — which the
     * declared-unavailable law makes impossible in this topology — so this map
     * stays empty in production and the pair below is the preserved rebuild
     * seam (see {@see onClientMessage()}).
     *
     * @var array<int, ClientConnection>
     */
    private static array $connClients = [];

    /**
     * Map of connection ID => FrameDecoder for streaming frame parsing.
     *
     * Paired with {@see self::$connClients}; stays empty under the M2 law.
     *
     * @var array<int, FrameDecoder>
     */
    private static array $connDecoders = [];

    /**
     * The controller is stateless since M2 (no container, no resolvable
     * services): the HTTP signpost is pure response construction and the WS
     * mount refuses before consulting anything (class docblock).
     */
    public function __construct()
    {
    }

    /**
     * Handle a client connection request to `/client/{server_id}`.
     *
     * This HTTP surface never mounted anything: it is a signpost that steers
     * callers to the dedicated `:8803` WebSocket worker (see class docblock for
     * the M2 declared-unavailable law on that mount path). Authentication,
     * tunnel binding, and frame relay all live on the WS worker.
     *
     * @param Request          $request The Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'server_id'.
     *
     * @return Response
     */
    public function handle(Request $request, array $params): Response
    {
        // NOTE: the REAL client-mount rate limit lives on the :8803 WebSocket
        // worker ({@see \Phlix\Hub\Relay\ClientRelayWorker::onWebSocketConnect},
        // HB-4.6f). This HTTP method is a dead stub — it only steers callers to
        // the WS endpoint (426/501 below) and never mounts a tunnel, so it needs
        // no limiter of its own (the former one was retired in HB-4.6f).
        $serverId = $params['server_id'] ?? '';

        if ($serverId === '') {
            // Registry dual-placement emit: dotted code + legacy literal
            // byte-identical in `error` TEXT (see SubdomainController's class
            // docblock); inline literal for wire-law visibility.
            return (new Response())->error(400, 'missing_server_id', 'MISSING_SERVER_ID', [
                'message' => 'Server ID is required',
            ]);
        }

        // The relay tunnel is established over the dedicated client WS worker
        // (ws://…:8803), NOT over this HTTP route. Authentication, tunnel
        // binding, and bidirectional frame relay all happen there — see
        // {@see \Phlix\Hub\Relay\ClientRelayWorker} and the WS-upgrade
        // handlers below ({@see onWebSocketConnect()} et al).
        //
        // Reaching this method means a caller issued a plain HTTP GET (or a
        // GET without a valid WebSocket upgrade) to the client mount. Mirror
        // RelayController's contract: 426 when no upgrade was requested, and
        // otherwise steer the caller to the WS endpoint with a 501.
        $hubWsHost = getenv('HUB_WS_HOST');
        $hubWsHost = is_string($hubWsHost) && $hubWsHost !== '' ? $hubWsHost : getenv('HUB_PUBLIC_DOMAIN');
        $hubWsHost = is_string($hubWsHost) && $hubWsHost !== '' ? $hubWsHost : 'your-hub-host';
        $wsEndpoint = 'ws://' . $hubWsHost . ':' . \Phlix\Hub\Relay\ClientRelayWorker::DEFAULT_PORT
            . '/client/' . $serverId;

        // M2 (review hub__asphp): the steer names where the surface lives, but
        // it must not promise it works — in the deployed multi-process topology
        // the :8803 mount refuses every connection (WS close 1011). The
        // `available: false` field makes that machine-readable.
        // (S2b truth: the mount credential is the per-user CLIENT RELAY TOKEN,
        // not the enrollment JWT — the stale wording is corrected here too.)
        $unavailableNote = ' The WebSocket surface at this address is not available in this hub '
            . 'topology (it refuses every mount with close code 1011); live client-to-server '
            . 'traffic rides the HTTP relay proxy at /api/v1/servers/' . $serverId . '/proxy/.';

        // Read through getHeader(): $request->headers is case-NORMALISED at the
        // Workerman boundary (Workerman lowercases, collectHeadersFromWorkerman()
        // then uppercases), so the literal 'Upgrade' never matched and this
        // branch fired for every caller — the 501 steer below was unreachable.
        // getHeader() compares with strcasecmp(). See
        // tests/Unit/Http/RawHeaderIndexGateTest.php.
        if ($request->getHeader('Upgrade') !== 'websocket') {
            return (new Response())
                ->header('X-WS-Endpoint', $wsEndpoint)
                ->status(426)
                ->json([
                    'error' => 'UPGRADE_REQUIRED',
                    'code' => 'relay.client_ws_endpoint',
                    'message' => 'The client relay must be established via WebSocket. Connect to '
                        . $wsEndpoint . ' with your client relay token.' . $unavailableNote,
                    'ws_endpoint' => $wsEndpoint,
                    'available' => false,
                ]);
        }

        return (new Response())
            ->header('X-WS-Endpoint', $wsEndpoint)
            ->status(501)
            ->json([
                'error' => 'NOT_IMPLEMENTED_VIA_HTTP',
                'code' => 'relay.client_ws_endpoint',
                'message' => 'The client relay tunnel must be established over the WebSocket worker. Connect to '
                    . $wsEndpoint . ' with your client relay token.' . $unavailableNote,
                'ws_endpoint' => $wsEndpoint,
                'available' => false,
            ]);
    }

    /**
     * Handle WebSocket upgrade for a client connection — refused loudly.
     *
     * Invoked by {@see \Phlix\Hub\Relay\ClientRelayWorker} after the client
     * relay token has been validated for $serverId. Under the M2
     * declared-unavailable law (class docblock) this method NEVER binds: the
     * mount is refused with a real WS close code 1011 (do-not-retry) and the
     * relay log carries an explicit `client_mount_bridge_unavailable` reason,
     * replacing the old silent "no active server tunnel" 1013 that indistinguish-
     * ably invited eternal reconnect loops against a structurally dead surface.
     *
     * The caller (ClientRelayWorker) remains responsible for authentication;
     * $userId is accepted and logged so operators can see WHO attempted the
     * dead mount (the auth ladder still runs first: cheap rate-limit, token
     * validation, ownership re-confirmation).
     *
     * @param TcpConnection    $connection Workerman TCP connection.
     * @param WorkermanRequest $request    The WS upgrade request (path/headers) —
     *                                     parsed by the caller; unused here.
     * @param string           $serverId   Server UUID the client wants to reach.
     * @param string           $userId     Authenticated owning-user UUID resolved by
     *                                     {@see \Phlix\Hub\Relay\ClientRelayWorker::validateClientAuth()}
     *                                     (S42). Logged for attribution; empty when
     *                                     the caller had none to forward.
     *
     * @return void
     */
    public function onWebSocketConnect(
        TcpConnection $connection,
        WorkermanRequest $request,
        string $serverId,
        string $userId = '',
    ): void {
        // Law 4 (fail loud): the bind cannot be served in THIS process — the
        // tunnel registry lives in the :8802 relay worker (see class docblock).
        // Refuse explicitly instead of probing a lookup that is dead by design.
        $logger = LoggerFactory::get(LogChannels::RELAY);
        $logger->warning('Relay: client WS mount refused — client-mount bridge is not available in this hub topology', [
            'server_id' => $serverId,
            'user_id' => $userId !== '' ? $userId : null,
            'reason' => 'client_mount_bridge_unavailable',
            'close_code' => self::CLOSE_INTERNAL_ERROR,
        ]);

        // L-4: real close frame. 1011 (NOT 1013): retrying this surface can
        // never succeed until the topology changes.
        WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL_ERROR);
    }

    /**
     * Handle incoming message from a client connection.
     *
     * Since M2 no mount ever succeeds on this surface, so the bookkeeping maps
     * stay empty and every frame falls into the defensive 1011 rejection below.
     * The dispatch stays wired (ClientRelayWorker routes onMessage here) as the
     * rebuild seam for the owner's architecture decision.
     *
     * @param TcpConnection $connection Client connection.
     * @param string        $data       Raw message data.
     *
     * @return void
     */
    public function onClientMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        $client = self::$connClients[$connId] ?? null;
        $decoder = self::$connDecoders[$connId] ?? null;

        if ($client === null || $decoder === null) {
            // L-4: post-handshake path — real 1011 close frame (the bookkeeping
            // for this connection vanished; nothing sensible left to serve).
            WebSocketCloseFrame::reject($connection, self::CLOSE_INTERNAL_ERROR);
            return;
        }

        $client->onMessage($data, $decoder);
    }

    /**
     * Handle client connection close.
     *
     * @param TcpConnection $connection Client connection.
     *
     * @return void
     */
    public function onClientClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);

        if (isset(self::$connClients[$connId])) {
            self::$connClients[$connId]->onClose();
            unset(self::$connClients[$connId], self::$connDecoders[$connId]);
        }
    }
}
