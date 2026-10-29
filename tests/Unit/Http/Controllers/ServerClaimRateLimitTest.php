<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use Phlix\Shared\Hub\ClaimResponse;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-12 (review hub__exploiter M1): the three server-claim pairing
 * endpoints must be budget-guarded per trusted client IP, with the buckets
 * kept separate per action and exactly ONE audit_logs row written per window
 * transition into "over budget" — never per refusal.
 *
 * The limiter is the REAL worker-local {@see RateLimiter} (with an injected
 * clock for determinism): the point of this suite is the controller's USE of
 * the mechanism (keys, order, refusal envelope, audit transition), which a
 * mocked interface could hide. The claim handler is a mock because its DB
 * semantics are pinned by ClaimRequestHandlerTest / the real-DB norm suite.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerClaimRateLimitTest extends TestCase
{
    private const CLIENT_IP = '203.0.113.7';

    /** Fixed clock: the window never rolls mid-test. */
    private const NOW = 1_800_000_000;

    private int $now = self::NOW;

    private function claimRequest(string $code = 'ZZZZ-ZZZZ'): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';
        $request->userId = 'user-rl-1';
        $request->remoteIp = self::CLIENT_IP;
        $request->body = ['claim_code' => $code];

        return $request;
    }

    private function statusRequest(string $claimId = 'claim-1'): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/server-claims/' . $claimId;
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
            'serverName' => 'RL Server',
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

    private function makeLimiter(int $max): RateLimiter
    {
        return new RateLimiter(60, $max, 100, fn (): int => $this->now);
    }

    public function testClaimGuessesStopAtTheBudgetOnThePerActionIpBucket(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $calls = 0;
        $handler->method('handleClaimCode')
            ->willReturnCallback(
                /** @return array{enrollment_jwt: string, hub_jwks_url: string, server_id: string} */
                static function (string $code, string $user) use (&$calls): array {
                    $calls++;
                    return [
                        'enrollment_jwt' => 'jwt',
                        'hub_jwks_url' => 'https://hub/.well-known/jwks.json',
                        'server_id' => 'srv-1',
                    ];
                },
            );

        $limiter = $this->makeLimiter(3);
        $controller = new ServerClaimController($handler, $limiter);

        // max=3 → attempts 1 and 2 pass (the window-REACHING request is the
        // first refusal): the third guess never touches the handler.
        $first = $controller->claim($this->claimRequest('AAAA-AAAA'));
        $second = $controller->claim($this->claimRequest('BBBB-BBBB'));
        self::assertSame(200, $first->statusCode);
        self::assertSame(200, $second->statusCode);
        self::assertSame(2, $calls, 'exactly max-1 guesses may reach the claim lookup per window');

        $tripped = false;
        try {
            $controller->claim($this->claimRequest('CCCC-CCCC'));
        } catch (RateLimitException) {
            $tripped = true;
        }
        self::assertTrue($tripped, 'the ceiling-reaching guess must trip the bucket');
        self::assertSame(2, $calls, 'the refused guess never reaches the claim lookup');
        self::assertTrue(
            $limiter->peek('server_claims:claim:' . self::CLIENT_IP)->limited,
            'the consumed bucket is the per-action, per-IP key server_claims:claim:<ip>',
        );
    }

    public function testExhaustedClaimBucketDoesNotBlindTheStatusPollOrNewClaim(): void
    {
        // Per-action keys are the design (the 2 s CLI poll must not burn the
        // guess budget, nor be burned by it): after the claim bucket trips,
        // status() and newClaim() still reach the handler.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')->willReturn(
            ['enrollment_jwt' => 'jwt', 'hub_jwks_url' => 'u', 'server_id' => 's'],
        );
        $handler->method('getClaimStatus')->willReturn(['status' => 'pending']);
        $handler->method('handleNewClaim')->willReturn(
            new ClaimResponse('ABCD-1234', 600, 'claim-1', 'https://hub.example.com'),
        );

        $limiter = $this->makeLimiter(2);
        $controller = new ServerClaimController($handler, $limiter);

        $controller->claim($this->claimRequest()); // consumes 1
        try {
            $controller->claim($this->claimRequest()); // trips (count==max)
            self::fail('expected the claim bucket to trip at its ceiling');
        } catch (RateLimitException) {
            // expected
        }

        $status = $controller->status($this->statusRequest(), ['claimId' => 'claim-1']);
        self::assertSame(200, $status->statusCode);
        /** @var mixed $statusBody */
        $statusBody = json_decode($status->body, true);
        self::assertIsArray($statusBody);
        self::assertSame('pending', $statusBody['status'] ?? null);

        $new = $controller->newClaim($this->newClaimRequest());
        self::assertSame(200, $new->statusCode);

        // The refusal was scoped to the CLAIM bucket only.
        self::assertFalse($limiter->peek('server_claims:status:' . self::CLIENT_IP)->limited);
        self::assertTrue($limiter->peek('server_claims:claim:' . self::CLIENT_IP)->limited);
    }

    public function testTrippedBudgetAnswersTheCentralRateLimitedEnvelope(): void
    {
        // No new wire codes: the claim 429 is the SAME registered envelope
        // proxy/jwks/alexa use ({error, code: 'rate_limited'} + Retry-After),
        // produced by the single central Application mapping this throw
        // deliberately reaches (the guard runs OUTSIDE the controllers'
        // InvalidArgumentException catches).
        $handler = $this->createMock(ClaimRequestHandler::class);
        $limiter = $this->makeLimiter(1); // first request already trips
        $controller = new ServerClaimController($handler, $limiter);

        try {
            $controller->claim($this->claimRequest());
            self::fail('expected RateLimitException on the first request over a ceiling of 1');
        } catch (RateLimitException $e) {
            $response = Application::rateLimitResponse($e);
        }

        self::assertSame(429, $response->statusCode);
        self::assertGreaterThanOrEqual(0, (int) ($response->headers['Retry-After'] ?? -1));
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        self::assertSame(['error', 'code'], array_keys($body));
        self::assertSame('Too Many Requests', $body['error']);
        self::assertSame('rate_limited', $body['code']);

        $handler->expects(self::never())->method('handleClaimCode');
    }

    public function testAuditRowIsWrittenExactlyOncePerWindowNotPerRefusal(): void
    {
        // The transition-only law: the FIRST refusal of the window persists
        // one audit_logs row (signal preserved); every further refusal must
        // NOT — otherwise the 429 path would become the new attacker-paced
        // audit-table write vector this commit exists to remove.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')->willReturn(
            ['enrollment_jwt' => 'jwt', 'hub_jwks_url' => 'u', 'server_id' => 's'],
        );

        /** @var list<array{reason: string, context: array<string, mixed>}> $rows */
        $rows = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason, array $context = []) use (&$rows): void {
                $rows[] = ['reason' => $reason, 'context' => $context];
            },
        );

        $limiter = $this->makeLimiter(2);
        $controller = new ServerClaimController($handler, $limiter, $audit);

        $controller->claim($this->claimRequest()); // 1: allowed, no row
        foreach ([3, 4, 5] as $ignored) {
            try {
                $controller->claim($this->claimRequest());
            } catch (RateLimitException) {
                // refused
            }
            $this->now++; // stay inside the 60 s window
        }

        self::assertCount(1, $rows, 'a burst of refusals persists exactly one audit row (the window transition)');
        self::assertSame('claim_code_rate_limited', $rows[0]['reason']);
        self::assertSame(['surface' => 'claim'], $rows[0]['context']);
    }

    public function testUnwiredLimiterDegradesToTheDocumentedPreM12Behavior(): void
    {
        // Pin of the nullable seam documented on the constructor: unit-test /
        // unbooted-pool construction without a limiter must NOT fail closed
        // (the production guarantee lives in ServerClaimRateLimitWiringTest,
        // which proves the real container always supplies one).
        $handler = $this->createMock(ClaimRequestHandler::class);
        $calls = 0;
        $handler->method('handleClaimCode')->willReturnCallback(
            /** @return array{enrollment_jwt: string, hub_jwks_url: string, server_id: string} */
            static function (string $code, string $user) use (&$calls): array {
                $calls++;
                return ['enrollment_jwt' => 'jwt', 'hub_jwks_url' => 'u', 'server_id' => 's'];
            },
        );
        $controller = new ServerClaimController($handler);

        for ($i = 0; $i < 20; $i++) {
            self::assertSame(200, $controller->claim($this->claimRequest())->statusCode);
        }
        self::assertSame(20, $calls);
    }

    public function testFreeGuardFramesDoNotConsumeTheBudget(): void
    {
        // Missing claim_code / missing claim id answer 400 before the bucket
        // is consulted: garbage floods are cheap for the attacker but must
        // not be able to lock an IP out of its legitimate pairing budget.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $limiter = $this->makeLimiter(2);
        $controller = new ServerClaimController($handler, $limiter);

        for ($i = 0; $i < 10; $i++) {
            $noCode = $this->claimRequest();
            $noCode->body = [];
            self::assertSame(400, $controller->claim($noCode)->statusCode);

            $noId = $this->statusRequest();
            self::assertSame(400, $controller->status($noId, [])->statusCode);
        }

        self::assertFalse($limiter->peek('server_claims:claim:' . self::CLIENT_IP)->limited);
        self::assertFalse($limiter->peek('server_claims:status:' . self::CLIENT_IP)->limited);

        // And with budget intact, a real guess still reaches the handler.
        $handler->expects(self::once())->method('handleClaimCode')->willReturn(
            ['enrollment_jwt' => 'jwt', 'hub_jwks_url' => 'u', 'server_id' => 's'],
        );
        self::assertSame(200, $controller->claim($this->claimRequest())->statusCode);
    }

    public function testUnknownPeerFallsBackToASingleOpaqueKeyNotAnEmptyBucket(): void
    {
        // `?: 'unknown'` law (mirrors AuthController): a request without a
        // usable IP still lands in ONE conservative bucket, never a key whose
        // empty suffix could collide with a real address shape.
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')->willReturn(
            ['enrollment_jwt' => 'jwt', 'hub_jwks_url' => 'u', 'server_id' => 's'],
        );
        $limiter = $this->makeLimiter(2);
        $controller = new ServerClaimController($handler, $limiter);

        $request = $this->claimRequest();
        $request->remoteIp = '0.0.0.0'; // loopback peer w/o XFF → resolver keeps '0.0.0.0'…
        // …unless TRUSTED_PROXIES default (loopback) treats it as a proxy with no
        // forwarded header; either way the bucket key is non-empty:
        self::assertSame(200, $controller->claim($request)->statusCode);

        $accountedFor = $limiter->peek('server_claims:claim:0.0.0.0')->count > 0
            || $limiter->peek('server_claims:claim:unknown')->count > 0;
        // One of the two buckets MUST carry the attempt; an empty-key bucket
        // would carry NEITHER.
        self::assertTrue($accountedFor, 'the guess landed in a named bucket, not a collapsed empty key');
    }
}
