<?php

declare(strict_types=1);

namespace Phlex\Hub\Http\Controllers;

use Phlex\Hub\Hub\ClaimRequestHandler;
use Phlex\Hub\Hub\HubServicesProvider;
use Phlex\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlex\Hub\Http\Request;
use Phlex\Hub\Http\Response;
use Phlex\Shared\Hub\ClaimRequest;
use Phlex\Shared\Hub\ClaimResponse;
use Psr\Container\ContainerInterface;

/**
 * Handles server claim endpoints.
 *
 * POST /api/v1/server-claims/new    — server initiates pairing (public)
 * POST /api/v1/server-claims/claim  — user claims a server (auth required)
 *
 * @package Phlex\Hub\Http\Controllers
 * @since 0.3.0
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
            return (new Response())->status(400)->json([
                'error' => 'HUB_PROTOCOL_UNSUPPORTED',
                'message' => 'Accept-Phlex-Protocol: v1 required',
            ]);
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
     * `POST /api/v1/server-claims/claim` — user claims a server.
     *
     * Requires user auth (userId must be set by AuthMiddleware).
     */
    public function claim(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'UNAUTHENTICATED',
            ]);
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
        return match ($code) {
            'CLAIM_CODE_NOT_FOUND' => (new Response())->status(404)->json([
                'error' => 'CLAIM_CODE_NOT_FOUND',
                'message' => 'Claim code not found',
            ]),
            'CLAIM_CODE_EXPIRED' => (new Response())->status(410)->json([
                'error' => 'CLAIM_CODE_EXPIRED',
                'message' => 'Claim code has expired',
            ]),
            'CLAIM_CODE_ALREADY_CLAIMED' => (new Response())->status(409)->json([
                'error' => 'CLAIM_CODE_ALREADY_CLAIMED',
                'message' => 'Claim code has already been used',
            ]),
            'HUB_PROTOCOL_UNSUPPORTED' => (new Response())->status(400)->json([
                'error' => 'HUB_PROTOCOL_UNSUPPORTED',
                'message' => 'Accept-Phlex-Protocol: v1 required',
            ]),
            'SERVER_KEY_INVALID' => (new Response())->status(400)->json([
                'error' => 'SERVER_KEY_INVALID',
                'message' => 'Server key is malformed or not Ed25519',
            ]),
            default => (new Response())->status(500)->json([
                'error' => 'HUB_INTERNAL_ERROR',
                'message' => 'An unexpected error occurred',
            ]),
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
