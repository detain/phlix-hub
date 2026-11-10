<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Workerman\Connection\TcpConnection;

/**
 * Explicit inbound package cap for the four WebSocket surfaces (:8802 relay,
 * :8803 client-relay, :8804 SyncPlay, :8805 federation) — review Minor 57.
 *
 * Before this module the WS workers silently INHERITED Workerman's
 * `TcpConnection::$defaultMaxPackageSize` — the per-connection inbound
 * reassembly bound every queue derivation depends on lived only in vendor
 * source, so a vendor default change would silently move the memory bound
 * while every hub-side cap (Tunnel's 256-frame queues, the SyncPlay message
 * caps) kept asserting the old arithmetic. The HTTP surface already pins its
 * cap explicitly onto each accepted connection (M-HUB-30,
 * `Application::MAX_REQUEST_BODY_BYTES`); this is the WS half of the same law.
 *
 * {@see self::onConnect()} returns the callback to assign to each worker's
 * `$worker->onConnect` (the TCP-accept slot — free on all four workers: the
 * websocket protocol speaks through `onWebSocketConnect`, never `onConnect`).
 *
 * Behavior-preserving by construction: the pinned value IS the current vendor
 * default (10 MiB), so no live socket changes shape today — the pin exists to
 * make the bound ours, declared, and the derivations below stable:
 *  - inbound reassembly ≤ MAX_INBOUND_PACKAGE_BYTES per connection;
 *  - with the websocket protocol deframing under the same bound, every queued
 *    Tunnel frame is ≤ that size, so Tunnel's frame-COUNT caps
 *    (MAX_HIGH_PRIORITY_QUEUE / MAX_BODY_QUEUE / MAX_CLIENT_QUEUE × 10 MiB)
 *    become a computable worst-case residency instead of an open question.
 *
 * Overflow posture (vendor, unchanged): `Input` refuses to buffer past the
 * cap and the connection is closed — the bound lives at the boundary, never
 * inside handler memory.
 */
final class InboundPackageCap
{
    /**
     * 10 MiB — equals the vendor `$defaultMaxPackageSize` this release ships
     * (verified against Workerman source in the Minor 57 test; a vendor drift
     * there is a DISCLOSED decision, not a silent move).
     */
    public const MAX_INBOUND_PACKAGE_BYTES = 10485760;

    /**
     * The `onConnect` handler: lands the declared cap on the accepted
     * connection itself, mirroring the HTTP worker's pin idiom.
     *
     * @return callable(TcpConnection): void
     */
    public static function onConnect(): callable
    {
        return static function (TcpConnection $connection): void {
            $connection->maxPackageSize = self::MAX_INBOUND_PACKAGE_BYTES;
        };
    }
}
