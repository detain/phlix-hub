<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use function in_array;

/**
 * The identity and capability set a live OAuth token carries (S92).
 *
 * Returned by both {@see OAuthTokenService::validateAccess()} and
 * {@see OAuthTokenService::consumeRefresh()} — the two answer different
 * questions ("may this bearer act?" and "may this bearer be re-issued?") but
 * both answer with the same three facts: which client, which user, and which
 * scopes.
 *
 * {@see $codeId} is the lineage handle. Every token descended from one
 * authorization code — the original access/refresh pair and every pair that
 * refresh rotation later produced — carries the same value, which is what makes
 * "revoke everything issued from this code" a single `UPDATE` when the code is
 * replayed.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthGrant
{
    /**
     * @param string       $id       Row UUID of the token itself.
     * @param string       $kind     {@see OAuthTokenService::KIND_ACCESS} or
     *                               {@see OAuthTokenService::KIND_REFRESH}.
     * @param string       $clientId Client the token was issued to.
     * @param string       $userId   Hub user the token acts as.
     * @param list<string> $scopes   Granted scopes, in {@see OAuthScopes::all()} order.
     * @param string|null  $codeId   Authorization code this token descends from.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
        public readonly ?string $codeId,
    ) {
    }

    /**
     * Whether this token carries `$scope`.
     *
     * @param string $scope Scope to test for.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
