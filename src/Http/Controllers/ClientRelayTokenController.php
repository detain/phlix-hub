<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Mints a per-user, server-scoped client relay token (Step S2a).
 *
 * `POST /api/v1/me/servers/{id}/relay-token` — for an authenticated hub user
 * who OWNS the named server, mint a short-lived, revocable relay token scoped
 * to that server and return the plaintext exactly once. The token is the
 * credential a browser/native client will present to the client relay worker
 * to mount a tunnel (worker enforcement lands in the S2b follow-up).
 *
 * Ownership is enforced exactly as the HTTP proxy does (see
 * {@see ServerProxyController::proxy()}): resolve the server via
 * {@see ServerInfoHandler} and require `server.userId === request.userId`,
 * returning 404 when the server is unknown and 403 when it is not owned.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S2a (per-user revocable hub relay token — mint endpoint)
 */
final class ClientRelayTokenController
{
    /**
     * @param ClientRelayTokenService      $tokens      Mints/validates relay tokens.
     * @param ServerInfoHandler            $serverInfo  Resolves server ownership.
     * @param AuditLogger                  $audit       Records the mint as an audit event.
     * @param RateLimiterInterface|null    $mintLimiter CLIENT_RELAY_TOKEN profile
     *                                                  limiter (M-HUB-22); null
     *                                                  only in un-wired venues —
     *                                                  the endpoint then behaves
     *                                                  pre-cure (rate) rather than
     *                                                  failing closed, matching
     *                                                  the invite-redeem posture.
     */
    public function __construct(
        private readonly ClientRelayTokenService $tokens,
        private readonly ServerInfoHandler $serverInfo,
        private readonly AuditLogger $audit,
        private readonly ?RateLimiterInterface $mintLimiter = null,
    ) {
    }

    /**
     * `POST /api/v1/me/servers/{id}/relay-token`.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Route params: `id` (server UUID).
     *
     * @return Response JSON `{token, expires_at, server_id}` on success.
     *
     * @since S2a
     */
    public function mint(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        // M-HUB-22: budget BEFORE any ownership read — over-budget traffic
        // must not cost the DB a server lookup. Throws RateLimitException,
        // which the central dispatch catch maps to the canonical 429 +
        // Retry-After envelope (the hub's Application::rateLimitResponse()
        // law); it deliberately escapes the method rather than being folded
        // into a locally-mapped error frame.
        $this->assertMintBudget($userId);

        $serverId = $params['id'] ?? '';
        $server = $this->serverInfo->getServerInfo($serverId);
        if ($server === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'server.not_found',
            ]);
        }

        if ($server->userId !== $userId) {
            $this->audit->logPermissionDenied($userId, $serverId, 'relay_token.mint');

            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'server.not_owned',
            ]);
        }

        $minted = $this->tokens->mint($userId, $serverId);

        $this->audit->logAdminAction(
            $userId,
            'relay_token.mint',
            $serverId,
            ['expires_at' => $minted['expires_at']],
        );

        // M-HUB-22: when the per-scope active cap evicted older tokens, make
        // it visible in the audit trail — a member (or an analyst) can see
        // the cap working without inferring it from token-count deltas.
        if (($minted['pruned'] ?? 0) > 0) {
            $this->audit->logAdminAction(
                $userId,
                'relay_token.prune_overflow',
                $serverId,
                ['pruned' => $minted['pruned']],
            );
        }

        return (new Response())->status(201)->json([
            'token' => $minted['token'],
            'expires_at' => $minted['expires_at'],
            'server_id' => $serverId,
        ]);
    }

    /**
     * The M-HUB-22 mint budget: {@see \Phlix\Hub\Common\RateLimit\RateLimitProfiles::CLIENT_RELAY_TOKEN},
     * keyed on the AUTHENTICATED MEMBER (`client_relay_token:<userId>`).
     *
     * User-keyed rather than IP-keyed (the invite-redeem precedent): every
     * request here already carries membership, the abuse vector is one
     * member minting credentials, and an IP key would collapse NAT'd
     * households into shared buckets while letting the member simply
     * re-connect to refill.
     */
    private function assertMintBudget(string $userId): void
    {
        if ($this->mintLimiter === null) {
            return;
        }

        $state = $this->mintLimiter->hit('client_relay_token:' . $userId);
        if (!$state->limited) {
            return;
        }

        if ($state->count === $state->limit) {
            $this->audit->logFailedAuth('relay_token_mint_rate_limited', ['user_id' => $userId]);
        }

        throw new RateLimitException(
            resetAt: $state->resetAt,
            remaining: 0,
        );
    }
}
