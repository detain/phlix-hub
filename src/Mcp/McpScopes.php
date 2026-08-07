<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use function implode;
use function in_array;
use function is_string;
use function preg_split;
use function trim;

/**
 * The closed set of scopes an MCP personal access token may carry (S62).
 *
 * A scope is a coarse capability label. It is the SECOND of two independent
 * gates on an MCP tool call, and it is deliberately the weaker one:
 *
 *  1. **Identity + ownership** — every tool call runs through
 *     {@see McpToolContext}, which re-derives the presenting token's `user_id`
 *     and hands the request to the production controllers
 *     ({@see \Phlix\Hub\Http\Controllers\ServerProxyController},
 *     {@see \Phlix\Hub\Http\Controllers\ServerListController}). Those enforce
 *     "the caller owns this server" and the relay browse-scope allowlist. This
 *     gate cannot be widened by a token.
 *  2. **Scope** — this file. It lets a user mint a token NARROWER than their
 *     own account, e.g. a token that may browse libraries but may not read
 *     playback information. It can only ever SUBTRACT from gate 1.
 *
 * Read that ordering literally: granting every scope in {@see all()} still does
 * not let a token see a server its user does not own. Scopes are not a
 * substitute for the ownership check and must never be treated as one.
 *
 * Storage format is a single space-delimited string (`mcp:servers:read
 * mcp:library:read`), parsed by {@see parse()} and rendered by
 * {@see toStorage()}. Unknown values are DROPPED at parse and mint time rather
 * than stored, so a typo becomes "no scope" (fail closed) instead of a scope
 * that silently matches nothing later.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpScopes
{
    /** Enumerate the media servers the token's user owns. */
    public const string SERVERS_READ = 'mcp:servers:read';

    /** Read library / media metadata from an owned server over the relay. */
    public const string LIBRARY_READ = 'mcp:library:read';

    /** Read playback information (stream decisions) for an owned media item. */
    public const string PLAYBACK_READ = 'mcp:playback:read';

    /**
     * Control an ALREADY-RUNNING cast/DLNA playback session on an owned server
     * (S63): pause, resume, stop, seek.
     *
     * The only WRITE scope in the set, and the reason it is separate from
     * {@see PLAYBACK_READ} rather than folded into it: a token minted so an
     * agent can answer "what would this play as?" must not thereby be able to
     * stop somebody's film. Granting it is a second, explicit decision at mint
     * time. It is also inert unless the operator has switched
     * {@see \Phlix\Hub\Mcp\Tools\PlaybackControlTool} on — see that class.
     */
    public const string PLAYBACK_CONTROL = 'mcp:playback:control';

    /**
     * Every scope this build understands, in a stable order.
     *
     * ⚠ Read-only scopes first, then writes. {@see parse()} emits scopes in
     * THIS order, so the order is part of the stored representation — appending
     * is safe, reordering rewrites what every existing row compares equal to.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SERVERS_READ,
            self::LIBRARY_READ,
            self::PLAYBACK_READ,
            self::PLAYBACK_CONTROL,
        ];
    }

    /**
     * Whether `$scope` is one this build understands.
     *
     * @param string $scope Candidate scope string.
     */
    public static function isKnown(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Parse a stored / user-supplied scope list into a de-duplicated list of
     * KNOWN scopes.
     *
     * Splits on any run of whitespace (so a comma-free "scope scope" string and
     * a newline-separated one both work), then walks {@see all()} and keeps the
     * ones that appeared. That single loop does all three jobs at once — it
     * DROPS unknown values (they are never in `all()`), it DE-DUPLICATES (each
     * scope is emitted at most once), and it fixes the ORDER — so two equivalent
     * lists compare equal.
     *
     * ⚠ Do not "tighten" this by also filtering the split pieces through
     * {@see isKnown()} first. That was the original shape and it was dead code:
     * mutation testing removed the filter and every test stayed green, because
     * the loop below is the only thing that decides membership. A redundant
     * guard is worse than none — it looks like the check, so the next reader
     * stops looking for the real one.
     *
     * @param string $raw Space-delimited scope list.
     *
     * @return list<string> Known scopes, de-duplicated, in {@see all()} order.
     */
    public static function parse(string $raw): array
    {
        $pieces = preg_split('/\s+/', trim($raw));
        if ($pieces === false) {
            return [];
        }

        $ordered = [];
        foreach (self::all() as $scope) {
            if (in_array($scope, $pieces, true)) {
                $ordered[] = $scope;
            }
        }

        return $ordered;
    }

    /**
     * Normalise an arbitrary caller-supplied array into a list of known scopes.
     *
     * Non-string members are dropped rather than coerced: a JSON body may carry
     * anything, and a numeric `0` coerced to `"0"` would be a scope nobody
     * asked for.
     *
     * @param array<array-key, mixed> $raw Caller-supplied scope values.
     *
     * @return list<string> Known scopes, de-duplicated, in {@see all()} order.
     */
    public static function fromArray(array $raw): array
    {
        $strings = [];
        /** @var mixed $value */
        foreach ($raw as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return self::parse(implode(' ', $strings));
    }

    /**
     * Render a scope list into the single column value the `mcp_tokens.scopes`
     * column stores.
     *
     * @param list<string> $scopes Scopes to store.
     */
    public static function toStorage(array $scopes): string
    {
        return implode(' ', self::parse(implode(' ', $scopes)));
    }
}
