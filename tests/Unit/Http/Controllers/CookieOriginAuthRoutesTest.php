<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Tests\Support\CookiePolicyTestShapes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CP-012 (COOKIE-DESIGN §2.5/§4.2): the Origin guard on the two auth routes
 * that run WITHOUT {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} yet
 * accept cookie lineage since CP-011 — POST /api/v1/auth/refresh and POST
 * /api/v1/auth/logout. Where the token arrives in the BODY the request is
 * body-lineage and the guard never touches it (every legacy native client
 * stays byte-ident, headerless included); where the cookie is the SOLE
 * credential source a cross-site / unnamed-origin / belt-cross-site request
 * is refused BEFORE the rotation or revocation side effect runs — a rejected
 * refresh must not burn a jti, and a rejected logout must not even clear
 * the jar (the rejection is not a logout).
 *
 * Login/signup carry the §5 design exemption: issuance needs no CSRF
 * protection (nothing fixates — the tokens ride the fresh response), so the
 * guard's absence on those routes is pinned, not implied.
 */
final class CookieOriginAuthRoutesTest extends TestCase
{
    /** @var array<string, mixed> */
    private const TOKENS = [
        'access_token' => 'access-tok',
        'refresh_token' => 'refresh-tok',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user' => ['id' => 'u-1', 'username' => 'alice'],
        'claims' => ['sub' => 'u-1'],
    ];

    private const HOST = 'hub.example.test';
    private const REJECT_FRAME = '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}';

    protected function setUp(): void
    {
        putenv(\Phlix\Hub\Hub\CookiePolicy::INSECURE_ENV);
    }

    protected function tearDown(): void
    {
        putenv(\Phlix\Hub\Hub\CookiePolicy::INSECURE_ENV);
        parent::tearDown();
    }

    private function authMgr(): AuthManager&MockObject
    {
        $auth = $this->createMock(AuthManager::class);
        $auth->method('accessTtl')->willReturn(3600);
        $auth->method('refreshTtl')->willReturn(604800);

        return $auth;
    }

    private function controller(AuthManager $auth): AuthController
    {
        return new AuthController($auth, CookiePolicyTestShapes::on());
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(string $path, array $body = [], array $headers = []): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = $path;
        $request->body = $body;
        $request->headers = $headers;
        $request->remoteIp = '203.0.113.7';

        return $request;
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return [
            'Origin' => 'https://' . self::HOST,
            'Host' => self::HOST,
            'X-Forwarded-Proto' => 'https',
        ];
    }

    /** @return array<string, string> */
    private function evilHeaders(): array
    {
        return [
            'Origin' => 'https://evil.example',
            'Host' => self::HOST,
            'Cookie' => 'phlix_hub_refresh=cookie-token',
        ];
    }

    /** @param array<string, string> $headers */
    private function cookieRefreshArm(array $headers, AuthManager&MockObject $auth): Response
    {
        return $this->controller($auth)->refreshJson($this->request('/api/v1/auth/refresh', [], $headers));
    }

    public function testCookieSourcedRefreshFromForeignOriginIsRefusedBeforeRotation(): void
    {
        $auth = $this->authMgr();
        // The decisive side-effect law: a REFUSED refresh must not consume
        // or rotate the token — guard first, auth->refresh() never.
        $auth->expects(self::never())->method('refresh');

        $response = $this->cookieRefreshArm($this->evilHeaders(), $auth);

        self::assertSame(403, $response->statusCode);
        self::assertSame(self::REJECT_FRAME, $response->body);
        self::assertSame([], $response->cookies);
    }

    public function testCookieSourcedRefreshWithNoOriginNamedIsRefusedFailClosed(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('refresh');

        $response = $this->cookieRefreshArm([
            'Host' => self::HOST,
            'Cookie' => 'phlix_hub_refresh=cookie-token',
        ], $auth);

        self::assertSame(403, $response->statusCode);
    }

    public function testCookieSourcedRefreshCrossSiteBeltRefusesEvenWithGoodOrigin(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('refresh');

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-token';
        $headers['Sec-Fetch-Site'] = 'cross-site';

        $response = $this->cookieRefreshArm($headers, $auth);

        self::assertSame(403, $response->statusCode);
    }

    public function testCookieSourcedRefreshFromSameOriginRotatesNormally(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('refresh')->with('cookie-token')->willReturn(self::TOKENS);

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-token';

        $response = $this->cookieRefreshArm($headers, $auth);

        self::assertSame(200, $response->statusCode);
        self::assertCount(2, $response->cookies);
    }

    public function testBodySourcedRefreshFromNativeWithoutHeadersIsUntouched(): void
    {
        // Precedence is the exemption: a body token is body lineage, so the
        // legacy native wire (no Origin, no Referer, no belt) keeps its
        // byte-identical 200 — the fail-closed header limb may never fire
        // on it.
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('refresh')->with('body-token')->willReturn(self::TOKENS);

        $response = $this->controller($auth)->refreshJson($this->request(
            '/api/v1/auth/refresh',
            ['refresh_token' => 'body-token'],
            ['Host' => self::HOST],
        ));

        self::assertSame(200, $response->statusCode);
    }

    public function testBodySourcedRefreshWithForeignOriginIsNotGuarded(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('refresh')->with('body-token')->willReturn(self::TOKENS);

        $response = $this->controller($auth)->refreshJson($this->request(
            '/api/v1/auth/refresh',
            ['refresh_token' => 'body-token'],
            $this->evilHeaders(),
        ));

        self::assertSame(200, $response->statusCode);
    }

    public function testRefreshWithoutAnyCredentialKeepsIts400NotTheGuard403(): void
    {
        // No lineage at all → the existing missing_credentials envelope;
        // the guard is a lineage-scoped law and never speaks first.
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('refresh');

        $response = $this->controller($auth)->refreshJson($this->request(
            '/api/v1/auth/refresh',
            [],
            ['Origin' => 'https://evil.example', 'Host' => self::HOST],
        ));

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('auth.missing_credentials', $response->body);
    }

    public function testCookieSourcedLogoutFromForeignOriginCannotRevocationStrike(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('logout');

        $request = $this->request('/api/v1/auth/logout', [], $this->evilHeaders());

        $response = $this->controller($auth)->logoutJson($request);

        self::assertSame(403, $response->statusCode);
        self::assertSame(self::REJECT_FRAME, $response->body);
        // The rejection is NOT a logout: no jar clear rides it.
        self::assertSame([], $response->cookies);
    }

    public function testCookieSourcedLogoutFromSameOriginClearsNormally(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('logout');

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-token';

        $response = $this->controller($auth)->logoutJson($this->request('/api/v1/auth/logout', [], $headers));

        self::assertSame(204, $response->statusCode);
        self::assertCount(2, $response->cookies);
    }

    public function testBodySourcedLogoutWithForeignOriginStillLogsOutAt204(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('logout');

        $response = $this->controller($auth)->logoutJson($this->request(
            '/api/v1/auth/logout',
            ['refresh_token' => 'body-token'],
            $this->evilHeaders() + ['Cookie' => 'phlix_hub_refresh=cookie-token'],
        ));

        self::assertSame(204, $response->statusCode);
    }

    public function testCredentiallessAnonymousLogoutStaysUngatedAndClears(): void
    {
        // Nothing to fixate, nothing revoked: the CP-011 204+clear posture
        // for a caller presenting NO credential at all is unchanged — the
        // guard is scoped to cookie LINEAGE, not cookie-header absence.
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('logout');

        $response = $this->controller($auth)->logoutJson($this->request(
            '/api/v1/auth/logout',
            [],
            ['Origin' => 'https://evil.example', 'Host' => self::HOST],
        ));

        self::assertSame(204, $response->statusCode);
        self::assertCount(2, $response->cookies);
    }

    public function testLoginAndSignupAreIssuanceExemptNeverGuarded(): void
    {
        // §5: login fixation needs no CSRF defence (the pair rides the
        // fresh response; nothing pre-existing attaches). A cross-site
        // login POST answers its normal arm (device-classified, bearer
        // body, zero cookies) — NOT a 403.
        $auth = $this->authMgr();
        $auth->method('login')->willReturn(self::TOKENS);
        $auth->method('register')->willReturn(self::TOKENS);

        $evil = ['Origin' => 'https://evil.example', 'Host' => self::HOST];

        $login = $this->controller($auth)->loginJson(
            $this->request('/api/v1/auth/login', ['username' => 'a', 'password' => 'b'], $evil),
        );
        self::assertNotSame(403, $login->statusCode);
        self::assertSame(200, $login->statusCode);

        $signup = $this->controller($auth)->signupJson(
            $this->request(
                '/api/v1/auth/register',
                ['username' => 'a', 'email' => 'a@e.test', 'password' => 'b'],
                $evil,
            ),
        );
        self::assertNotSame(403, $signup->statusCode);
    }
}
