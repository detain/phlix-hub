<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

/**
 * Shared constants for the cross-process "pending command" push (S93).
 *
 * ## The process boundary this exists to cross
 *
 * An Alexa utterance lands on an HTTP worker. The live SyncPlay client sockets
 * live in {@see SyncPlayRelayWorker}, a SEPARATE Workerman process (`count = 1`),
 * whose `self::$clients` map is per-process and therefore invisible to every HTTP
 * worker. The established transport between the two in this repo is
 * `workerman/channel` — the same broker
 * {@see \Phlix\Hub\Relay\RelayProxyProtocol} uses for the HTTP-over-relay proxy.
 * An HTTP worker publishes on {@see PUSH_EVENT}; the `:8804` worker subscribes,
 * writes the frame to the matching sockets, and publishes the DELIVERED COUNT
 * back on the per-request `reply_event` carried in that message.
 *
 * ## What actually consumes the frame today: nothing
 *
 * Verified 2026-08-08 across every client repo: **no client anywhere connects to
 * hub `:8804`.** Every live client SyncPlay socket points at phlix-server's own
 * `:8097`, speaks a different (`syncplay_`-prefixed) vocabulary, and carries its
 * token in the query string — which `:8804` refuses by design (S237). No client
 * has any handling for an unknown frame type, and none can navigate-and-play from
 * a media id. So in practice the delivered count is 0 today and the Alexa skill
 * speaks its honest "you do not have the Phlix app open" answer.
 *
 * That is the intended, correct degradation, not a defect: the hub half is built
 * so the failure is honest by construction, and the feature turns on by itself the
 * moment a client implements the consumer.
 *
 * @package Phlix\Hub\SyncPlay
 * @since   S93 (Alexa: play in an already-open app)
 */
final class PendingCommandProtocol
{
    /**
     * Channel event the HTTP workers publish pending-command pushes on; the
     * `:8804` SyncPlay relay worker subscribes to it.
     */
    public const PUSH_EVENT = 'phlix.syncplay.pending_command.push';

    /**
     * The `type` field of the JSON frame written to a client socket.
     *
     * Deliberately outside the existing `:8804` vocabulary (`group_join`,
     * `playback_play`, `room_state`, …) because this is not a room broadcast: it
     * is addressed to a USER's open app.
     */
    public const FRAME_TYPE = 'pending_command';

    /**
     * The only command carried today: "start this media id".
     *
     * A `command` discriminator rather than a bare frame type so a later step can
     * add (say) a queue or a resume command without minting a second frame type
     * every existing consumer would have to learn.
     */
    public const COMMAND_PLAY_MEDIA = 'play_media';

    /**
     * Seconds an HTTP worker waits for the `:8804` worker's delivered-count reply.
     *
     * Short ON PURPOSE. Alexa gives a self-hosted skill roughly **8 seconds** to
     * answer before it abandons the request and speaks its own error, and this
     * wait sits INSIDE that budget alongside the account-link resolve, the server
     * list and the relayed library search. A generous timeout here would spend the
     * whole budget waiting for a process that, in the common case, has nothing to
     * deliver anyway.
     *
     * The direction the timeout fails in is the point: a missing reply degrades to
     * **0 delivered**, which the skill speaks as the honest "no open app" answer —
     * NOT to an exception the user hears as a 500 and not to a cheerful
     * confirmation. Under-claiming delivery is always the safe error;
     * over-claiming would confirm a command nobody received.
     */
    public const REPLY_TIMEOUT_SECONDS = 2.0;

    /**
     * Prevent instantiation — constants only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
