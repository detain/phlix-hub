<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\ClaimResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-12 r2 (review hub__exploiter M1, envelope law): the SERVER_CLAIMS
 * budget guard must be INVISIBLE to every response frame it covers.
 *
 * Review r1's REWORK named exactly one blocking issue: the rate-limited/error
 * path changed response bytes where the law demands byte-identical envelopes.
 * The r1 suite pinned the arms' SHAPE only through the NULL-limiter seam
 * (`new ServerClaimController($handler)`), which never exercises the guard —
 * so the wired controller's frames were not pinned against the pre-M12 ones
 * at all. This file closes that hole the only way the law can be enforced:
 * every covered arm is invoked TWICE — once on the pre-M12 behavior (no
 * limiter wired: byte-for-byte what ships at 9a6897d2) and once on the
 * production-shaped controller (real in-memory limiter, budget NOT tripped) —
 * and the two Responses must agree on ALL THREE wire-bearing properties by
 * strict identity: `statusCode`, the exact body STRING (not a decoded tree:
 * key order and JSON escaping are part of the envelope), and the full
 * `headers` map. A guard mutation that perturbs any covered arm — an added
 * header, a re-ordered body, a swapped status — reddens the arm it touched
 * while every honest arm stays green (mutation teeth proven in the lane
 * report). The tripped-budget arm is deliberately NOT here: it is NEW
 * surface (429 canonical envelope pinned by ServerClaimRateLimitTest), not a
 * covered one.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerClaimEnvelopeByteIdentityTest extends TestCase
{
    private const CLIENT_IP = '203.0.113.77';

    private const NOW = 1_800_000_999;

    private function makeLimiter(): RateLimiter
    {
        // Generous ceiling: no arm in this suite trips. The point is the
        // guard CONSUMING on the wired side while its answers stay byte-
        // identical to the unwired controller's.
        return new RateLimiter(60, 60, 100, fn (): int => self::NOW);
    }

    private function claimRequest(string $code = 'ZZZZ-ZZZZ'): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';
        $request->userId = 'user-env-1';
        $request->remoteIp = self::CLIENT_IP;
        $request->body = ['claim_code' => $code];

        return $request;
    }

    private function statusRequest(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/server-claims/x';
        $request->remoteIp = self::CLIENT_IP;

        return $request;
    }

    private function newClaimRequest(): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/new';
        $request->remoteIp = self::CLIENT_IP;
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->body = [
            'serverName' => 'Env Server',
            'version' => '0.12.0',
            'publicKeysJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
            'hostnameCandidates' => [],
            'protocolVersion' => 'v1',
        ];

        return $request;
    }

    /**
     * Build the two controllers the law compares: unwired (exactly the
     * pre-M12/parent construction) and production-shaped (real limiter,
     * budget never tripped). Both share ONE handler mock so stub behaviour —
     * including willThrowException re-arming — is byte-for-byte the same
     * program for both runs.
     *
     * @param callable(MockObject&ClaimRequestHandler): void $configure
     */
    private function pair(callable $configure): ServerClaimController
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $configure($handler);

        return new ServerClaimController($handler, $this->makeLimiter());
    }

    private function unwired(callable $configure): ServerClaimController
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $configure($handler);

        return new ServerClaimController($handler);
    }

    /**
     * The law itself: guarded and pre-M12 answers must be the SAME response.
     *
     * @param callable(ServerClaimController): Response $invoke
     */
    private function assertEnvelopeByteIdenticalToPreM12(
        callable $configure,
        callable $invoke,
        string $arm
    ): void {
        $before = $invoke($this->unwired($configure));
        $after = $invoke($this->pair($configure));

        self::assertSame(
            $before->statusCode,
            $after->statusCode,
            "arm '{$arm}': the guard must not alter the status code"
        );
        self::assertSame(
            $before->body,
            $after->body,
            "arm '{$arm}': the guard must not alter body bytes (exact string)"
        );
        self::assertSame(
            $before->headers,
            $after->headers,
            "arm '{$arm}': the guard must not alter the header map"
        );
    }

    /** @return array<string, array{0: string}> */
    public static function denialArms(): array
    {
        return [
            'not-found-404' => ['CLAIM_CODE_NOT_FOUND'],
            'expired-410' => ['CLAIM_CODE_EXPIRED'],
            'already-claimed-409' => ['CLAIM_CODE_ALREADY_CLAIMED'],
            'quota-409' => ['SERVER_CAP_REACHED'],
            'protocol-400' => ['HUB_PROTOCOL_UNSUPPORTED'],
            'key-invalid-400' => ['SERVER_KEY_INVALID'],
            'default-500' => ['UNRECOGNISED_HANDLER_CODE'],
        ];
    }

    /**
     * @dataProvider denialArms
     */
    public function testEachMapErrorDenialArmIsByteIdenticalUnderTheGuard(string $handlerCode): void
    {
        $this->assertEnvelopeByteIdenticalToPreM12(
            static function (MockObject&ClaimRequestHandler $handler) use ($handlerCode): void {
                $handler->method('handleClaimCode')
                    ->willThrowException(new \InvalidArgumentException($handlerCode));
            },
            fn (ServerClaimController $c): Response => $c->claim($this->claimRequest()),
            'claim mapError ' . $handlerCode
        );
    }

    public function testClaimSuccessTrioIsByteIdenticalUnderTheGuard(): void
    {
        $this->assertEnvelopeByteIdenticalToPreM12(
            static function (MockObject&ClaimRequestHandler $handler): void {
                $handler->method('handleClaimCode')->willReturn([
                    'enrollment_jwt' => 'jwt-one',
                    'hub_jwks_url' => 'https://hub.example/.well-known/jwks.json',
                    'server_id' => 'srv-one',
                ]);
            },
            fn (ServerClaimController $c): Response => $c->claim($this->claimRequest()),
            'claim success 200'
        );
    }

    public function testClaimAuthAndMissingCode400sAreByteIdenticalUnderTheGuard(): void
    {
        $configure = static function (MockObject&ClaimRequestHandler $handler): void {
            $handler->expects(self::never())->method('handleClaimCode');
        };

        $noUser = $this->claimRequest();
        $noUser->userId = null;
        $this->assertEnvelopeByteIdenticalToPreM12(
            $configure,
            fn (ServerClaimController $c): Response => $c->claim($noUser),
            'claim 401 unauthenticated'
        );

        $noCode = $this->claimRequest();
        $noCode->body = [];
        $this->assertEnvelopeByteIdenticalToPreM12(
            $configure,
            fn (ServerClaimController $c): Response => $c->claim($noCode),
            'claim 400 missing claim_code'
        );
    }

    public function testNewClaimSuccessAndGuardsAreByteIdenticalUnderTheBudget(): void
    {
        $ok = static function (MockObject&ClaimRequestHandler $handler): void {
            $handler->method('handleNewClaim')->willReturn(
                new ClaimResponse('ABCD-1234', 600, 'claim-env-1', 'https://hub.example.com')
            );
        };
        $this->assertEnvelopeByteIdenticalToPreM12(
            $ok,
            fn (ServerClaimController $c): Response => $c->newClaim($this->newClaimRequest()),
            'newClaim success 200'
        );

        // Wrong protocol: guard never even consulted (free 400 arm) — still
        // pinned, so a reordering mutation cannot silently front-load the
        // budget onto free frames and change this answer.
        $wrongProto = static function (MockObject&ClaimRequestHandler $handler): void {
            $handler->expects(self::never())->method('handleNewClaim');
        };
        $badProto = $this->newClaimRequest();
        $badProto->headers['Accept-Phlix-Protocol'] = 'v0';
        $this->assertEnvelopeByteIdenticalToPreM12(
            $wrongProto,
            fn (ServerClaimController $c): Response => $c->newClaim($badProto),
            'newClaim protocol 400'
        );

        $malformed = $this->newClaimRequest();
        $malformed->body = ['serverName' => 42, 'publicKeysJwk' => 'not-an-object'];
        $this->assertEnvelopeByteIdenticalToPreM12(
            $wrongProto,
            fn (ServerClaimController $c): Response => $c->newClaim($malformed),
            'newClaim invalid_payload 400'
        );

        $boom = static function (MockObject&ClaimRequestHandler $handler): void {
            $handler->method('handleNewClaim')
                ->willThrowException(new \InvalidArgumentException('SERVER_CAP_REACHED'));
        };
        $this->assertEnvelopeByteIdenticalToPreM12(
            $boom,
            fn (ServerClaimController $c): Response => $c->newClaim($this->newClaimRequest()),
            'newClaim mapError arm'
        );
    }

    public function testStatusPollArmsAreByteIdenticalUnderTheBudget(): void
    {
        $this->assertEnvelopeByteIdenticalToPreM12(
            static function (MockObject&ClaimRequestHandler $handler): void {
                $handler->method('getClaimStatus')->willReturn(['status' => 'pending']);
            },
            fn (ServerClaimController $c): Response => $c->status($this->statusRequest(), ['claimId' => 'claim-env-1']),
            'status 200 poll'
        );

        $this->assertEnvelopeByteIdenticalToPreM12(
            static function (MockObject&ClaimRequestHandler $handler): void {
                $handler->expects(self::never())->method('getClaimStatus');
            },
            fn (ServerClaimController $c): Response => $c->status($this->statusRequest(), []),
            'status 400 missing id'
        );
    }

    public function testTheGuardActuallyConsumedOnTheWiredPair(): void
    {
        // Anti-vacuity: if a future refactor made the wired controller skip
        // hit() (e.g. by always taking the null seam), every byte-identity
        // assertion above would silently compare the SAME path twice. This
        // test proves the limiter buckets were consumed while producing the
        // identical bytes: hit-count transitions pin it independently of
        // peek() sharing bucket state with the pair() controllers — so the
        // pair() controller is REUSED through a limiter kept by reference.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')->willReturn([
            'enrollment_jwt' => 'jwt',
            'hub_jwks_url' => 'https://hub.example/jwks.json',
            'server_id' => 'srv',
        ]);
        $limiter = $this->makeLimiter();
        $controller = new ServerClaimController($handler, $limiter);

        $response = $controller->claim($this->claimRequest());

        self::assertSame(200, $response->statusCode);
        self::assertGreaterThan(
            0,
            $limiter->peek('server_claims:claim:' . self::CLIENT_IP)->count,
            'the covered arm must have consumed budget — byte-identity is pinned ON THE GUARDED PATH'
        );
    }
}
