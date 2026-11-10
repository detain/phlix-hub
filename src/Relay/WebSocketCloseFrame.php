<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Throwable;
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
 * The reason-free shape is deliberate (review hub__enduser Info / HUB-27,
 * 2026-11-04; RFC 6455 permits it): the hub widens no wire surface even on
 * close, so browser devtools show the peer a bare number (4401 / 1013 /
 * 1011). Its human meaning lives in two places: the close-code table of
 * docs/websockets.md (the "Rejection close codes" section) and the docblock
 * at the rejecting emitter.
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
     * Both arms contain a throwing `close()` into one attributed error
     * line (R3 survivor sweep, review hub__asphp F-R3-4): the call runs on
     * a resident worker's event loop, and an escaping dead-socket throw
     * would take the worker — and every connection it serves — down.
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
            // R3 survivor sweep (review hub__asphp F-R3-4): the SAME
            // close()-may-throw vector as the deferred arm below, on the
            // SAME object — and this is the arm the frame-budget rejection
            // actually reaches, from FederationWorker::onMessage OUTSIDE
            // that method's dispatch try. Contain it identically: attribute
            // one error line, swallow, keep the resident worker alive.
            try {
                $connection->close($frame, true);
            } catch (Throwable $e) {
                LoggerFactory::get(LogChannels::RELAY)->error(
                    'WebSocketCloseFrame: rejection close failed — contained',
                    [
                        'code'  => $code,
                        'error' => $e->getMessage(),
                    ],
                );
            }

            return;
        }

        // Pre-upgrade: a close frame before the 101 is not a WS close at all.
        // Finish the handshake, then close with the documented code — one extra
        // event-loop tick at most, and the peer's close event carries the real
        // code instead of a transport failure.
        //
        // R2 survivor sweep: this arm fires from Workerman's callback funnel
        // on a RESIDENT worker — a close() that throws there (socket already
        // dead mid-teardown) would escape the arm and take the whole worker
        // down, converting a refused peer into collateral for every other
        // connection it serves. Contain it: attribute one error line, swallow.
        $connection->onWebSocketConnected = static function (TcpConnection $connection) use ($frame, $code): void {
            try {
                $connection->close($frame, true);
            } catch (Throwable $e) {
                LoggerFactory::get(LogChannels::RELAY)->error(
                    'WebSocketCloseFrame: rejection arm close failed — contained',
                    [
                        'code'  => $code,
                        'error' => $e->getMessage(),
                    ],
                );
            }
        };
    }
}
