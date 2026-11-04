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
 * {@see FederationPushBridge::dispatch()} /
 * {@see FederationPushBridge::dispatchLeaf()}):
 *  - `request_id`   unique per dispatch (hex), echoed in the reply.
 *  - `reply_event`  the publishing worker's own reply event.
 *  - `action`       one of {@see ACTION_OFFER}, {@see ACTION_REVOCATION},
 *                   {@see ACTION_CLOSE_PEER} (master-side, push DOWN to a leaf)
 *                   or {@see ACTION_LEAF_CONNECT}, {@see ACTION_LEAF_DISCONNECT},
 *                   {@see ACTION_LEAF_SHARE_OFFER},
 *                   {@see ACTION_LEAF_SHARE_REVOCATION} (leaf-side, review
 *                   hub__asphp: the leaf link's lifecycle and its UP-pushes are
 *                   owned by the `:8805` worker, so HTTP processes may only
 *                   REQUEST them through this exact boundary).
 *  - `leaf_hub_id`  the connection key (the leaf's own hub uuid), never the
 *                   local peer row id — resolve() already translated. For the
 *                   LEAF family it is exactly `''`: the command addresses the
 *                   command-consuming process's own single outbound link (the
 *                   worker is count=1 — the same law that makes the master-side
 *                   map safe), not one of its inbound peers.
 *  - `payload`      JSON payload for offer/revocation ('' for close_peer,
 *                   leaf_connect and leaf_disconnect; for the leaf share family
 *                   it carries the REQUEST fields — the wire payload and the
 *                   BINARY frame are built WS-side by
 *                   {@see FederationPushDispatcher}/the worker's own
 *                   {@see FederationPeerManager}, so wire-encoding AND
 *                   originating-identity authority stay in the process that
 *                   speaks the socket protocol).
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
     * Establish (or arm) THIS process's single leaf→master link (payload: '').
     *
     * Review hub__asphp (Critical): the admin HTTP worker used to call
     * FederationPeerManager::connectToMaster() on its OWN per-process instance
     * — dialing a rogue second link under this hub's identity, or silently
     * doing nothing to the worker's real one. The intent now crosses this
     * boundary and the `:8805` worker executes it on the instance that owns
     * the link; `delivered` answers the measured state (link live OR a re-dial
     * armed), never a hope.
     */
    public const ACTION_LEAF_CONNECT = 'leaf_connect';

    /**
     * Tear THIS process's leaf link down and stop the reconnect ladder
     * (payload: ''). `delivered` is the measured "no live socket and no armed
     * re-dial" after the worker runs disconnectFromMaster() (M-7 law applies
     * inside the owner, where it can actually hold).
     */
    public const ACTION_LEAF_DISCONNECT = 'leaf_disconnect';

    /**
     * Push an outgoing-share offer UP to the master over the worker's verified
     * leaf link (payload: the REQUEST fields
     * `{"share_id":"<uuid>","library_id":"<uuid>","library_name":"...","permission":"read|readwrite"}`).
     * The worker builds the wire payload — including the M-5 originating
     * `peer_id` — itself; `delivered` is the frame's send verdict, and a
     * missing/unverified link refuses LOUD (review hub__asphp: the silent
     * no-op return is the defect this retires).
     */
    public const ACTION_LEAF_SHARE_OFFER = 'leaf_share_offer';

    /**
     * Push a share revocation UP to the master (payload:
     * `{"share_id":"<uuid>"}`). Same loud-failure law as
     * {@see ACTION_LEAF_SHARE_OFFER}.
     */
    public const ACTION_LEAF_SHARE_REVOCATION = 'leaf_share_revocation';

    /**
     * The leaf-command family: envelope law is `leaf_hub_id === ''` and the
     * lifecycle verbs carry `payload === ''` (see the class docblock).
     *
     * @var list<string>
     */
    public const LEAF_ACTIONS = [
        self::ACTION_LEAF_CONNECT,
        self::ACTION_LEAF_DISCONNECT,
        self::ACTION_LEAF_SHARE_OFFER,
        self::ACTION_LEAF_SHARE_REVOCATION,
    ];

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
