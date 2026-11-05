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
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

use function count;
use function is_numeric;
use function microtime;
use function round;
use function spl_object_id;

/**
 * Repo-owned deadline between TCP-accept and the WebSocket upgrade.
 *
 * WHY (live-host evidence, .reviews/2026-11-05/HOST-3.md Phase 3 item 5 /
 * flag H3-3): a TLS client that completes the transport handshake but never
 * sends the HTTP `Upgrade` request left the hub holding the inbound socket
 * ~indefinitely (10 min observed on :8802). FIX-HUB-RELAY-OBS established
 * there is NO repo-side timer on this path — the ~2 min cutoff the pre-fix
 * logs seemed to show belonged to the CLIENT destroying its socket, not to
 * any hub policy. Silent sockets are how the incident program lost hours;
 * the hub must not keep them.
 *
 * MECHANISM: an `onConnect` decorator. {@see self::compose()} keeps the
 * surface's declared inbound cap (the inner handler it wraps is always
 * {@see InboundPackageCap::onConnect()}) and additionally arms a ONE-SHOT
 * deadline timer per accepted connection. The deadline covers the whole
 * pre-upgrade window: TCP-accept → (TLS handshake, when `transport=ssl`) →
 * complete HTTP upgrade request → vendor `onWebSocketConnect`. Each governed
 * worker cancels the deadline as the first thing its `onWebSocketConnect`
 * does, and every worker's `onClose` cancels it too (belt: the map must not
 * outlive the connection — `spl_object_id()` is reused after destroy, so a
 * stale entry could silently disarm a NEW connection's deadline; the
 * timer-id identity check inside the armed callback is the second belt).
 *
 * When the deadline fires on a still-silent socket: ONE WARN line on the
 * relay channel (`reason=handshake_timeout`, `remote_ip`, `listener`,
 * `timeout_seconds`, `bytes_read`, `bytes_written`) and a plain
 * `$connection->close()`. Redaction law (F-4): `remote_ip` is the only peer
 * identity logged and NO payload bytes are ever logged — on this path none
 * are trusted to exist in the first place; the transport byte counters are
 * the F-3 fingerprint idiom (ClientHello-only stalls read ~1.5k and write
 * ~0), not payload. The close destroys the socket, which fires the surface's
 * own `onClose` — so the F-3 lifecycle INFO line follows the WARN, giving
 * operators the pair (policy reason + confirmed teardown).
 *
 * WIRE LAW: the guard answers NOTHING on a socket that never completed its
 * upgrade (a pre-`101` close frame would be HTTP-garbage per
 * {@see WebSocketCloseFrame} L-4), so successful clients are untouched
 * byte-for-byte: their timer is cancelled before any response is written.
 * No new close codes, no envelopes.
 *
 * CONFIG GATE: seconds come from `relay.ws_handshake_timeout_seconds`
 * (config/server.php, env `HUB_WS_HANDSHAKE_TIMEOUT_SECONDS`), default 10 s
 * — generous against real TCP+TLS+upgrade round-trips (<1 s in practice),
 * bounded against the 10-minute observed hold. `0` disables the deadline
 * (same "`0` = off" law as `reconnect_drain_grace_seconds` beside it).
 * Federation `:8805` is deliberately NOT governed (owner-reel surface; it
 * keeps its bare {@see InboundPackageCap} connect handler).
 */
final class InboundHandshakeGuard
{
    /**
     * Default pre-upgrade deadline in seconds. Sane bound for
     * TCP + TLS handshake + first HTTP request on a healthy path; the
     * observed stall class holds sockets for MINUTES.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * Armed deadlines keyed by `spl_object_id(TcpConnection)`.
     *
     * @var array<int, int> connection id => one-shot timer id (0 IS a valid
     *                        id: Events\Event/StreamSelect allocate their
     *                        first timer id as 0 — never test these ids for
     *                        truthiness, only null/absence)
     */
    private static array $armed = [];

    /**
     * Not instantiable — static composition helper only.
     */
    private function __construct()
    {
    }

    /**
     * Parse the configured timeout into a trusted deadline in seconds.
     *
     * Boundary parse (config may carry env-string slop): numeric and >= 0 →
     * that many seconds; anything else (missing, non-numeric, NEGATIVE — a
     * typo, not a policy) → the default. `0` is the documented OFF switch and
     * stays 0.
     *
     * @param mixed $raw Raw config value.
     *
     * @return float Deadline seconds; <= 0 means "guard disabled".
     */
    public static function resolveTimeoutSeconds(mixed $raw): float
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        $seconds = (float) $raw;

        return $seconds < 0.0 ? self::DEFAULT_TIMEOUT_SECONDS : $seconds;
    }

    /**
     * Build the `onConnect` handler: run $inner, then arm the deadline.
     *
     * @param callable(TcpConnection): void $inner The surface's existing
     *                                             TCP-accept handler — by
     *                                             law always the declared
     *                                             {@see InboundPackageCap}
     *                                             handler, kept visibly at
     *                                             each assignment site.
     * @param float                         $timeoutSeconds Parsed deadline;
     *                                             <= 0 arms nothing (guard
     *                                             disabled — inner still
     *                                             runs, wire behavior
     *                                             identical to pre-guard
     *                                             builds).
     * @param string                        $listener Human-stable surface
     *                                             label for the WARN line
     *                                             (e.g. "relay-ws :8802").
     *
     * @return callable(TcpConnection): void
     */
    public static function compose(callable $inner, float $timeoutSeconds, string $listener): callable
    {
        return static function (TcpConnection $connection) use ($inner, $timeoutSeconds, $listener): void {
            $inner($connection);

            if ($timeoutSeconds <= 0.0) {
                return;
            }

            self::arm($connection, $timeoutSeconds, $listener);
        };
    }

    /**
     * Cancel this connection's deadline (upgrade arrived, or socket closed).
     *
     * Idempotent: an un-armed or already-cancelled connection is a no-op.
     *
     * @param TcpConnection $connection The upgraded/closed connection.
     *
     * @return void
     */
    public static function cancel(TcpConnection $connection): void
    {
        $connectionId = spl_object_id($connection);
        if (!isset(self::$armed[$connectionId])) {
            return;
        }

        $timerId = self::$armed[$connectionId];
        unset(self::$armed[$connectionId]);
        Timer::del($timerId);
    }

    /**
     * Number of currently-armed deadlines (resident-worker hygiene + test
     * visibility: this must be ~live-silent-connections, never growing).
     */
    public static function armedCount(): int
    {
        return count(self::$armed);
    }

    /**
     * Drop all armed bookkeeping WITHOUT touching live timers.
     *
     * Test isolation only (mirrors {@see RelayWorker::reset()}'s law): the
     * map is process-global static state.
     *
     * @return void
     */
    public static function resetForTests(): void
    {
        self::$armed = [];
    }

    /**
     * Arm the one-shot deadline for one accepted, not-yet-upgraded socket.
     *
     * Contained (R3 survivor-sweep law): a throw from `Timer::add()` outside
     * a running loop must never take the worker down through its accept
     * path — the connection then simply keeps yesterday's behavior (held
     * until peer/vendor teardown) and the failure is logged, never silent.
     *
     * @param TcpConnection $connection Accepted connection (no upgrade yet).
     * @param float         $timeoutSeconds Deadline in seconds (> 0).
     * @param string        $listener   Surface label for the WARN line.
     *
     * @return void
     */
    private static function arm(TcpConnection $connection, float $timeoutSeconds, string $listener): void
    {
        $connectionId = spl_object_id($connection);
        $armedAt = microtime(true);

        /** @var int $timerId */
        $timerId = 0;

        try {
            // One-shot (persistent=false) → vendor routes through the event
            // driver's delay(); every driver in this release can return 0 as
            // a real id, hence the by-reference identity capture below and
            // the `!== null`-style existence checks everywhere.
            $expire = static function () use (
                $connection,
                $connectionId,
                $timeoutSeconds,
                $listener,
                $armedAt,
                &$timerId,
            ): void {
                self::fire($connection, $connectionId, $timeoutSeconds, $listener, $armedAt, $timerId);
            };
            $timerId = Timer::add($timeoutSeconds, $expire, [], false);
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Relay: inbound handshake guard unavailable (timer arm failed)',
                ['listener' => $listener, 'remote_ip' => $connection->getRemoteIp(), 'error' => $e->getMessage()],
            );

            return;
        }

        self::$armed[$connectionId] = $timerId;
    }

    /**
     * Deadline expiry handler — the close-on-silence half of the guard.
     *
     * Two gates before acting: the identity gate (this callback must still
     * own the connection's armed slot — id reuse after destroy would
     * otherwise let a departed connection's timer close its replacement) and
     * the status gate (a socket already tearing down needs no second close,
     * and its own onClose already logged the teardown).
     *
     * @param TcpConnection $connection The guarded connection.
     * @param int           $connectionId spl_object_id captured at arm time.
     * @param float         $timeoutSeconds Configured deadline (log context).
     * @param string        $listener   Surface label (log context).
     * @param float         $armedAt    Microtime at arm (elapsed in log context).
     * @param int           $timerId    This callback's own timer id (by-ref
     *                                  from {@see self::arm()}).
     */
    private static function fire(
        TcpConnection $connection,
        int $connectionId,
        float $timeoutSeconds,
        string $listener,
        float $armedAt,
        int $timerId,
    ): void {
        // Identity gate: the vendor event drivers fire this exactly once, but
        // the map slot may since have been cancelled (upgrade/closed) or
        // re-armed by a connection whose object id was reused. Only the
        // callback still holding the slot may act.
        $held = self::$armed[$connectionId] ?? null;
        if ($held === null || $held !== $timerId) {
            return;
        }

        unset(self::$armed[$connectionId]);

        // Status gate: ESTABLISHED is the only state a silent socket can sit
        // in pre-upgrade; anything else is already teardown.
        if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return;
        }

        LoggerFactory::get(LogChannels::RELAY)->warning(
            'Relay: inbound WS upgrade never arrived — closing on handshake timeout',
            [
                'reason' => 'handshake_timeout',
                'listener' => $listener,
                'remote_ip' => $connection->getRemoteIp(),
                'timeout_seconds' => $timeoutSeconds,
                'elapsed_seconds' => round(microtime(true) - $armedAt, 3),
                // Transport counters (F-3 fingerprint idiom), never payload.
                'bytes_read' => $connection->bytesRead,
                'bytes_written' => $connection->bytesWritten,
            ],
        );

        try {
            // Plain close(): NO WS close frame is possible pre-upgrade (L-4
            // law) and the F-4 envelope family does not apply to a socket
            // that never spoke HTTP. destroy() inside the vendor close path
            // fires the surface's onClose, which logs the F-3 lifecycle line.
            $connection->close();
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error(
                'Relay: handshake-timeout close threw',
                ['listener' => $listener, 'remote_ip' => $connection->getRemoteIp(), 'error' => $e->getMessage()],
            );
        }
    }
}
