<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use function array_filter;
use function array_unique;
use function array_values;
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
     * Every scope this build understands, in a stable order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SERVERS_READ,
            self::LIBRARY_READ,
            self::PLAYBACK_READ,
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
     * a newline-separated one both work), then drops anything
     * {@see isKnown()} rejects. Order follows {@see all()} so two equivalent
     * lists compare equal.
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

        $present = array_filter(
            $pieces,
            static fn (string $piece): bool => $piece !== '' && self::isKnown($piece),
        );
        $present = array_values(array_unique($present));

        $ordered = [];
        foreach (self::all() as $scope) {
            if (in_array($scope, $present, true)) {
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
