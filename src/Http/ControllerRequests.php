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
 * Body-field extraction (`stringField()` / `stringOrEmpty()` et al., F-04)
 * joins the same file: it is the other half of reading a request safely, and
 * the strict-vs-coercing split below is exactly the per-copy audit the scan's
 * "collapse the near-twins" instinct failed — they are NOT all the same law.
 * Seven methods encode five distinct laws, each byte-faithful to the copies
 * it absorbed (F-04): strict-string (Request body + array twins), optional
 * string (absent-or-empty collapses), trimmed string, coercing row reader
 * (MySQL driver int/float ambiguity), and the two key-normalizers which the
 * scan listed as twins but which are opposite laws — one CASTS numeric keys,
 * the other DROPS them.
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

    /**
     * Read a string field from a decoded request body. Returns `''` when the
     * key is absent or the value is not a string.
     *
     * Folds the three byte-identical private `stringField()` readers of
     * AuthController, UserPasswordController and ServerClaimController (F-04).
     * The AuthController copy claimed a "raw POST body (Workerman form-encoded)
     * fallback" in its docblock; that fallback never existed in any copy —
     * `Request::decodeJsonBody()` owns body hydration. This docblock is the
     * corrected truth.
     */
    public static function stringField(Request $request, string $key): string
    {
        return self::stringOrEmpty($request->body, $key);
    }

    /**
     * Coerce a `mixed` value to string (non-string → empty string) — the base
     * law under {@see stringOrEmpty()}. Absorbs AdminDashboardController::
     * stringOf() (F-04; a near-twin the scan's 21-copy census missed).
     */
    public static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The strict-array law behind {@see stringField()}, exposed for callers
     * that already hold the decoded array (query or body): a value is read
     * only when it is a string; anything else — absent, null, number, list —
     * reads as `''`. Never coerces.
     *
     * Absorbs OAuthController::str() (F-04), whose "treating absent and
     * non-string as `''`" law is exactly the trio's law over an array source.
     *
     * @param array<array-key, mixed> $source
     */
    public static function stringOrEmpty(array $source, string $key): string
    {
        return self::stringOf($source[$key] ?? null);
    }

    /**
     * An optional string parameter, distinguishing absent from empty: only a
     * non-empty string survives; everything else reads as `null`.
     *
     * Absorbs OAuthController::strOrNull() (F-04). `state` needs this law:
     * RFC 6749 echoes it back only when supplied, so an absent `state` must
     * never become `state=` on the redirect.
     *
     * @param array<array-key, mixed> $source
     */
    public static function optionalString(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A non-empty, trimmed string field, or `null`. The value is returned
     * TRIMMED — the distinct law of the AlexaSkillController copy, which
     * surfaces skill-slot text where surrounding whitespace is data noise
     * (absorbed by F-04; contrast {@see optionalString()}, which preserves
     * the raw spelling).
     *
     * @param array<array-key, mixed> $source
     */
    public static function trimmedString(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Coerce a MySQL row value to a string (handles the int/string ambiguity
     * of the driver); null, absent, and any non-scalar collapse to `''`.
     *
     * Absorbs AdminUserController::str() (F-04). This is a DIFFERENT law from
     * the strict readers above and must never be collapsed with them: it
     * deliberately stringifies int/float so a driver-typed primary key or
     * counter survives into the JSON wire as a string.
     *
     * @param array<array-key, mixed> $row
     */
    public static function rowString(array $row, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $row[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Re-key a decoded JSON object so every key is provably `string`, without
     * dropping anything: a numeric-looking key (`{"0": …}`) decodes to `int`
     * and is CAST here, so no field of the payload is lost on the way to
     * {@see trimmedString()}.
     *
     * Built with `array_combine()` rather than a foreach-into-typed-array loop,
     * and the distinction is not cosmetic: the loop binds each decoded value to
     * a `mixed` variable, which Psalm at errorLevel 1 refuses
     * (`MixedAssignment`) — PHPStan level 9 does NOT, so the loop passed one
     * analyser and failed the other. `array_combine()` never names the values
     * at all, and the key type is DERIVED from the mapper's `: string` return
     * rather than merely asserted. {@see \Phlix\Hub\Mcp\McpToolContext::decode()}
     * solves the identical problem the identical way.
     *
     * Absorbs AlexaSkillController::stringKeyed() (F-04). It is NOT a twin of
     * {@see stringKeyedParams()}, which answers the opposite question and
     * DROPS non-string keys.
     *
     * @param array<array-key, mixed> $source
     *
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $source): array
    {
        return array_combine(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($source)),
            $source,
        );
    }

    /**
     * Coerce an arbitrary decoded value into a string-keyed map by DROPPING
     * every non-string key (and everything that is not an array at all).
     *
     * JSON-RPC `params` may legitimately be an array (positional) or absent;
     * neither is usable by the by-name tool arguments MCP defines, and both
     * collapse to `[]` here rather than to something half-read. Absorbs
     * McpController::stringKeyed() (F-04) — the opposite law of
     * {@see stringKeyed()}: there a numeric key is data, here it is noise.
     *
     * @param mixed $raw
     *
     * @return array<string, mixed>
     */
    public static function stringKeyedParams(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        // `ARRAY_FILTER_USE_KEY` guarantees every surviving key is a string, but
        // Psalm cannot derive that: its array_filter provider only applies the
        // callback's assertions when no third argument is present, so with a
        // flag it hands back the *original* key type. The `@var` states the
        // fact the filter already enforces. Same idiom as `Http\Router`.
        /** @var array<string, mixed> $out */
        $out = array_filter($raw, 'is_string', ARRAY_FILTER_USE_KEY);

        return $out;
    }
}
