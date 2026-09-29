<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Workerman\Connection\TcpConnection;

/**
 * Builds RFC 6455 close frames and rejects connections with a REAL close code.
 *
 * L-4: the relay workers used to reject pre-upgrade connections with
 * `$connection->close((string) 4401, true)` — inside Workerman's
 * `Websocket::dealHandshake()` that fires BEFORE the `101 Switching Protocol`
 * response is emitted, so the raw ASCII digits `"4401"` were written onto a
 * socket the peer still treats as plain HTTP: the client sees a garbage status
 * line and `close((string)code)` never produces a WebSocket close code at all.
 *
 * A WS close frame is only meaningful after the upgrade, so rejection here is
 * two-phase when the handshake has not completed yet: let Workerman finish the
 * `101` (arming `onWebSocketConnected`, which it fires immediately after the
 * response — vendor `Protocols/Websocket.php`), then close with the proper
 * frame. Clients then observe the documented code (4401 / 1013 / 1011) on their
 * `close` event instead of a transport error, exactly as the wire contract
 * promises (RFC 6455 §5.5.1, §7.4.1).
 *
 * The frame layout mirrors the vendor's own server close
 * (`$connection->close("\x88\x02\x03\xe8", true)` for code 1000): FIN set,
 * opcode 8, unmasked (server→client), 2-byte big-endian status, no reason.
 *
 * @package Phlix\Hub\Relay
 * @since 0.12.0
 */
final class WebSocketCloseFrame
{
    /** Lowest close code a peer may legitimately send (RFC 6455 §7.4). */
    private const MIN_CLOSE_CODE = 1000;

    /** Highest close code within the reserved range (RFC 6455 §7.4.2). */
    private const MAX_CLOSE_CODE = 4999;

    /**
     * Not instantiable — static factory only.
     */
    private function __construct()
    {
    }

    /**
     * Encode a server→client WS close frame carrying $code.
     *
     * @param int $code Close code in 1000..4999 (e.g. 1000, 1011, 1013, 4401).
     *
     * @return string The 4-byte wire frame: [0x88][0x02][code hi][code lo].
     *
     * @throws InvalidArgumentException If $code is outside the valid range.
     */
    public static function bytes(int $code): string
    {
        if ($code < self::MIN_CLOSE_CODE || $code > self::MAX_CLOSE_CODE) {
            throw new InvalidArgumentException(
                sprintf(
                    'WebSocket close code %d out of range %d..%d',
                    $code,
                    self::MIN_CLOSE_CODE,
                    self::MAX_CLOSE_CODE,
                ),
            );
        }

        return "\x88\x02" . pack('n', $code);
    }

    /**
     * Close $connection delivering the real WS close code to the peer.
     *
     * Post-handshake connections get the close frame immediately. Pre-handshake
     * ones (the common rejection point — auth/rate-limit checks run in
     * `onWebSocketConnect`) have `onWebSocketConnected` armed so the frame
     * follows the `101` byte-for-byte; closing with a raw frame any earlier
     * would corrupt the HTTP response the client is still parsing.
     *
     * @param TcpConnection $connection The connection to reject.
     * @param int           $code       Close code to deliver (see {@see bytes()}).
     *
     * @return void
     */
    public static function reject(TcpConnection $connection, int $code): void
    {
        $frame = self::bytes($code);

        if (!empty($connection->context->websocketHandshake)) {
            $connection->close($frame, true);
            return;
        }

        // Pre-upgrade: a close frame before the 101 is not a WS close at all.
        // Finish the handshake, then close with the documented code — one extra
        // event-loop tick at most, and the peer's close event carries the real
        // code instead of a transport failure.
        $connection->onWebSocketConnected = static function (TcpConnection $connection) use ($frame): void {
            $connection->close($frame, true);
        };
    }
}
