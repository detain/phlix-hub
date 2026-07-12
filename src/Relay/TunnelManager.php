<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Generator;
use Workerman\Connection\TcpConnection;

use function gethostname;
use function is_string;

/**
 * Manages all active relay tunnels between the hub and servers.
 *
 * Provides:
 *   - Registration of new server tunnels via acceptServer()
 *   - Lookup of tunnels by server ID
 *   - Client connection routing via acceptClient()
 *   - Tunnel lifecycle (close, reaper)
 *
 * @package Phlix\Hub\Relay
 */
final class TunnelManager implements TunnelManagerInterface
{
    /**
     * @param RelaySessionManager       $sessionManager Session manager for byte accounting.
     * @param RelayWireCodecInterface   $codec         Wire codec for frame encoding/decoding.
     * @param StructuredLogger          $logger        Structured logger for relay events.
     * @param EnrollmentJwtService|null $jwtService    Enrollment-JWT validator passed to each tunnel
     *                                                 so HELLO frames are cryptographically verified.
     *                                                 Null (test-only) skips validation.
     * @param float                     $reconnectDrainGraceSeconds Grace window (seconds) an incumbent
     *                                                 tunnel keeps draining in-flight requests after a
     *                                                 VALIDATED reconnect displaces it (H-R6). `<= 0`
     *                                                 disables the drain (immediate hard displacement).
     */
    public function __construct(
        private readonly RelaySessionManager $sessionManager,
        private readonly RelayWireCodecInterface $codec,
        private readonly StructuredLogger $logger,
        private readonly ?EnrollmentJwtService $jwtService = null,
        private readonly float $reconnectDrainGraceSeconds = self::DEFAULT_RECONNECT_DRAIN_GRACE_SECONDS,
    ) {
        $this->tunnels = [];
    }

    /**
     * Default reconnect-drain grace period (seconds). A validated server
     * reconnect lets the incumbent tunnel finish delivering in-flight responses
     * for this long before the hard close, so a deploy/network blip does not
     * instantly kill active playback (H-R6).
     */
    public const float DEFAULT_RECONNECT_DRAIN_GRACE_SECONDS = 5.0;

    /**
     * @var array<string, Tunnel> Active tunnels keyed by server ID. This is the
     *      routing map: {@see getTunnelForServer()} reads it, and a live
     *      incumbent stays here (reachable) until a reconnecting tunnel's HELLO
     *      JWT validates.
     */
    private array $tunnels;

    /**
     * New, UNVALIDATED tunnels awaiting HELLO-JWT validation, keyed by server ID.
     *
     * When a HELLO arrives for a server_id that already has a live tunnel, the
     * new tunnel is parked here — NOT placed in {@see $tunnels} and NOT allowed
     * to displace the incumbent — until its enrollment JWT is validated in
     * {@see finalizeServerConnection()}. If validation fails,
     * {@see abortPendingConnection()} discards it and the incumbent is never
     * touched. This closes the unauthenticated tunnel-displacement DoS
     * (HB-2.2 / H-H1): displacement is gated on validation, not on the mere
     * arrival of a HELLO.
     *
     * @var array<string, Tunnel>
     */
    private array $pendingTunnels = [];

    /**
     * @var RelayProxyManager|null Proxy manager passed to each tunnel for HTTP-over-relay.
     */
    private ?RelayProxyManager $proxyManager = null;

    /**
     * Set the relay proxy manager that tunnels route HTTP_RESPONSE frames to.
     *
     * Wired once at relay-worker startup (it depends on this manager, so it is
     * injected after construction to avoid a circular dependency).
     *
     * @param RelayProxyManager $proxyManager The proxy manager.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function setProxyManager(RelayProxyManager $proxyManager): void
    {
        $this->proxyManager = $proxyManager;
    }

    /**
     * Accept a new server connection and create a tunnel (still in PENDING).
     *
     * The returned tunnel has NOT yet displaced any incumbent. If a live tunnel
     * already serves this server_id, the new tunnel is parked in
     * {@see $pendingTunnels} and the incumbent is LEFT in the routing map, fully
     * reachable, until the new tunnel's HELLO enrollment JWT validates (see
     * {@see finalizeServerConnection()} / {@see abortPendingConnection()}). This
     * closes the unauthenticated tunnel-displacement DoS (HB-2.2 / H-H1): an
     * invalid HELLO with a known server_id can no longer evict a healthy tunnel.
     *
     * When there is no live incumbent, the new tunnel becomes the routing entry
     * directly (there is nothing to protect); a failed HELLO then removes it via
     * {@see abortPendingConnection()}.
     *
     * @param string      $serverId Server UUID from the HELLO handshake.
     * @param TcpConnection $serverWs Workerman connection to the server.
     *
     * @return Tunnel The newly created tunnel (in PENDING state until HELLO is received).
     */
    public function acceptServer(string $serverId, TcpConnection $serverWs): Tunnel
    {
        $hostname = @gethostname();
        /** @var non-falsy-string $workerNode */
        $workerNode = is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';

        $tunnel = new Tunnel(
            $serverId,
            $serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            $this->jwtService,
            $this->proxyManager,
        );

        $incumbent = $this->tunnels[$serverId] ?? null;
        if ($incumbent !== null && $incumbent->status !== Tunnel::STATUS_CLOSED) {
            // A live tunnel already serves this server. Park the new (still
            // UNVALIDATED) tunnel and leave the incumbent routable. It is only
            // displaced once this tunnel's HELLO JWT validates.
            $priorPending = $this->pendingTunnels[$serverId] ?? null;
            if ($priorPending !== null && $priorPending->status !== Tunnel::STATUS_CLOSED) {
                // Rapid re-HELLO before the previous one resolved: discard the
                // older pending tunnel (never promoted, nothing routes to it).
                $priorPending->close('superseded_pending');
            }
            $this->pendingTunnels[$serverId] = $tunnel;

            $this->logger->info('Relay: HELLO for server with live tunnel; parked pending JWT validation', [
                'server_id' => $serverId,
                'tunnel_id' => $tunnel->tunnelId,
                'incumbent_tunnel_id' => $incumbent->tunnelId,
                'worker_node' => $workerNode,
            ]);

            return $tunnel;
        }

        // No live incumbent — the new tunnel owns the routing slot directly.
        $this->tunnels[$serverId] = $tunnel;

        $this->logger->info('Relay: server accepted, tunnel created', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'worker_node' => $workerNode,
        ]);

        return $tunnel;
    }

    /**
     * Get the tunnel for a given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return Tunnel|null The tunnel if found and active, null otherwise.
     */
    public function getTunnelForServer(string $serverId): ?Tunnel
    {
        return $this->tunnels[$serverId] ?? null;
    }

    /**
     * Accept a new client connection and attach it to the appropriate tunnel.
     *
     * @param string      $serverId Server UUID the client wants to connect to.
     * @param TcpConnection $clientWs Workerman connection to the client.
     * @param string      $clientId Client UUID assigned by the hub.
     * @param string      $sessionId Optional relay session ID for this client.
     *
     * @return ClientConnection|null The created ClientConnection, or null if tunnel not found.
     */
    public function acceptClient(
        string $serverId,
        TcpConnection $clientWs,
        string $clientId,
        string $sessionId = '',
    ): ?ClientConnection {
        $tunnel = $this->getTunnelForServer($serverId);

        if ($tunnel === null) {
            $this->logger->warning('Relay: client connection rejected, server not connected', [
                'server_id' => $serverId,
                'client_id' => $clientId,
            ]);
            return null;
        }

        if ($tunnel->status !== Tunnel::STATUS_ACTIVE) {
            $this->logger->warning('Relay: client connection rejected, tunnel not active', [
                'server_id' => $serverId,
                'tunnel_id' => $tunnel->tunnelId,
                'client_id' => $clientId,
                'tunnel_status' => $tunnel->status,
            ]);
            return null;
        }

        $client = new ClientConnection($clientWs, $serverId, $clientId, $this->logger, $sessionId);
        $client->tunnel = $tunnel;
        $tunnel->registerClient($client);

        $this->logger->info('Relay: client connected to tunnel', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'client_id' => $clientId,
        ]);

        return $client;
    }

    /**
     * Close a tunnel by server ID.
     *
     * Marks the tunnel as closed, sends TYPE_DISCONNECTED to all clients,
     * closes the server connection, and removes the tunnel from the map.
     *
     * @param string $serverId Server UUID.
     * @param string $reason   Human-readable close reason.
     *
     * @return void
     */
    public function closeTunnel(string $serverId, string $reason): void
    {
        $tunnel = $this->tunnels[$serverId] ?? null;

        if ($tunnel === null) {
            return;
        }

        $tunnel->close($reason);

        unset($this->tunnels[$serverId]);

        $this->logger->info('Relay: tunnel closed and removed', [
            'server_id' => $serverId,
            'tunnel_id' => $tunnel->tunnelId,
            'reason' => $reason,
        ]);
    }

    /**
     * Promote a validated tunnel and displace any incumbent.
     *
     * Called by {@see RelayWorker::handleHello()} ONLY after the tunnel's
     * `onServerMessage()` transitioned it to ACTIVE — i.e. its HELLO enrollment
     * JWT validated. The parked (pending) tunnel is swapped into the routing map
     * and the incumbent (if any) is displaced. Displacement uses a bounded
     * reconnect-drain (H-R6): the incumbent keeps delivering in-flight responses
     * for {@see $reconnectDrainGraceSeconds} before its hard close, so a
     * legitimate reconnect (deploy/network blip) does not instantly kill
     * playback.
     *
     * Safe to call when there is no pending tunnel (fresh connection): the new
     * tunnel is already the map entry and there is nothing to displace.
     *
     * @param string $serverId Server UUID.
     *
     * @return void
     */
    public function finalizeServerConnection(string $serverId): void
    {
        $pending = $this->pendingTunnels[$serverId] ?? null;
        if ($pending === null) {
            // Fresh connection: the validated tunnel is already the routing
            // entry (placed by acceptServer) and there is nothing to displace.
            return;
        }
        unset($this->pendingTunnels[$serverId]);

        $incumbent = $this->tunnels[$serverId] ?? null;

        // Promote the now-VALIDATED tunnel into the routing map. From here new
        // clients and proxy requests route to it.
        $this->tunnels[$serverId] = $pending;

        if ($incumbent !== null && $incumbent !== $pending && $incumbent->status !== Tunnel::STATUS_CLOSED) {
            $this->logger->info('Relay: JWT validated, draining + displacing incumbent tunnel', [
                'server_id' => $serverId,
                'incumbent_tunnel_id' => $incumbent->tunnelId,
                'new_tunnel_id' => $pending->tunnelId,
                'grace_seconds' => $this->reconnectDrainGraceSeconds,
            ]);
            $incumbent->beginDrain($this->reconnectDrainGraceSeconds, 'server_replaced');
        }
    }

    /**
     * Discard a tunnel whose HELLO failed validation, leaving the incumbent live.
     *
     * Called by {@see RelayWorker::handleHello()} when `onServerMessage()` did
     * NOT bring the tunnel to ACTIVE (invalid/absent enrollment JWT, malformed
     * HELLO). The rejected tunnel is removed from wherever it was parked; a live
     * incumbent stays in the routing map, untouched and reachable — it is NEVER
     * displaced by an unvalidated HELLO. This is the core of the HB-2.2 fix.
     *
     * @param string $serverId Server UUID.
     * @param Tunnel $rejected The tunnel that failed HELLO validation.
     *
     * @return void
     */
    public function abortPendingConnection(string $serverId, Tunnel $rejected): void
    {
        if (($this->pendingTunnels[$serverId] ?? null) === $rejected) {
            unset($this->pendingTunnels[$serverId]);
        }

        // Fresh-connection case: the rejected tunnel was placed directly in the
        // routing map (no incumbent). Drop it so getTunnelForServer() does not
        // return a dead tunnel. A live incumbent in the map is left in place.
        if (($this->tunnels[$serverId] ?? null) === $rejected) {
            unset($this->tunnels[$serverId]);
        }

        // Tunnel::handleHelloFrame already closed it (close('unauthorized')).
        // close() is idempotent on CLOSED; this is a defensive no-op if so.
        if ($rejected->status !== Tunnel::STATUS_CLOSED) {
            $rejected->close('unauthorized');
        }

        $this->logger->info('Relay: HELLO rejected; incumbent (if any) left intact', [
            'server_id' => $serverId,
            'tunnel_id' => $rejected->tunnelId,
        ]);
    }

    /**
     * Get all active tunnels as a generator.
     *
     * Yields [serverId => Tunnel] for all tunnels in ACTIVE status.
     * Used by heartbeat timer and idle reaper to iterate without modifying
     * the underlying array during iteration.
     *
     * @return Generator<string, Tunnel>
     */
    public function allTunnels(): Generator
    {
        foreach ($this->tunnels as $serverId => $tunnel) {
            if ($tunnel->status === Tunnel::STATUS_ACTIVE) {
                yield $serverId => $tunnel;
            }
        }
    }

    /**
     * Get the count of active tunnels.
     *
     * @return int Number of active tunnels.
     */
    public function getActiveTunnelCount(): int
    {
        $count = 0;
        foreach ($this->tunnels as $tunnel) {
            if ($tunnel->status === Tunnel::STATUS_ACTIVE) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Check if an active tunnel exists for the given server ID.
     *
     * @param string $serverId Server UUID.
     *
     * @return bool True if an active tunnel exists.
     */
    public function hasTunnel(string $serverId): bool
    {
        return isset($this->tunnels[$serverId])
            && $this->tunnels[$serverId]->status === Tunnel::STATUS_ACTIVE;
    }

    /**
     * Remove a tunnel from the manager (called after cleanup).
     *
     * @param string $serverId Server UUID.
     *
     * @return void
     */
    public function removeTunnel(string $serverId): void
    {
        unset($this->tunnels[$serverId]);
    }
}
