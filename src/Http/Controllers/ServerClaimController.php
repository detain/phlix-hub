<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\ClaimRequest;

/**
 * Handles server claim endpoints.
 *
 * POST /api/v1/server-claims/new    — server initiates pairing (public)
 * POST /api/v1/server-claims/claim  — user claims a server (auth required)
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ServerClaimController
{
    /**
     * @param ClaimRequestHandler $handler Claim request handler.
     */
    public function __construct(
        private readonly ClaimRequestHandler $handler,
    ) {
    }

    /**
     * `POST /api/v1/server-claims/new` — server initiates pairing.
     *
     * Public endpoint — no auth required (the server has no JWT yet).
     */
    public function newClaim(Request $request): Response
    {
        $protocolHeader = $request->getHeader(HubProtocolMiddleware::HEADER_NAME);
        if ($protocolHeader !== HubProtocolMiddleware::REQUIRED_VERSION) {
            return (new Response())->error(
                400,
                'hub.protocol_unsupported',
                'HUB_PROTOCOL_UNSUPPORTED',
                ['message' => 'Accept-Phlix-Protocol: v1 required'],
            );
        }

        try {
            $claimRequest = ClaimRequest::fromPayload($request->body);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $response = $this->handler->handleNewClaim($claimRequest);
            return (new Response())->json($response->toPayload());
        } catch (\InvalidArgumentException $e) {
            return $this->mapError($e->getMessage());
        }
    }

    /**
     * `GET /api/v1/server-claims/{claimId}` — poll claim status.
     *
     * Public endpoint — the server polls this before it has a JWT. The
     * claim id is an unguessable UUID and acts as the bearer secret; a
     * `claimed` response returns the one-time enrollment material.
     *
     * @param array<string, string> $params Route params (claimId).
     */
    public function status(Request $request, array $params): Response
    {
        $claimId = $params['claimId'] ?? '';
        if ($claimId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => 'claim id is required',
            ]);
        }

        return (new Response())->json($this->handler->getClaimStatus($claimId));
    }

    /**
     * `POST /api/v1/server-claims/claim` — user claims a server.
     *
     * Requires user auth (userId must be set by AuthMiddleware).
     */
    public function claim(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            // Registry dual-placement note: `auth.unauthenticated` rode `code`
            // in SCREAMING form; this emit-wave flips the site to the dotted
            // forward form and parks the legacy literal byte-identical in the
            // `error` TEXT field for clients that still string-match it.
            return (new Response())->error(401, 'auth.unauthenticated', 'UNAUTHENTICATED');
        }

        $claimCode = self::stringField($request, 'claim_code');
        if ($claimCode === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => 'claim_code is required',
            ]);
        }

        try {
            $result = $this->handler->handleClaimCode($claimCode, $userId);
            return (new Response())->json([
                'enrollment_jwt' => $result['enrollment_jwt'],
                'hub_jwks_url' => $result['hub_jwks_url'],
                'server_id' => $result['server_id'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->mapError($e->getMessage());
        }
    }

    /**
     * Map an error code string to an HTTP status + body.
     */
    private function mapError(string $code): Response
    {
        // W3 emit-wave: every arm carries its registered dotted `code`
        // (@phlix/contracts claim.*/hub.*/server.key_invalid) while the legacy
        // SCREAMING literal rides the `error` TEXT field byte-identical.
        return match ($code) {
            'CLAIM_CODE_NOT_FOUND' => (new Response())->error(
                404,
                'claim.code_not_found',
                'CLAIM_CODE_NOT_FOUND',
                ['message' => 'Claim code not found'],
            ),
            'CLAIM_CODE_EXPIRED' => (new Response())->error(
                410,
                'claim.code_expired',
                'CLAIM_CODE_EXPIRED',
                ['message' => 'Claim code has expired'],
            ),
            'CLAIM_CODE_ALREADY_CLAIMED' => (new Response())->error(
                409,
                'claim.code_already_claimed',
                'CLAIM_CODE_ALREADY_CLAIMED',
                ['message' => 'Claim code has already been used'],
            ),
            'HUB_PROTOCOL_UNSUPPORTED' => (new Response())->error(
                400,
                'hub.protocol_unsupported',
                'HUB_PROTOCOL_UNSUPPORTED',
                ['message' => 'Accept-Phlix-Protocol: v1 required'],
            ),
            'SERVER_KEY_INVALID' => (new Response())->error(
                400,
                'server.key_invalid',
                'SERVER_KEY_INVALID',
                ['message' => 'Server key is malformed or not Ed25519'],
            ),
            default => (new Response())->error(
                500,
                'hub.internal_error',
                'HUB_INTERNAL_ERROR',
                ['message' => 'An unexpected error occurred'],
            ),
        };
    }

    /**
     * Read a string field from $request->body.
     */
    private static function stringField(Request $request, string $key): string
    {
        /** @var mixed $value */
        $value = $request->body[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
