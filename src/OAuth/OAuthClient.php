<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use InvalidArgumentException;

use function hash;
use function hash_equals;
use function in_array;
use function trim;

/**
 * A registered OAuth 2.0 client — an immutable, already-validated view of one
 * `oauth_clients` row (S92).
 *
 * ## An instance of this class cannot be permissive
 *
 * The constructor is private and {@see create()} THROWS on an empty
 * `$redirectUris` or an empty `$allowedScopes`. That is the whole point of the
 * type: everywhere downstream, `$client->redirectUris` is known to be non-empty,
 * so {@see allowsRedirectUri()} looping over it and returning false cannot
 * degenerate into "no registered URIs, therefore nothing to check, therefore
 * allow". This estate has shipped exactly that bug — a rating cap built from an
 * empty allow-list emitted no `WHERE` clause and authorised everything — and an
 * empty allow-list on an OAuth client would be the same failure with a redirect
 * attached to it.
 *
 * {@see OAuthClientRegistry::find()} catches the exception and returns `null`,
 * so a malformed or half-provisioned row presents to the outside world as
 * "no such client" rather than as a client with no restrictions.
 *
 * ## Redirect URI matching is exact, and only exact
 *
 * {@see allowsRedirectUri()} compares whole strings with {@see hash_equals()}.
 * It performs no normalisation, no prefix match, no substring match, no
 * trailing-slash tolerance and no query-parameter tolerance. Every one of those
 * relaxations is a known open-redirect / code-exfiltration vector, and a
 * sibling-wildcard-style partial match is a recurring defect class in this
 * estate. If a client needs three redirect URIs it registers three rows'
 * worth of URIs, spelled out.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthClient
{
    /**
     * @param string            $id             Row UUID.
     * @param string            $clientId       Public `client_id` presented on the wire.
     * @param string            $name           Human label shown on the consent screen.
     * @param non-empty-list<string> $redirectUris Exact, whole-string redirect URIs.
     * @param non-empty-list<string> $allowedScopes The ceiling on what this client may be granted.
     * @param bool              $confidential   True when the client holds a secret.
     * @param string|null       $secretHash     SHA-256 hex of the client secret, or null.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $name,
        public readonly array $redirectUris,
        public readonly array $allowedScopes,
        public readonly bool $confidential,
        private readonly ?string $secretHash,
    ) {
    }

    /**
     * Build a validated client, or refuse.
     *
     * @param string       $id            Row UUID.
     * @param string       $clientId      Public `client_id`.
     * @param string       $name          Human label.
     * @param list<string> $redirectUris  Registered redirect URIs. MUST be non-empty.
     * @param list<string> $allowedScopes Scope ceiling. MUST be non-empty AND all known.
     * @param bool         $confidential  Whether the client authenticates with a secret.
     * @param string|null  $secretHash    SHA-256 hex of the secret when confidential.
     *
     * @throws InvalidArgumentException When any invariant that would make the
     *         client permissive is violated.
     */
    public static function create(
        string $id,
        string $clientId,
        string $name,
        array $redirectUris,
        array $allowedScopes,
        bool $confidential,
        ?string $secretHash,
    ): self {
        if ($clientId === '') {
            throw new InvalidArgumentException('OAuth client_id must not be empty');
        }

        // ⚠ Trimmed, and whitespace-only entries dropped. A `"  "` row survived
        // an `$uri !== ''` check and became a REGISTERED redirect URI — one that
        // `allowsRedirectUri("  ")` would then have matched. Note the asymmetry
        // that makes this safe: only the REGISTERED side is trimmed. A presented
        // `" https://…"` is still compared verbatim and still refused, so
        // trimming cannot be used to smuggle a near-miss past the matcher.
        $uris = [];
        foreach ($redirectUris as $uri) {
            $uri = trim($uri);
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }
        if ($uris === []) {
            throw new InvalidArgumentException(
                'OAuth client "' . $clientId . '" has no registered redirect URIs;'
                . ' an empty redirect allow-list would authorise every destination',
            );
        }

        // Unknown scopes are dropped here, so a client provisioned against a
        // newer build cannot carry a ceiling this build does not understand.
        // If NOTHING survives, the client is refused rather than treated as
        // unrestricted.
        $scopes = OAuthScopes::parse(OAuthScopes::toStorage($allowedScopes));
        if ($scopes === []) {
            throw new InvalidArgumentException(
                'OAuth client "' . $clientId . '" has no recognised allowed scopes;'
                . ' an empty scope allow-list would authorise every capability',
            );
        }

        if ($confidential && ($secretHash === null || $secretHash === '')) {
            throw new InvalidArgumentException(
                'OAuth client "' . $clientId . '" is confidential but stores no secret hash;'
                . ' it could never be authenticated',
            );
        }

        return new self($id, $clientId, $name, $uris, $scopes, $confidential, $secretHash);
    }

    /**
     * Whether `$uri` is EXACTLY one of the registered redirect URIs.
     *
     * ⚠ Whole-string comparison only. Do not add normalisation, a
     * `str_starts_with()` prefix test, a trailing-slash fallback, or a
     * "same origin is close enough" relaxation — each of those turns a
     * registered `https://example.com/cb` into a licence to redirect an
     * authorization code to `https://example.com/cb.evil.example/`,
     * `https://example.com/cb/../../` or `https://example.com/cb?next=…`.
     *
     * @param string $uri The `redirect_uri` presented by the client.
     */
    public function allowsRedirectUri(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }

        foreach ($this->redirectUris as $registered) {
            if (hash_equals($registered, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every scope in `$scopes` is inside this client's ceiling.
     *
     * An empty `$scopes` is FALSE, not true-by-vacuity. "The client requested
     * nothing recognised" must not read as "the client asked for nothing
     * forbidden" — that is the fail-open shape, and `foreach` over an empty
     * list falls straight into it if the guard below is removed.
     *
     * @param list<string> $scopes Scopes the caller wants to grant.
     */
    public function permits(array $scopes): bool
    {
        if ($scopes === []) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (!in_array($scope, $this->allowedScopes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this client must authenticate with a secret at the token endpoint.
     */
    public function requiresSecret(): bool
    {
        return $this->confidential;
    }

    /**
     * Verify a presented client secret in constant time.
     *
     * Returns false for a public client regardless of what is presented: a
     * public client has no secret, so "the secret matched" is never a true
     * statement about it, and callers must gate on {@see requiresSecret()}
     * rather than on this returning true.
     *
     * @param string $secret The presented `client_secret`.
     */
    public function verifySecret(string $secret): bool
    {
        if (!$this->confidential || $this->secretHash === null || $secret === '') {
            return false;
        }

        return hash_equals($this->secretHash, hash('sha256', $secret));
    }
}
