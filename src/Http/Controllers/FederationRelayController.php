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
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\ConnectionInterface;

use function is_string;
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
 *   - Dispatches text frames (HELLO) to {@see FederationFrameHandler::handleTextFrame()}
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
     * frame prefix per the RelayFrame wire format). Text frames carry
     * HELLO/HELLO_ACK JSON; binary frames carry relay frames (HEARTBEAT, etc.).
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
            $error = $this->frameHandler->handleTextFrame($hubId, $data);
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
     * Handle a federation connection close.
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
            $this->connMgr->removeConnection($hubId);
            unset(self::$connHubIds[$connId], self::$connDecoders[$connId]);
        }
    }

    /**
     * Determine whether an incoming frame payload is a text (JSON) frame.
     *
     * Text frames are JSON strings (UTF-8) that start with '{' or '['.
     * Binary frames are the RelayFrame binary encoding (always start with a
     * non-UTF-8 null byte in practice, or simply are not valid UTF-8 JSON).
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
        // sequence number. If the first byte is 0x00 and the string is short,
        // it's likely a binary frame. More reliably: check for valid UTF-8 JSON.
        $firstByte = ord($data[0]);

        // If first byte is 0x00 and data is at least 7 bytes (minimum frame header),
        // it's almost certainly a binary RelayFrame (sequence number starts with 0).
        if ($firstByte === 0x00 && strlen($data) >= 7) {
            return false;
        }

        // Otherwise check if it's valid UTF-8 JSON (starts with { or [)
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($data, false, 2);
            return is_array($decoded) || is_scalar($decoded);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Clear static connection maps.
     *
     * Intended for test isolation only.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connHubIds = [];
        self::$connDecoders = [];
    }
}
