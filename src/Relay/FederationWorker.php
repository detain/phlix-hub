<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function count;
use function is_string;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function trim;

/**
 * WebSocket worker that handles hub-to-hub federation connections.
 *
 * Leaf hubs connect via WSS to `ws://master-hub:8804/relay/federation/{hub_id}`.
 * This worker is the master-side counterpart that accepts inbound WS
 * connections from federated leaf hubs.
 *
 * Connection lifecycle:
 *   1. WS upgrade — parse `hub_id` from the request path.
 *   2. Validate the hub_id is a known peer in federation_peers.
 *      On failure, close with reason "Unknown hub".
 *   3. On success, delegate to {@see FederationRelayController::onConnect()}.
 *   4. Subsequent text/binary frames dispatched to
 *      {@see FederationRelayController::onMessage()};
 *      close events go to {@see FederationRelayController::onClose()}.
 *
 * @package Phlix\Hub\Relay
 */
final class FederationWorker
{
    /**
     * Default port for federation WS connections (master side).
     */
    public const DEFAULT_PORT = 8804;

    /**
     * Map of connection ID => hub ID, set at WS-connect time so later
     * message/close callbacks know which hub the connection belongs to.
     *
     * @var array<int, string>
     */
    private static array $connHubIds = [];

    /**
     * @param ContainerInterface $container PSR-11 container for resolving services.
     * @param int                $port      Federation WS port (default 8804).
     * @param int                $count     Number of worker processes.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly int $count = 1,
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

        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];

        return $worker;
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
