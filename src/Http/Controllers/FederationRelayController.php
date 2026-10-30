<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Throwable;
use Workerman\Connection\ConnectionInterface;

use function json_decode;
use function ord;
use function spl_object_id;

/**
 * Master-side WebSocket handler for hub-to-hub federation connections.
 *
 * Handles inbound WS connections from leaf hubs at `/relay/federation/{hub_id}`.
 * The connection is validated at WS upgrade time by the owning
 * {@see FederationWorker}. Once connected, this controller:
 *   - Stores the connection in {@see FederationConnectionManager}
 *   - Dispatches handshake text frames (inbound `hub_hello` /
 *     `hub_hello_auth`) to {@see FederationFrameHandler::handleTextFrame()}
 *   - Dispatches binary frames (HEARTBEAT, etc.) to {@see FederationFrameHandler::handleBinaryFrame()}
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class FederationRelayController
{
    /**
     * Map of connection ID → hub ID for active federation connections.
     *
     * @var array<int, string>
     */
    private static array $connHubIds = [];

    /**
     * @var array<int, FrameDecoder>
     */
    private static array $connDecoders = [];

    public function __construct(
        private readonly FederationFrameHandler $frameHandler,
        private readonly FederationConnectionManager $connMgr,
    ) {
    }

    /**
     * Handle a new federation WebSocket connection from a leaf hub.
     *
     * Called by {@see FederationWorker::onWebSocketConnect()} after the
     * worker has validated the hub_id is a known peer. Stores the
     * connection and prepares for incoming frames.
     *
     * @param ConnectionInterface $connection Workerman WS connection.
     * @param string              $hubId       Leaf hub UUID from the route path.
     *
     * @return void
     */
    public function onConnect(ConnectionInterface $connection, string $hubId): void
    {
        $connId = spl_object_id($connection);

        // Store hubId → connection mapping
        self::$connHubIds[$connId] = $hubId;
        self::$connDecoders[$connId] = new FrameDecoder();

        // Register in the connection manager
        $this->connMgr->addConnection($hubId, $connection);
    }

    /**
     * Handle an incoming WebSocket message from a federation peer.
     *
     * Detects text vs binary by checking the first byte (null byte = binary
     * frame prefix per the RelayFrame wire format). Text frames carry the
     * `hub_hello`-family handshake JSON (`hub_hello` / `hub_hello_auth` inbound);
     * binary frames carry relay frames (HEARTBEAT, etc.).
     *
     * @param ConnectionInterface $connection WS connection that received the frame.
     * @param string              $data       Raw frame payload.
     *
     * @return void
     */
    public function onMessage(ConnectionInterface $connection, string $data): void
    {
        $connId = spl_object_id($connection);
        $hubId = self::$connHubIds[$connId] ?? null;

        if ($hubId === null) {
            $connection->close('unknown_connection');
            return;
        }

        // Detect text vs binary frame
        // Text JSON frames: first byte is not \x00
        // Binary relay frames: first byte is \x00 (the encoded seq number starts with 0x00 often)
        // More precisely: check if the payload is valid UTF-8 JSON starting with '{'
        if ($this->isTextFrame($data)) {
            // The arriving connection object rides along: the H-4 `hub_hello_auth`
            // proof is bound to its carrier socket, so the handler can refuse
            // a proof delivered over a superseded connection.
            $error = $this->frameHandler->handleTextFrame($hubId, $data, $connection);
            if ($error !== null) {
                $connection->close($error);
            }
        } else {
            $this->handleBinaryMessage($connId, $hubId, $connection, $data);
        }
    }

    /**
     * Handle a binary WebSocket message using the frame decoder.
     *
     * @param int                 $connId     Connection ID.
     * @param string             $hubId      Leaf hub UUID.
     * @param ConnectionInterface $connection WS connection.
     * @param string             $data       Raw binary data.
     *
     * @return void
     */
    private function handleBinaryMessage(
        int $connId,
        string $hubId,
        ConnectionInterface $connection,
        string $data,
    ): void {
        $decoder = self::$connDecoders[$connId] ?? null;
        if ($decoder === null) {
            $connection->close('internal_error');
            return;
        }

        try {
            $frame = $decoder->decode($data);
        } catch (InvalidFrameTypeException) {
            // Undecodable frame or a decode-buffer overflow (H-R7) from a leaf
            // hub. Escaping here would fatal out of the Workerman callback; close
            // the federation connection cleanly instead.
            $connection->close('invalid_frame');
            return;
        }
        if ($frame === null) {
            // Incomplete frame — wait for more data
            return;
        }

        $this->frameHandler->handleBinaryFrame($hubId, $frame->payload, $frame->type->value);
    }

    /**
     * Determine whether an incoming frame payload is a text (JSON) frame.
     *
     * Text frames are valid UTF-8 JSON objects/arrays (the handshake
     * messages are all JSON objects). The assoc flag is load-bearing:
     * `json_decode(..., false)` hands back a stdClass for every object and
     * an array-or-scalar acceptance test then silently misroutes EVERY
     * hub_hello into the binary decoder — the master-side twin of the
     * leaf-side defect fixed at 519c0c0 (`FederationPeerManager::isTextFrame`).
     * Acceptance after assoc decoding is `is_array`, which covers both JSON
     * objects and JSON arrays; bare scalars (`123`, `"x"`) are NOT handshake
     * text and fall to the binary decoder, whose refusal is the honest path
     * (the pre-fix `is_scalar` arm invited them into the handshake instead).
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

        // Binary frames per the RelayFrame spec start with a 4-byte big-endian
        // sequence number: a 0x00 first byte with at least the 7-byte minimum
        // frame header is a binary RelayFrame (the leaf-side twin keeps this
        // exact guard).
        $firstByte = ord($data[0]);

        // If first byte is 0x00 and data is at least 7 bytes (minimum frame header),
        // it's almost certainly a binary RelayFrame (sequence number starts with 0).
        if ($firstByte === 0x00 && strlen($data) >= 7) {
            return false;
        }

        // Otherwise it must parse as a JSON object/array to be handshake text.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Handle a federation connection close.
     *
     * M-6: removal goes through the frame handler's IDENTITY-checked path
     * (the closing object must still be the registered one) so a late close
     * from a superseded connection neither unmaps the new link nor marks the
     * peer disconnected; live session rows are closed and the peer is marked
     * 'disconnected' so DB state converges with the socket state.
     *
     * @param ConnectionInterface $connection WS connection that closed.
     *
     * @return void
     */
    public function onClose(ConnectionInterface $connection): void
    {
        $connId = spl_object_id($connection);
        $hubId = self::$connHubIds[$connId] ?? null;

        if ($hubId !== null) {
            $this->frameHandler->handleConnectionClosed($hubId, $connection);
            unset(self::$connHubIds[$connId], self::$connDecoders[$connId]);
        }
    }
}
