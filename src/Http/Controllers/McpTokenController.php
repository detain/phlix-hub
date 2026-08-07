<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;

use function is_array;
use function is_string;
use function mb_substr;
use function trim;

/**
 * Manage a user's MCP personal access tokens (S62).
 *
 * `GET /api/v1/me/mcp-tokens` · `POST /api/v1/me/mcp-tokens` ·
 * `DELETE /api/v1/me/mcp-tokens/{id}`.
 *
 * Modelled on {@see ClientRelayTokenController}: the same 401-then-act shape,
 * the same "plaintext is returned exactly once" contract, the same audit trail.
 * The difference is what a token is scoped to. A relay token names a single
 * server and the controller checks ownership of it before minting; an MCP token
 * names a USER, so there is no server to check here — every request the token
 * later makes is ownership-checked at USE time by
 * {@see ServerProxyController::proxy()} via `Phlix\Hub\Mcp\McpToolContext`.
 *
 * ⚠ Read that as a deliberate placement, not an omission. Checking ownership at
 * mint time would be worthless anyway: a server can be unclaimed, transferred or
 * deleted during a 90-day token's life, and only a check at use time reflects
 * that. This controller's job is to bind the token to a user and a scope set;
 * the proxy's job is to decide what that user may reach right now.
 *
 * All three routes sit behind `AuthMiddleware` in the composed route table, and
 * every query is additionally keyed by `$request->userId`, so a caller cannot
 * list or revoke another user's tokens even if they learn an id.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpTokenController
{
    /** Longest accepted token label; the column is VARCHAR(191). */
    private const int MAX_NAME_LENGTH = 191;

    /**
     * @param McpTokenService $tokens Mints / lists / revokes MCP tokens.
     * @param AuditLogger     $audit  Records mint and revoke as audit events.
     */
    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * `GET /api/v1/me/mcp-tokens` — the caller's tokens, metadata only.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Unused.
     */
    public function index(Request $request, array $params = []): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return self::unauthorized();
        }

        return (new Response())->json([
            'tokens' => $this->tokens->listForUser($userId),
            'available_scopes' => McpScopes::all(),
        ]);
    }

    /**
     * `POST /api/v1/me/mcp-tokens` — mint a token and return the plaintext once.
     *
     * Body: `{"name": "Claude Desktop", "scopes": ["mcp:servers:read", …]}`.
     * An empty or unrecognised scope list is refused rather than silently
     * minting a token that can call nothing — a credential that authenticates
     * but authorises nothing is the shape of a bug report, not a feature.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Unused.
     */
    public function create(Request $request, array $params = []): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return self::unauthorized();
        }

        /** @var mixed $rawName */
        $rawName = $request->body['name'] ?? '';
        $name = is_string($rawName) ? mb_substr(trim($rawName), 0, self::MAX_NAME_LENGTH) : '';

        /** @var mixed $rawScopes */
        $rawScopes = $request->body['scopes'] ?? null;
        $scopes = is_array($rawScopes) ? McpScopes::fromArray($rawScopes) : McpScopes::all();

        if ($scopes === []) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'mcp_token.no_valid_scopes',
                'message' => 'At least one known scope is required.',
                'available_scopes' => McpScopes::all(),
            ]);
        }

        $minted = $this->tokens->mint($userId, $name, $scopes);

        $this->audit->logAdminAction($userId, 'mcp_token.mint', $minted['id'], [
            'scopes' => $minted['scopes'],
            'expires_at' => $minted['expires_at'],
        ]);

        return (new Response())->status(201)->json([
            'id' => $minted['id'],
            // Returned exactly once. There is no endpoint that can show it
            // again, because only its SHA-256 hash was stored.
            'token' => $minted['token'],
            'name' => $name,
            'scopes' => $minted['scopes'],
            'expires_at' => $minted['expires_at'],
        ]);
    }

    /**
     * `DELETE /api/v1/me/mcp-tokens/{id}` — revoke one of the caller's tokens.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Route params: `id` (token UUID).
     */
    public function revoke(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return self::unauthorized();
        }

        $tokenId = $params['id'] ?? '';
        if (!$this->tokens->revokeForUser($userId, $tokenId)) {
            // Unknown id, already revoked, and "belongs to somebody else" are
            // deliberately indistinguishable: distinguishing them would turn
            // this endpoint into an oracle for other users' token ids.
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'mcp_token.not_found',
            ]);
        }

        $this->audit->logAdminAction($userId, 'mcp_token.revoke', $tokenId, []);

        return (new Response())->json(['revoked' => true, 'id' => $tokenId]);
    }

    /**
     * The shared 401 envelope.
     */
    private static function unauthorized(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code' => 'auth.required',
        ]);
    }
}
