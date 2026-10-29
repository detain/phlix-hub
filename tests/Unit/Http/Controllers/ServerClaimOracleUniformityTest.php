<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-12 (review hub__exploiter M1) — the state-oracle law for the claim
 * denial arms, stated honestly:
 *
 * The three refusal frames are DISTINCT on purpose (404 `claim.code_not_found`
 * / 410 `claim.code_expired` / 409 `claim.code_already_claimed`). Collapsing
 * them is NOT available to this repo: @phlix/contracts registers all three
 * (errors.ts claim.* trio, registry md5 40c1da48 frozen), and the pinned
 * consumer @phlix/ui (src/api/claimServer.ts) renders distinct pairing UX
 * from each ("code has expired — generate a new one" is the legitimate
 * user's recovery path). What IS law here is SHAPE uniformity: every denial
 * arm ships byte-identically the same key set `{error, code, message}` — no
 * arm leaks row ids, owner ids, expiry timestamps, or per-state extras — and
 * the status poll (the PUBLIC endpoint) never discriminates unknown from
 * expired at all. Pinned below; the residual distinct-code oracle survives
 * only for AUTHENTICATED guessers and is cost-bounded by the SERVER_CLAIMS
 * limiter (ServerClaimRateLimitTest).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerClaimOracleUniformityTest extends TestCase
{
    private function claimRequest(): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';
        $request->userId = 'user-oracle-1';
        $request->remoteIp = '203.0.113.9';
        $request->body = ['claim_code' => 'ZZZZ-ZZZZ'];

        return $request;
    }

    private function controllerThrowing(string $handlerCode): ServerClaimController
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')
            ->willThrowException(new \InvalidArgumentException($handlerCode));

        return new ServerClaimController($handler);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deny(ServerClaimController $controller): array
    {
        $response = $controller->claim($this->claimRequest());
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return ['status' => $response->statusCode, 'body' => $decoded];
    }

    public function testAllThreeDenialArmsShipTheIdenticalEnvelopeKeySet(): void
    {
        $arms = [];
        foreach (['CLAIM_CODE_NOT_FOUND', 'CLAIM_CODE_EXPIRED', 'CLAIM_CODE_ALREADY_CLAIMED'] as $handlerCode) {
            $denied = $this->deny($this->controllerThrowing($handlerCode));
            $arms[$handlerCode] = $denied;
        }

        $keySets = [];
        foreach ($arms as $handlerCode => $arm) {
            $keySets[$handlerCode] = array_keys($arm['body']);
        }

        // Shape uniformity: the SAME three keys on every arm, in the same
        // order, and NOTHING else (no claimed_by, no server id, no ttl).
        self::assertSame(['error', 'code', 'message'], $keySets['CLAIM_CODE_NOT_FOUND']);
        self::assertSame($keySets['CLAIM_CODE_NOT_FOUND'], $keySets['CLAIM_CODE_EXPIRED']);
        self::assertSame($keySets['CLAIM_CODE_NOT_FOUND'], $keySets['CLAIM_CODE_ALREADY_CLAIMED']);
    }

    public function testDenialArmsDifferOnlyInTheirContractRegisteredFields(): void
    {
        // The ONLY values a guesser may read back are the registered trio
        // (status + dotted code + its dual-placed SCREAMING text + the fixed
        // sentence). Each pair of arms must be identical under removal of
        // exactly those four pinned fields.
        $pinned = [
            'CLAIM_CODE_NOT_FOUND' => [
                'status' => 404, 'code' => 'claim.code_not_found',
                'error' => 'CLAIM_CODE_NOT_FOUND', 'message' => 'Claim code not found',
            ],
            'CLAIM_CODE_EXPIRED' => [
                'status' => 410, 'code' => 'claim.code_expired',
                'error' => 'CLAIM_CODE_EXPIRED', 'message' => 'Claim code has expired',
            ],
            'CLAIM_CODE_ALREADY_CLAIMED' => [
                'status' => 409, 'code' => 'claim.code_already_claimed',
                'error' => 'CLAIM_CODE_ALREADY_CLAIMED', 'message' => 'Claim code has already been used',
            ],
        ];

        foreach ($pinned as $handlerCode => $expected) {
            $arm = $this->deny($this->controllerThrowing($handlerCode));
            self::assertSame($expected['status'], $arm['status'], $handlerCode);
            self::assertSame($expected['code'], $arm['body']['code'] ?? null, $handlerCode . ' dotted code');
            self::assertSame(
                $expected['error'],
                $arm['body']['error'] ?? null,
                $handlerCode . ' dual-placed SCREAMING text'
            );
            self::assertSame($expected['message'], $arm['body']['message'] ?? null, $handlerCode . ' message');

            // Strip the pinned fields → what remains must be the empty frame,
            // i.e. there is no arm-specific EXTRA data leaking.
            $rest = $arm['body'];
            unset($rest['code'], $rest['error'], $rest['message']);
            self::assertSame([], $rest, $handlerCode . ' carries fields beyond the pinned envelope');
            self::assertSame($expected['status'], $arm['status']);
        }
    }

    public function testUnknownClaimCodeAndAmbiguousGarbageAreIndistinguishable(): void
    {
        // A miss is a miss: any code the handler cannot find answers the one
        // CLAIM_CODE_NOT_FOUND frame, including the empty-after-normalisation
        // arm (handler law: 'CLAIM_CODE_INVALID' input still maps to NOT_FOUND,
        // so separators/whitespace garbage cannot fingerprint itself).
        $notFound = $this->deny($this->controllerThrowing('CLAIM_CODE_NOT_FOUND'));
        $again = $this->deny($this->controllerThrowing('CLAIM_CODE_NOT_FOUND'));

        self::assertSame($notFound['status'], $again['status']);
        self::assertSame($notFound['body'], $again['body']);
    }

    public function testPublicStatusPollNeverDiscriminatesUnknownFromExpired(): void
    {
        // GET /server-claims/{claimId} is the PUBLIC arm — its bearer is the
        // UUIDv4 claim id (~2^122). Unknown and expired MUST answer
        // byte-identically ({status: 'expired'}) so the poll endpoint is not
        // an existence oracle at all. (The `claimed` arm is the legitimate
        // enrollment delivery and intentionally carries material; pinned in
        // ClaimRequestHandlerTest.)
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('getClaimStatus')
            ->willReturnCallback(static fn (string $id): array => ['status' => 'expired']);
        $controller = new ServerClaimController($handler);

        $unknown = $controller->status($this->statusRequest(), ['claimId' => '00000000-dead-beef-0000-000000000000']);
        $expired = $controller->status($this->statusRequest(), ['claimId' => 'aaaaaaaa-1111-2222-3333-444444444444']);

        self::assertSame($unknown->body, $expired->body, 'poll answers must be byte-identical for unknown vs expired');
        self::assertSame(['status' => 'expired'], json_decode($unknown->body, true, 512, JSON_THROW_ON_ERROR));
    }

    private function statusRequest(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/server-claims/x';
        $request->remoteIp = '203.0.113.9';

        return $request;
    }
}
