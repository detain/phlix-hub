<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use Phlix\Hub\Mcp\McpScopes;

use function implode;
use function in_array;
use function is_string;
use function preg_split;
use function trim;

/**
 * The closed set of scopes an OAuth 2.0 access token issued by this hub may
 * carry (S92).
 *
 * ## Why this is not an Alexa scope list
 *
 * S92's brief is to build the Authorization Server ONCE and share it, rather
 * than build one for Alexa now and a second for MCP later. The scope vocabulary
 * is where that promise is either kept or quietly broken: if this file invented
 * `alexa:library:read` alongside the existing `mcp:library:read`, then MCP
 * adopting OAuth would mean renaming every scope in every stored token — a
 * rebuild in all but name.
 *
 * So the vocabulary is deliberately the UNION of:
 *
 *  - {@see PROFILE_READ} — the identity scope every account-linking flow needs
 *    ("which hub user is this?"), which MCP's PAT surface never needed because a
 *    PAT is minted by the user in their own session; and
 *  - every member of {@see McpScopes::all()}, re-exported verbatim.
 *
 * That second clause is the load-bearing one and it is pinned by
 * `OAuthScopesTest::testEveryMcpScopeIsGrantableOverOauth()`. An MCP
 * client that migrates from a PAT to an OAuth authorization-code grant asks for
 * the SAME scope strings it asks for today, and
 * {@see \Phlix\Hub\Mcp\McpToolRegistry::call()} — which compares against
 * `McpScopes` constants — keeps working unchanged against an OAuth-issued token.
 *
 * ## Fail-closed parsing
 *
 * Storage format, parse semantics and ordering rules are identical to
 * {@see McpScopes}: a single space-delimited column, unknown values DROPPED
 * rather than stored, output always in {@see all()} order so two equivalent
 * grants compare equal.
 *
 * ⚠ An empty scope list is never a permissive default here. {@see parse()}
 * returning `[]` is the "nothing recognised" signal and every caller in this
 * subsystem treats it as a hard refusal:
 * {@see OAuthClient} refuses to exist with an empty allow-list, and
 * {@see \Phlix\Hub\Http\Controllers\OAuthController::authorize()} answers
 * `invalid_scope` rather than picking a default. This estate has already shipped
 * the other shape once — a rating cap built from `[]` emitted no `WHERE` clause
 * at all and silently authorised everything — and S261 shipped it again, where
 * an MCP token minted with no `scopes` field received the WRITE scope. There is
 * no default grant in this file for that reason.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthScopes
{
    /**
     * Read the linked hub account's identity — its user id and display name.
     *
     * The minimum an account-linking flow can ask for, and the only scope in
     * this vocabulary that is NOT also an {@see McpScopes} member: MCP never
     * needed it, because a personal access token is minted by the user inside
     * their own hub session and its `user_id` is therefore already known to
     * whoever holds it. An OAuth client is a third party (Amazon, a future MCP
     * client) that starts out knowing nothing about the user.
     */
    public const string PROFILE_READ = 'phlix:profile:read';

    /**
     * Every scope this build will issue, in a stable order.
     *
     * ⚠ The order is part of the stored representation ({@see parse()} emits in
     * THIS order and {@see toStorage()} round-trips through it), so appending is
     * safe and reordering rewrites what every existing row compares equal to.
     *
     * ⚠ The MCP members are taken from {@see McpScopes::all()} rather than
     * restated. Restating them would let the two vocabularies drift the first
     * time somebody added a scope to one file and not the other, and the drift
     * would be invisible — an OAuth token carrying a scope MCP no longer knows
     * simply matches no tool. Deriving keeps the "one server, shared with MCP"
     * property true by construction.
     *
     * @return non-empty-list<string>
     */
    public static function all(): array
    {
        $scopes = [self::PROFILE_READ];
        foreach (McpScopes::all() as $mcpScope) {
            $scopes[] = $mcpScope;
        }

        return $scopes;
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
     * Parse a space-delimited scope string into a de-duplicated list of KNOWN
     * scopes, in {@see all()} order.
     *
     * Unknown values are dropped, so a typo becomes "no scope" rather than a
     * scope that silently matches nothing later. A caller must therefore treat
     * an empty return as a refusal, NOT as "the client asked for nothing in
     * particular, give it the usual".
     *
     * @param string $raw Space-delimited scope list, as it arrives on the wire
     *                    (`scope=phlix:profile:read mcp:library:read`) or out of
     *                    the `scopes` column.
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
     * Non-string members are dropped rather than coerced — a JSON body may carry
     * anything, and a numeric `0` coerced to `"0"` would be a scope nobody asked
     * for.
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
     * Render a scope list into the single column value the `scopes` columns of
     * `oauth_clients`, `oauth_consent_requests`, `oauth_authorization_codes` and
     * `oauth_tokens` all store.
     *
     * @param list<string> $scopes Scopes to store.
     */
    public static function toStorage(array $scopes): string
    {
        return implode(' ', self::parse(implode(' ', $scopes)));
    }

    /**
     * A short, user-facing sentence for the consent screen.
     *
     * ⚠ Enumerated, not derived from the scope string. A derived description
     * ("read your " . str_replace(...)) self-adjusts: a new scope would get a
     * plausible-looking sentence automatically and the consent screen would
     * describe a capability nobody had written down. An unknown scope here
     * returns the raw string, which reads as obviously unfinished rather than
     * as a considered description.
     *
     * @param string $scope Scope to describe.
     */
    public static function describe(string $scope): string
    {
        return match ($scope) {
            self::PROFILE_READ          => 'See which Phlix account you are, and your display name',
            McpScopes::SERVERS_READ     => 'List the Phlix media servers you own',
            McpScopes::LIBRARY_READ     => 'Browse and search the libraries on your servers',
            McpScopes::PLAYBACK_READ    => 'See how a title would play — quality, subtitles, audio tracks',
            McpScopes::PLAYBACK_CONTROL => 'Pause, resume, seek and stop playback that is already running',
            default                     => $scope,
        };
    }
}
