<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

/**
 * The seam for reading a controller request (refactor F-03/F-04).
 *
 * Every member-facing controller used to hand-roll the same preamble —
 * `$userId = $request->userId ?? ''; if ($userId === '') { … }` — twenty-nine
 * copies across fifteen files, each with its own comment drift and its own
 * risk of a future copy mutating into a different trigger law (a loosened
 * `!== null`, a forgotten trim). The 401 FRAME each site emits, however, is
 * deliberately NOT collapsed here: the member prose family, the machine
 * claim envelope and the plain auth frame are different registered contracts
 * (HUB-04 dual-placement law), and a seam that picks one frame would bake a
 * wire change into a dedupe. The seam therefore answers only the question,
 * never the refusal: the caller keeps its own frame in its own `if`.
 *
 * Body-field extraction (`authenticatedBodyString()` et al., F-04) joins the
 * same file: it is the other half of reading a request safely, and the
 * strict-vs-coercing split below is exactly the per-copy audit the scan's
 * "collapse the near-twins" instinct failed — they are NOT all the same law.
 *
 * @package Phlix\Hub\Http
 */
final class ControllerRequests
{
    /**
     * Vocabulary class — no instances (mirrors {@see QuotaFrames}).
     */
    private function __construct()
    {
    }

    /**
     * The authenticated user this request acts as, or null when there is none.
     *
     * This is the exact law the 29 inlined preambles encoded: a `null`
     * identity and an empty-string identity are the SAME absence — the
     * middleware populates `Request::$userId` as a string and leaves it null
     * only for unauthenticated arrivals, but the gate treats both as "not
     * signed in". A non-empty string is returned verbatim; the caller's
     * narrow-out (`=== null`) leaves it a plain string for the handler body.
     */
    public static function authenticatedUserId(Request $request): ?string
    {
        $userId = $request->userId ?? '';

        return $userId === '' ? null : $userId;
    }
}
