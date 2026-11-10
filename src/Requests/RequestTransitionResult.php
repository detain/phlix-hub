<?php

/**
 * Phlix hub component: Requests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Requests;

/**
 * The outcome of an admin approve/deny attempt on a request row.
 *
 * Review hub__webux #1 (SH-075): a plain `bool` collapsed three genuinely
 * different worlds — "you did it", "someone already did it", and "the *arr
 * backend refused" — into one 500 whose message blamed Sonarr/Radarr even for
 * a harmless second click. The controller now maps each case to an honest
 * HTTP frame; this enum is the boundary that makes the distinction real
 * instead of re-probing the row and guessing.
 *
 * ## Wire law
 *
 * This enum NEVER reaches the wire. The hub's error vocabulary is the frozen
 * `@phlix/contracts` registry (ErrorCodesContractTest: every `code` literal
 * must be registered, whitelist empty), and the registry carries no
 * "already acted on" code for this operation — minting one is a contracts
 * release decision, not a lane edit. The conflict arm therefore rides the
 * ALREADY REGISTERED `approve_failed`/`deny_failed` codes with the honest
 * sentence on `error`/`message` and the truthful 409 status; only a real
 * backend failure keeps the 500.
 *
 * @package Phlix\Hub\Requests
 */
enum RequestTransitionResult
{
    /** The caller won the transition; the row is now in the requested state. */
    case Applied;

    /** The row exists but was no longer `pending` (second click, or another admin acted first). */
    case NotPending;

    /** The row is gone (deleted between the controller's existence pre-read and the guarded UPDATE). */
    case NotFound;

    /** The state transition held, but the side effect behind it failed (arr add rejected/unreachable/corrupt row). */
    case Failed;
}
