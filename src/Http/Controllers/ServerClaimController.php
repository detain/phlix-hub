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
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\QuotaFrames;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\ClaimRequest;

/**
 * Handles server claim endpoints.
 *
 * POST /api/v1/server-claims/new    — server initiates pairing (public)
 * GET  /api/v1/server-claims/{claimId} — server polls pairing status (public)
 * POST /api/v1/server-claims/claim  — user claims a server (auth required)
 *
 * All three surfaces are budget-guarded per trusted client IP by the
 * {@see RateLimitProfiles::SERVER_CLAIMS} limiter (review hub__exploiter M1):
 * each request consumes one unit of its action bucket
 * (`server_claims:{new,status,claim}:<ip>`) BEFORE any handler/DB work runs,
 * and a tripped bucket answers through the central {@see RateLimitException}
 * mapping — 429 + `Retry-After` + `{error, code: 'rate_limited'}` (the same
 * registered envelope login/mcp/alexa/jwks use; no new wire codes).
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ServerClaimController
{
    /**
     * Claim-surface body cap (M-HUB-13, review hub__exploiter M2: no body cap
     * at the claim surface; Workerman materializes every body per
     * Content-Length before routing, so the parse boundary is where the bill
     * gets refused). 16 KiB = the largest legal claim payload (serverName 255
     * + version 32 + Ed25519 JWK + 16×253-byte hostname candidates, ~2× for
     * JSON escaping ≈ 8.5 KB) with a 2× margin at a power-of-two boundary,
     * ≈ 400× the ~40-byte
     * typical pairing request. McpController's 256 KiB JSON-RPC envelope is
     * the shape precedent, not the value precedent — claim payloads are an
     * order of magnitude smaller by spec. The estate-wide GLOBAL cap at the
     * HTTP boundary stays a separate item (review minor-10); this is the
     * claim-scoped floor M2 charged.
     */
    private const int MAX_CLAIM_BODY_BYTES = 16384;

    /**
     * @param ClaimRequestHandler       $handler        Claim request handler.
     * @param RateLimiterInterface|null $claimsLimiter  SERVER_CLAIMS profile limiter;
     *        null (unit tests, unbooted pool wiring) disables the budget — the
     *        pre-M12 behavior, pinned by the wiring-guard test so the real
     *        container can NEVER ship the null again (same fail-open-with-guard
     *        precedent as AuthManager's nullable signup limiter).
     * @param AuditLogger|null          $audit          Writes exactly ONE audit_logs
     *        row per limiter transition into "over budget" (first refusal of the
     *        window); null = file-channel-only, same degradation as above.
     */
    public function __construct(
        private readonly ClaimRequestHandler $handler,
        private readonly ?RateLimiterInterface $claimsLimiter = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Consume one unit of this action's per-IP pairing budget.
     *
     * @param string $action Bucket name: new | status | claim.
     *
     * @throws RateLimitException Over budget — mapped to the central 429 by
     *         {@see \Phlix\Hub\Application} (the jwks/proxy central-mapping path;
     *         this throw deliberately happens OUTSIDE the methods'
     *         InvalidArgumentException catches so it can never be folded into
     *         a generic error frame).
     */
    private function assertPairingBudget(string $action, Request $request): void
    {
        if ($this->claimsLimiter === null) {
            return;
        }

        // Trusted-proxy-aware real client IP — NOT the raw peer, which is the
        // HAProxy loopback for every request and would collapse the limiter
        // into one global bucket (mirrors HubJwksController / AuthController).
        $ip = $request->getTrustedClientIp() ?: 'unknown';
        $state = $this->claimsLimiter->hit('server_claims:' . $action . ':' . $ip);
        if (!$state->limited) {
            return;
        }

        if ($state->count === $state->limit) {
            $this->audit?->logFailedAuth('claim_code_rate_limited', ['surface' => $action]);
        }

        throw new RateLimitException(
            resetAt: $state->resetAt,
            remaining: 0,
        );
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

        // Fail loud BEFORE any parse work: a body over cap never reaches the
        // decoder, the budget, or the DB. Frame = this endpoint's existing
        // shape-reject arm (invalid_payload/'Bad Request'), byte-shaped like
        // the fromPayload catch below — no new wire codes.
        if (strlen($request->rawBody) > self::MAX_CLAIM_BODY_BYTES) {
            return (new Response())->error(400, 'invalid_payload', 'Bad Request', [
                'message' => 'Request body exceeds ' . self::MAX_CLAIM_BODY_BYTES . ' bytes.',
            ]);
        }

        try {
            $claimRequest = ClaimRequest::fromPayload($request->body);
        } catch (\InvalidArgumentException $e) {
            // ClaimRequest::fromPayload rejects present-but-malformed body
            // fields (JWKS shape, key types) → registry invalid_payload:
            // "a body field is present but not of the shape the endpoint
            // parses". 'Bad Request' text kept byte-identical; code additive.
            return (new Response())->error(400, 'invalid_payload', 'Bad Request', [
                'message' => $e->getMessage(),
            ]);
        }

        // Free guard-shaped 400s above cost the flood nothing; every request
        // that reaches DB work (the claim-row INSERT) pays the budget.
        $this->assertPairingBudget('new', $request);

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
            // Required part of the REQUEST missing → registry invalid_request.
            return (new Response())->error(400, 'invalid_request', 'Bad Request', [
                'message' => 'claim id is required',
            ]);
        }

        $this->assertPairingBudget('status', $request);

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
            // Required part of the REQUEST missing → registry invalid_request.
            return (new Response())->error(400, 'invalid_request', 'Bad Request', [
                'message' => 'claim_code is required',
            ]);
        }

        // The guess path: every attempt that reaches the claim-code lookup
        // pays this bucket (review hub__exploiter M1 — the grind bound).
        $this->assertPairingBudget('claim', $request);

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
     *
     * STATE-ORACLE POSTURE (review hub__exploiter M1, honest disclosure): the
     * `claim.*` trio answers 404 / 410 / 409 with three DISTINCT registered
     * codes. That is contract-law bound: @phlix/contracts errors.ts registers
     * claim.code_not_found / claim.code_expired / claim.code_already_claimed
     * (registry md5 40c1da48 frozen — no new or collapsed codes from a client
     * repo), and the pinned consumer @phlix/ui src/api/claimServer.ts maps all
     * three to distinct user-facing kinds ("That claim code has expired.
     * Generate a new one on your server." is real pairing UX). Collapsing them
     * into one deny frame would break both. The oracle therefore REMAINS for a
     * caller that is already AUTHENTICATED (POST /claim sits behind
     * AuthMiddleware) and is capped at `60 × HUB_WORKERS` guesses/minute/IP by
     * the SERVER_CLAIMS limiter against a ~2^40 code space with 600 s TTL; the
     * per-guess `audit_logs` write that made grinding disk-cheap is gone
     * (ClaimRequestHandler de-amplification). What every arm DOES keep
     * byte-identical is the frame SHAPE: `{error, code, message}` — no arm
     * carries extra fields (no claim owner, no expiry timestamp, no row ids),
     * pinned by ServerClaimOracleUniformityTest.
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
            // M-HUB-04 wire law (kept) + M-HUB-35 completion: this frame is
            // honest END TO END — the registered `quota.exceeded` code, the
            // human sentence in BOTH text fields (`error` pre-fix parked the
            // SCREAMING machine literal, which user-facing renderers read),
            // and the `quota_kind`/`quota_reset` discriminators that tell any
            // client WHICH cap answered and WHEN it changes (caps are not
            // time-based: reset is 'on_release', never an invented epoch).
            // Pinned by QuotaExceededWireTest and ServerClaimQuotaMessageTest
            // — the frames rotate together with this emitter, by law. The
            // CLAIM_CODE_* neighbours deliberately keep the legacy SCREAMING
            // `error` field (claim-trio shape law, outside this ask). The
            // user-visible "already claimed" mislabel is still NOT produced
            // here: the pinned @phlix/ui claim layer resolves its failure
            // kind from a code table that lacks `quota.exceeded`, so this 409
            // falls to its status-keyed already-claimed copy until the ui tag
            // carries the mapping (cure owed to that repo; contracts law
            // freezes the code, so reshaping status/code here would break
            // every registered consumer).
            'SERVER_CAP_REACHED' => (new Response())->error(
                409,
                'quota.exceeded',
                QuotaFrames::SERVER_COUNT_TEXT,
                ['message' => QuotaFrames::SERVER_COUNT_TEXT]
                    + QuotaFrames::discriminator(QuotaFrames::KIND_SERVER_COUNT, QuotaFrames::RESET_ON_RELEASE),
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
            // M-HUB-13 (hostname-array bound): the payload's
            // hostnameCandidates exceed the count/length bounds justified on
            // the ClaimRequestHandler constants — a present-but-unparseable-
            // per-endpoint-spec field, the registered `invalid_payload`
            // family the fromPayload catch above already speaks (zero new
            // wire codes, same {error,code,message} shape).
            'HOSTNAME_CANDIDATES_INVALID' => (new Response())->error(
                400,
                'invalid_payload',
                'Bad Request',
                ['message' => 'hostnameCandidates exceed the pairing bounds'],
            ),
            // R-HUB-14 (minors sweep): the two legacy field markers from
            // ClaimRequestHandler::validateClaimRequest rode the 500 default
            // below — a caller payload-shape refusal answering
            // HUB_INTERNAL_ERROR. One addition: they join the same
            // registered `invalid_payload` family the fromPayload catch and
            // the HOSTNAME_CANDIDATES_INVALID neighbour speak (zero new wire
            // codes, same {error,code,message} shape). The bounds are the
            // handler's: serverName non-empty ≤ 255 bytes, version non-empty
            // ≤ 32 bytes.
            'Invalid server_name', 'Invalid version' => (new Response())->error(
                400,
                'invalid_payload',
                'Bad Request',
                ['message' => 'serverName must be 1-255 bytes; version must be 1-32 bytes'],
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
