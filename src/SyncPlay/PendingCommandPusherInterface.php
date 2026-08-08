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
 * Push a "start this title" command to a user's already-open Phlix apps (S93).
 *
 * The seam between an HTTP handler (the Alexa skill) and the separate `:8804`
 * SyncPlay relay process that owns the live client sockets. Bound to an interface
 * so the skill controller can be unit-tested without a channel broker, and so a
 * later transport (a different worker, a queue) can replace the implementation
 * without touching a single line of speech logic.
 *
 * @package Phlix\Hub\SyncPlay
 * @since   S93 (Alexa: play in an already-open app)
 */
interface PendingCommandPusherInterface
{
    /**
     * Ask every live, authenticated app of `$userId` bound to `$serverId` to start
     * `$mediaId`.
     *
     * ## Why this returns a COUNT and not `void` or `bool`
     *
     * The return value is **the number of live, authenticated client sockets the
     * frame was actually written to**. `0` means *nobody received it* — no app was
     * open, or none of the open ones was bound to that server.
     *
     * A caller must therefore **never speak a confirmation on `0`**. That is the
     * whole reason this method reports a count rather than returning nothing or a
     * `true` that only means "the push was published": a skill that says "I sent
     * it" when the frame reached no socket has told the user a button exists which
     * does not, and their only way to discover the truth is to sit watching a
     * screen that never lights up. `void` cannot express the difference; `bool`
     * blurs "published" into "delivered".
     *
     * A count > 0 is a statement about the WRITE, not about what the app then did
     * with the frame — the honest ceiling on what the hub can know from here.
     *
     * Implementations must never throw for an ordinary failure (no broker, no
     * reply, a malformed reply): they return `0`, because under-claiming delivery
     * degrades to an honest answer while an exception inside a resident worker
     * degrades to a 500 the user hears as a broken skill.
     *
     * @param string $userId   Hub user id the command is addressed to.
     * @param string $serverId Server the media id belongs to; an app bound to a
     *        DIFFERENT server must not receive it.
     * @param string $mediaId  Media id to start.
     * @param string $title    Human title, for the app's own UI only.
     *
     * @return int Number of sockets written to. `0` means nobody received it.
     */
    public function pushPlayMedia(string $userId, string $serverId, string $mediaId, string $title): int;
}
