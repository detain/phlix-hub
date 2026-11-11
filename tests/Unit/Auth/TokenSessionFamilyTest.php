<?php

/**
 * CP-029 / D-1a hub twin — session-family law suite.
 *
 * Mirrors the server's TokenSessionFamilyTest (413765b6) leg for leg where
 * the hub has the same seam, and pins the documented hub deviations by name:
 * the collapsed refusal text (no-probe law), the carrier-only logout, the
 * absent watermark/nuclear choke, and the claims echo that never grows a
 * `sid` key (the fixed-field JwtClaims DTO is the reason — pinned here so a
 * future shared-DTO change cannot silently widen the wire).
 *
 * The family store is ALWAYS the collaborator double here: a real MySQL leg
 * lives in TokenSessionFamilyMigration055RealDbTest. What this suite owns is
 * the decision table — which arm fires, in which order, what gets written,
 * what must NEVER be touched, and what the caller sees.
 *
 * Phlix hub test: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\RefreshTokenRevocationService;
use Phlix\Hub\Auth\TokenSessionRepository;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Shared\Auth\JwtClaims;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TokenSessionFamilyTest extends TestCase
{
    private const SECRET = 'an-extra-long-test-secret-with-32-bytes-min';
    private const USER_ID = 'u-1';

    protected function setUp(): void
    {
        AuthMiddleware::resetCache();
    }

    // ------------------------------------------------------------------
    // Mint / rotation ceremony
    // ------------------------------------------------------------------

    public function testLegacyRotationOpensAFamilyAndRegistersTheMintedJti(): void
    {
        // Genesis: an UNWIRED manager mints the historical (claim-less) pair.
        $legacy = $this->manager(null)->refresh(
            $this->legacyRefreshToken(),
        );
        self::assertArrayNotHasKey('sid', $legacy['claims']);

        $grants = [];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('createSession')
            ->with(self::USER_ID)
            ->willReturn('fam-1');
        $store->expects(self::once())
            ->method('recordGrant')
            ->willReturnCallback(static function (string $jti, string $sid) use (&$grants): void {
                $grants[$jti] = $sid;
            });
        $store->expects(self::never())->method('touchSession');

        $rotated = $this->manager($store)->refresh($legacy['refresh_token']);

        // The family travels on BOTH new tokens …
        $jwt = new JwtHandler(self::SECRET);
        self::assertSame('fam-1', self::sessionOf($jwt->validateAccessTokenWithSession($rotated['access_token'])));
        self::assertSame('fam-1', self::sessionOf($jwt->validateRefreshTokenWithSession($rotated['refresh_token'])));
        // … and the grant registered is the ONE the handler actually signed
        // (read back from the minted token, never trusted from the builder).
        $mintedJti = self::jtiOf($jwt->validateTokenWithSession($rotated['refresh_token']));
        self::assertSame(['fam-1'], array_values($grants));
        self::assertArrayHasKey($mintedJti, $grants);
        // Wire envelope stays byte-ident: the DTO echo drops the sid claim.
        self::assertArrayNotHasKey('sid', $rotated['claims']);
    }

    public function testRotationContinuesInsideTheFamilyAndConsumesBeforeMinting(): void
    {
        $presented = $this->familyRefreshToken('fam-1');
        $jti = self::jtiOf((new JwtHandler(self::SECRET))->validateTokenWithSession($presented));

        $log = [];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::never())->method('createSession');
        $store->method('findGrant')
            ->with($jti, 10)
            ->willReturn([
                'jti' => $jti, 'sessionId' => 'fam-1', 'sessionStatus' => 'active',
                'consumed' => false, 'withinGrace' => false,
            ]);
        $store->method('markGrantConsumed')->willReturnCallback(
            static function () use (&$log): void {
                $log[] = 'consumed';
            }
        );
        $store->method('recordGrant')->willReturnCallback(
            static function () use (&$log): void {
                $log[] = 'granted';
            }
        );
        $store->method('touchSession')->willReturnCallback(
            static function (string $sid) use (&$log): void {
                $log[] = 'touched:' . $sid;
            }
        );
        $store->expects(self::never())->method('revokeSession');

        $rotated = $this->manager($store)->refresh($presented);

        // Consume-before-mint is the whole anti-theft timing law; the hub's
        // createAuthResponse then records and touches. Pin the measured
        // order exactly: ['consumed', 'granted', 'touched:fam-1'].
        self::assertSame(['consumed', 'granted', 'touched:fam-1'], $log);

        $jwt = new JwtHandler(self::SECRET);
        self::assertSame('fam-1', self::sessionOf($jwt->validateRefreshTokenWithSession($rotated['refresh_token'])));
    }

    public function testUnwiredStoreMintsTheLegacyShapeExactly(): void
    {
        $legacy = $this->legacyRefreshToken();
        $rotated = $this->manager(null)->refresh($legacy);

        $jwt = new JwtHandler(self::SECRET);
        self::assertNull(self::sessionOf($jwt->validateAccessTokenWithSession($rotated['access_token'])));
        self::assertNull(self::sessionOf($jwt->validateRefreshTokenWithSession($rotated['refresh_token'])));
        self::assertArrayNotHasKey('sid', $rotated['claims']);
        // The historical six-key envelope stands untouched.
        self::assertSame(
            ['access_token', 'refresh_token', 'token_type', 'expires_in', 'user', 'claims'],
            array_keys($rotated),
        );
    }

    public function testLoginOpensAFamilyAndStampsBothTokens(): void
    {
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('createSession')->with(self::USER_ID)->willReturn('fam-new');
        $store->expects(self::once())->method('recordGrant');

        [$repo, $jwt, $audit, $logger, $rateLimiter] = $this->deps();
        $repo->method('findByUsername')->willReturn(['id' => self::USER_ID, 'username' => 'nina']);
        $repo->method('verifyPassword')->willReturn(true);
        $manager = new AuthManager(
            $repo,
            $jwt,
            $audit,
            $logger,
            $rateLimiter,
            null,
            null,
            null,
            true,
            null,
            null,
            $store,
        );

        $response = $manager->login('nina', 'correct-pw', '203.0.113.9');

        self::assertSame('fam-new', self::sessionOf($jwt->validateAccessTokenWithSession($response['access_token'])));
        self::assertSame('fam-new', self::sessionOf($jwt->validateRefreshTokenWithSession($response['refresh_token'])));
        self::assertArrayNotHasKey('sid', $response['claims']);
    }

    // ------------------------------------------------------------------
    // Reuse detection — the decision table, arm by arm
    // ------------------------------------------------------------------

    public function testReuseOutsideGraceRevokesTheFamilyOnlyAndRefuses(): void
    {
        $audited = [];
        $presented = $this->familyRefreshToken('fam-1');
        $jti = self::jtiOf((new JwtHandler(self::SECRET))->validateTokenWithSession($presented));

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => (string) $jti, 'sessionId' => 'fam-1', 'sessionStatus' => 'active',
            'consumed' => true, 'withinGrace' => false,
        ]);
        $store->expects(self::once())->method('revokeSession')->with('fam-1', 'refresh_token_reuse');
        $store->expects(self::never())->method('markGrantConsumed');
        $store->expects(self::never())->method('createSession');

        $carrier = $this->createMock(RefreshTokenRevocationService::class);
        $carrier->method('isRevoked')->willReturn(false);
        $carrier->expects(self::never())->method('revoke');

        $this->expectExceptionMessage('Invalid or expired refresh token');
        try {
            $this->manager($store, $carrier, $audited)->refresh($presented);
        } finally {
            // Family death never reaches the carrier layer (COOKIE-DESIGN:
            // orthogonal jobs), and the audit reason is the ONLY place the
            // distinction lives (the wire text is collapsed by law).
            self::assertSame([['refresh_token_reuse', [
                'user_id' => self::USER_ID, 'context' => 'refresh', 'session_id' => 'fam-1', 'jti' => $jti,
            ]]], $audited);
        }
    }

    public function testReplayInsideGraceRotatesOnWithoutRevocation(): void
    {
        $presented = $this->familyRefreshToken('fam-1');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => 'anything', 'sessionId' => 'fam-1', 'sessionStatus' => 'active',
            'consumed' => true, 'withinGrace' => true,
        ]);
        $store->expects(self::never())->method('revokeSession');
        $store->expects(self::never())->method('markGrantConsumed');
        $store->expects(self::once())->method('recordGrant');
        $store->expects(self::once())->method('touchSession')->with('fam-1');

        $rotated = $this->manager($store)->refresh($presented);
        self::assertSame(
            'fam-1',
            self::sessionOf((new JwtHandler(self::SECRET))->validateRefreshTokenWithSession($rotated['refresh_token'])),
        );
    }

    public function testRevokedFamilyRefusesRefreshWithoutReRevoking(): void
    {
        $audited = [];
        $presented = $this->familyRefreshToken('fam-1');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => 'anything', 'sessionId' => 'fam-1', 'sessionStatus' => 'revoked',
            'consumed' => true, 'withinGrace' => false,
        ]);
        // A replay must not rewrite the kill that already happened.
        $store->expects(self::never())->method('revokeSession');
        $store->expects(self::never())->method('markGrantConsumed');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->manager($store, null, $audited)->refresh($presented);
        } finally {
            self::assertSame('token_session_revoked', $audited[0][0] ?? 'NOT-REACHED');
        }
    }

    public function testUnknownGrantFailsClosed(): void
    {
        $audited = [];
        $presented = $this->familyRefreshToken('fam-1');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn(null);
        $store->expects(self::never())->method('markGrantConsumed');
        $store->expects(self::never())->method('revokeSession');
        $store->expects(self::never())->method('recordGrant');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->manager($store, null, $audited)->refresh($presented);
        } finally {
            self::assertSame('refresh_grant_unknown', $audited[0][0] ?? 'NOT-REACHED');
        }
    }

    public function testGrantSessionMismatchDies(): void
    {
        $audited = [];
        $presented = $this->familyRefreshToken('fam-2');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => 'anything', 'sessionId' => 'fam-1', 'sessionStatus' => 'active',
            'consumed' => false, 'withinGrace' => false,
        ]);
        $store->expects(self::never())->method('markGrantConsumed');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->manager($store, null, $audited)->refresh($presented);
        } finally {
            self::assertSame('refresh_grant_session_mismatch', $audited[0][0] ?? 'NOT-REACHED');
        }
    }

    public function testSidClaimAgainstUnwiredStoreFailsClosed(): void
    {
        $audited = [];
        $presented = $this->familyRefreshToken('fam-1');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->manager(null, null, $audited)->refresh($presented);
        } finally {
            self::assertSame('token_store_unwired', $audited[0][0] ?? 'NOT-REACHED');
        }
    }

    public function testJtilessFamilyTokenFailsClosed(): void
    {
        $audited = [];
        // Hand-crafted below every builder: a refresh token that names a
        // family but carries no jti — the arm (b) degeneracy the store can
        // never see through the API, so the adjudicator must die on it.
        $token = $this->handcraftedRefreshToken('fam-hand', withJti: false);

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::never())->method('findGrant');
        $store->expects(self::never())->method('markGrantConsumed');

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->manager($store, null, $audited)->refresh($token);
        } finally {
            self::assertSame('refresh_grant_jti_missing', $audited[0][0] ?? 'NOT-REACHED');
        }
    }

    // ------------------------------------------------------------------
    // Access-path family read (middleware seam)
    // ------------------------------------------------------------------

    public function testAccessFamilyReadIsLiveEveryRequest(): void
    {
        [$token] = $this->familyAccessPair('fam-1');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::exactly(2))->method('isSessionActive')->with('fam-1')->willReturn(true);

        $middleware = $this->middleware($store);
        self::assertNull($middleware($this->apiRequest($token)));
        self::assertNull($middleware($this->apiRequest($token)));
    }

    public function testLegacyAccessNeverConsultsTheStoreAndShapeIsUntouched(): void
    {
        $token = $this->familyAccessPair(null)[0];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::never())->method('isSessionActive');

        $request = $this->apiRequest($token);
        self::assertNull($this->middleware($store)($request));
        self::assertSame(self::USER_ID, $request->userId);
        self::assertNull($request->tokenSessionId);
        self::assertInstanceOf(JwtClaims::class, $request->claims);
    }

    public function testAccessOnRevokedFamilyAnswersTheHistoricalUnauthorizedFrame(): void
    {
        [$token] = $this->familyAccessPair('fam-1');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('isSessionActive')->with('fam-1')->willReturn(false);

        $response = $this->middleware($store)($this->apiRequest($token));
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        // Zero new wire codes: the SAME frame every dead credential gets.
        self::assertSame(
            ['error' => 'Unauthorized', 'code' => 'auth.invalid_token'],
            json_decode($response->body, true),
        );
    }

    public function testUnwiredStoreRefusesNamedFamilyWithSameChallengeOnly(): void
    {
        [$token] = $this->familyAccessPair('fam-1');

        $response = $this->middleware(null)($this->apiRequest($token));
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertSame(
            ['error' => 'Unauthorized', 'code' => 'auth.invalid_token'],
            json_decode($response->body, true),
        );
    }

    public function testLiveFamilyHydratesTheRequestIdWithoutTouchingAnythingElse(): void
    {
        [$token] = $this->familyAccessPair('fam-9');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('isSessionActive')->willReturn(true);
        $store->expects(self::never())->method('findGrant');

        $request = $this->apiRequest($token);
        self::assertNull($this->middleware($store)($request));
        self::assertSame('fam-9', $request->tokenSessionId);
    }

    // ------------------------------------------------------------------
    // Per-device revocation + listing
    // ------------------------------------------------------------------

    public function testPerDeviceRevokeNarrowsToExactlyOneFamily(): void
    {
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('sessionBelongsToUser')->with('fam-a', self::USER_ID)->willReturn(true);
        $store->expects(self::once())->method('revokeSession')->with('fam-a', 'logout');
        $store->expects(self::never())->method('revokeAllForUser');

        $audited = [];
        self::assertTrue($this->manager($store, auditSink: $audited)->revokeTokenSession(self::USER_ID, 'fam-a'));
        // The device's death is an audit logout for exactly that family —
        // logLogout is the historical sink, no new event shape.
        self::assertSame([['logout:' . self::USER_ID . ':fam-a', []]], $audited);
    }

    public function testForeignAndUnknownIdsBothRefuseWithoutWriting(): void
    {
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('sessionBelongsToUser')->willReturn(false);
        $store->expects(self::never())->method('revokeSession');

        $manager = $this->manager($store);
        self::assertFalse($manager->revokeTokenSession(self::USER_ID, 'fam-other-user'));
        self::assertFalse($manager->revokeTokenSession(self::USER_ID, 'fam-never-minted'));
    }

    public function testBlankInputsAndUnwiredStoreReturnFalse(): void
    {
        self::assertFalse($this->manager(null)->revokeTokenSession(self::USER_ID, 'fam-1'));
        self::assertSame([], $this->manager(null)->listTokenSessions(self::USER_ID));

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::never())->method('sessionBelongsToUser');
        self::assertFalse($this->manager($store)->revokeTokenSession('', 'fam-1'));
        self::assertFalse($this->manager($store)->revokeTokenSession(self::USER_ID, ''));
    }

    public function testListTokenSessionsDelegatesAndDegenerates(): void
    {
        $rows = [
            ['id' => 'fam-1', 'created_at' => 'x', 'last_used_at' => 'y', 'device_type' => null, 'device_name' => null],
        ];
        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::once())->method('listActiveSessions')->with(self::USER_ID)->willReturn($rows);
        self::assertSame($rows, $this->manager($store)->listTokenSessions(self::USER_ID));
        self::assertSame([], $this->manager($store)->listTokenSessions(''));
    }

    // ------------------------------------------------------------------
    // Carrier-only logout (hub deviation, pinned)
    // ------------------------------------------------------------------

    public function testLogoutNeverConsultsTheFamilyStore(): void
    {
        $token = $this->familyRefreshToken('fam-1');

        $carrier = $this->createMock(RefreshTokenRevocationService::class);
        $carrier->expects(self::once())->method('revoke');

        $store = $this->createMock(TokenSessionRepository::class);
        $store->expects(self::never())->method('revokeSession');
        $store->expects(self::never())->method('revokeAllForUser');
        $store->expects(self::never())->method('isSessionActive');
        $store->expects(self::never())->method('findGrant');

        $this->manager($store, $carrier)->logout(self::USER_ID, 'ip-slot', 'explicit', $token);
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{0:string,1:array<string,mixed>}> $auditSink
     */
    private function manager(
        ?TokenSessionRepository $store,
        ?RefreshTokenRevocationService $carrier = null,
        array &$auditSink = [],
    ): AuthManager {
        [$repo, $jwt, $audit, $logger, $rateLimiter] = $this->deps();
        $audit
            ->method('logFailedAuth')
            ->willReturnCallback(static function (string $reason, array $context) use (&$auditSink): void {
                $auditSink[] = [$reason, $context];
            });
        $audit
            ->method('logLogout')
            ->willReturnCallback(static function (string $userId, string $sessionId) use (&$auditSink): void {
                $auditSink[] = ['logout:' . $userId . ':' . $sessionId, []];
            });

        return new AuthManager(
            $repo,
            $jwt,
            $audit,
            $logger,
            $rateLimiter,
            null,
            null,
            null,
            true,
            $carrier,
            null,
            $store,
        );
    }

    /**
     * @return array{0: UserRepository&MockObject, 1: JwtHandler,
     *               2: AuditLogger&MockObject, 3: StructuredLogger&MockObject,
     *               4: RateLimiter}
     */
    private function deps(): array
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn(['id' => self::USER_ID, 'username' => 'nina', 'password_hash' => 'hash']);
        $jwt = new JwtHandler(self::SECRET);
        return [
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            $this->createMock(StructuredLogger::class),
            new RateLimiter(windowSeconds: 900, maxAttempts: 5, cap: 1000),
        ];
    }

    /**
     * The family claim of a validated lineage, failing loudly when a fixture
     * token did not validate at all (null lineage is a broken test, never a
     * silent null family). Typed narrowing for the analyzer: the manager and
     * middleware read the same claim through these seams.
     *
     * @param array{claims: JwtClaims, session_id: ?string}|null $lineage
     */
    private static function sessionOf(?array $lineage): ?string
    {
        if ($lineage === null) {
            self::fail('twin fixture token must validate through the lineage seam');
        }

        return $lineage['session_id'];
    }

    /**
     * The jti of a validated lineage, fail-loud on absence — premise pins
     * read back the EXACT minted token's id instead of trusting a fixture.
     *
     * @param array{claims: JwtClaims, session_id: ?string}|null $lineage
     */
    private static function jtiOf(?array $lineage): string
    {
        if ($lineage === null) {
            self::fail('twin fixture token must validate through the lineage seam');
        }

        $jti = $lineage['claims']->jti;
        if (!is_string($jti)) {
            self::fail('twin fixture refresh token must carry a jti');
        }

        return $jti;
    }

    /** A pre-055 (no sid claim) refresh token: minted by an unwired manager. */
    private function legacyRefreshToken(): string
    {
        return $this->manager(null)
            ->refresh($this->handcraftedRefreshToken(null))['refresh_token'];
    }

    /**
     * A refresh token of family $family, minted through a genesis store that
     * registers its jti — the presented artifact for adjudication legs.
     */
    private function familyRefreshToken(string $family): string
    {
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('createSession')->willReturn($family);
        return $this->manager($store)
            ->refresh($this->handcraftedRefreshToken(null))['refresh_token'];
    }

    /**
     * @return array{0:string,1:string} [access token, refresh token] of $family
     *     (null family mints through an UNWIRED manager — the legacy pair).
     */
    private function familyAccessPair(?string $family): array
    {
        if ($family === null) {
            $response = $this->manager(null)->refresh($this->handcraftedRefreshToken(null));
            return [$response['access_token'], $response['refresh_token']];
        }

        $mintStore = $this->createMock(TokenSessionRepository::class);
        $mintStore->method('createSession')->willReturn($family);
        $response = $this->manager($mintStore)->refresh($this->handcraftedRefreshToken(null));
        return [$response['access_token'], $response['refresh_token']];
    }

    /**
     * Hand-craft an HS256 refresh JWT (optionally with a sid claim) signed
     * with the test secret — the shape NO hub builder produces (jti-less,
     * or family-naming without a grant), which is exactly why the
     * adjudicator needs arms for it.
     */
    private function handcraftedRefreshToken(?string $family, bool $withJti = true): string
    {
        $now = time();
        $payload = [
            'iss' => 'phlix-hub',
            'aud' => 'hub',
            'sub' => self::USER_ID,
            'iat' => $now,
            'exp' => $now + 600,
            'type' => 'refresh',
        ];
        if ($withJti) {
            $payload['jti'] = 'jti-' . bin2hex(random_bytes(8));
        }
        if ($family !== null) {
            $payload['sid'] = $family;
        }

        $encode = static fn (string $part): string => rtrim(strtr(base64_encode($part), '+/', '-_'), '=');
        $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $header = $encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], $flags));
        $signingInput = $header
            . '.' . $encode(json_encode($payload, $flags));

        return $signingInput . '.' . $encode(hash_hmac('sha256', $signingInput, self::SECRET, true));
    }

    private function middleware(?TokenSessionRepository $store): AuthMiddleware
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->willReturn(true);
        return new AuthMiddleware(new JwtHandler(self::SECRET), $repo, $store);
    }

    private function apiRequest(string $bearerToken): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->bearerToken = $bearerToken;
        return $request;
    }
}
