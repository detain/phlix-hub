<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

/**
 * Shared constants for the cross-process federation master push (owner decision,
 * the topology note left open by the H-4/H-5 rework rounds).
 *
 * ## The process boundary this exists to cross
 *
 * The admin controller writes share/peer truth from an HTTP worker (`:8800`), but
 * the leaf's live WebSocket — and the H-4 verified stamp that authorises any push
 * over it — lives in the {@see \Phlix\Hub\Relay\FederationWorker} process
 * (`:8805`, `count = 1`). `FederationConnectionManager` is per-process, so the
 * pusher's in-process send() could never see those sockets. This protocol carries
 * the INTENT (action + target + payload) across the same `workerman/channel`
 * broker {@see \Phlix\Hub\Relay\RelayProxyProtocol} uses for the HTTP-over-relay
 * proxy; the `:8805` process applies its OWN verified-channel law before a byte
 * touches a socket. Transport moves intent, never authority.
 *
 * ## Command / reply shapes
 *
 * Command (published on {@see COMMAND_EVENT} by
 * {@see FederationPushBridge::dispatch()}):
 *  - `request_id`   unique per dispatch (hex), echoed in the reply.
 *  - `reply_event`  the publishing worker's own reply event.
 *  - `action`       one of {@see ACTION_OFFER}, {@see ACTION_REVOCATION},
 *                   {@see ACTION_CLOSE_PEER}.
 *  - `leaf_hub_id`  the connection key (the leaf's own hub uuid), never the
 *                   local peer row id — resolve() already translated.
 *  - `payload`      JSON payload for offer/revocation ('' for close_peer). The
 *                   BINARY frame is encoded WS-side by {@see FederationPushDispatcher}
 *                   so wire-encoding authority stays in the process that speaks
 *                   the socket protocol.
 *
 * Reply (published on the command's `reply_event`):
 *  - `request_id`   echo of the command's id.
 *  - `delivered`    bool: did the frame demonstrably go out (or, for
 *                   close_peer, is the link verifiably torn down)? Refusals
 *                   (unverified channel) and misses (no connection) reply false —
 *                   a measured fact, not a fabrication. A command the dispatcher
 *                   never understood gets NO reply at all, and the publisher's
 *                   timeout degrades honestly to false (same doctrine as
 *                   {@see \Phlix\Hub\SyncPlay\PendingCommandDispatcher}).
 *
 * @package Phlix\Hub\Federation
 */
final class FederationPushProtocol
{
    /**
     * Channel event HTTP workers publish federation push commands on; the
     * `:8805` federation worker subscribes to it.
     */
    public const COMMAND_EVENT = 'phlix.federation.master_push.command';

    /**
     * Push a new outgoing-share offer (payload: `{"shares":[{...}]}`).
     */
    public const ACTION_OFFER = 'offer';

    /**
     * Push a share revocation (payload: `{"share_id":"<uuid>"}`).
     */
    public const ACTION_REVOCATION = 'revocation';

    /**
     * Goodbye + unmap + close the peer's live WS (payload: '').
     */
    public const ACTION_CLOSE_PEER = 'close_peer';

    /**
     * How long an HTTP worker waits for the delivery reply.
     *
     * NOT Alexa-budgeted like the SyncPlay pending command — this is the admin
     * path (share create/revoke, peer delete). It is kept short on purpose: the
     * DB truth is already committed when the command is published, so a dead or
     * absent `:8805` process must not hang the admin request; the leaf converges
     * on its next hello replay either way.
     */
    public const REPLY_TIMEOUT_SECONDS = 2.0;

    private function __construct()
    {
    }
}
