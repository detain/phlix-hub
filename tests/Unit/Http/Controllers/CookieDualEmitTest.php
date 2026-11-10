<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Tests\Support\CookiePolicyTestShapes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CP-011 (COOKIE-DESIGN §2.2/§4.2, hub twin of the server's
 * CookieDualEmitTest): the JSON login/register/refresh surfaces DUAL-EMIT
 * for browser-class requests on a §2.4-eligible scheme — byte-ident body
 * envelope AND the HttpOnly `phlix_hub_token`/`phlix_hub_refresh` pair —
 * while device-class requests stay bearer-only with NO Set-Cookie ever.
 * Logout clears both names on every arm; the refresh token may arrive in
 * the body (precedence, legacy wire) or the cookie (CP-011 SPA flow).
 *
 * The central non-regression law lives here: the browser arm and the device
 * arm of the SAME AuthManager payload produce byte-identical JSON bodies;
 * the cookie ride is strictly transport-level (headers only).
 */
final class CookieDualEmitTest extends TestCase
{
    /** @var array<string, mixed> The AuthManager success payload under test. */
    private const TOKENS = [
        'access_token' => 'access-tok',
        'refresh_token' => 'refresh-tok',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user' => ['id' => 'u-1', 'username' => 'alice'],
        'claims' => ['sub' => 'u-1'],
    ];

    protected function setUp(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
    }

    protected function tearDown(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
        parent::tearDown();
    }

    private function authMgr(): AuthManager&MockObject
    {
        $auth = $this->createMock(AuthManager::class);
        $auth->method('accessTtl')->willReturn(3600);
        $auth->method('refreshTtl')->willReturn(604800);

        return $auth;
    }

    private function controller(AuthManager $auth, ?CookiePolicy $policy = null): AuthController
    {
        return new AuthController($auth, $policy ?? CookiePolicyTestShapes::on());
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

    /**
     * The same-site browser over the hub's TLS front (§2.1 + §2.4 both hold).
     *
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'Origin' => 'https://hub.example.test',
            'Host' => 'hub.example.test',
            'X-Forwarded-Proto' => 'https',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function queuedCookies(Response $response): array
    {
        return $response->cookies;
    }

    /**
     * One login arm: TOKENS-backed mock + given headers (+ optional policy).
     *
     * @param array<string, string> $headers
     */
    private function loginArm(array $headers, ?CookiePolicy $policy = null): Response
    {
        $auth = $this->authMgr();
        $auth->method('login')->willReturn(self::TOKENS);

        return $this->controller($auth, $policy)
            ->loginJson($this->request('/api/v1/auth/login', ['username' => 'a', 'password' => 'b'], $headers));
    }

    /**
     * @param list<array<string, mixed>> $cookies
     * @return array<string, mixed>
     */
    private function cookieByName(array $cookies, string $name): array
    {
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                return $cookie;
            }
        }
        self::fail("expected a queued cookie named {$name}; got: " . json_encode(array_column($cookies, 'name')));
    }

    public function testBrowserJsonLoginDualEmitsBodyAndCookiePair(): void
    {
        $response = $this->loginArm($this->browserHeaders());

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::expectedBody(self::TOKENS), $response->body);

        $cookies = $this->queuedCookies($response);
        self::assertCount(2, $cookies);

        $session = $this->cookieByName($cookies, CookiePolicy::SESSION_COOKIE);
        self::assertSame('access-tok', $session['value']);
        self::assertSame(3600, $session['max_age']);

        $refresh = $this->cookieByName($cookies, CookiePolicy::REFRESH_COOKIE);
        self::assertSame('refresh-tok', $refresh['value']);
        self::assertSame(604800, $refresh['max_age']);

        foreach ($cookies as $cookie) {
            self::assertSame('/', $cookie['path']);
            self::assertTrue($cookie['http_only']);
            self::assertTrue($cookie['secure']);
            self::assertSame('Lax', $cookie['same_site']);
        }
    }

    public function testDeviceLoginEmitsZeroCookiesAndByteIdenticalBody(): void
    {
        // The non-regression centerpiece: same payload, device marker +
        // same-site Origin (marker wins) — JSON byte-ident to the browser
        // arm above, NOT ONE Set-Cookie queued.
        $headers = $this->browserHeaders();
        $headers['X-Phlix-Device-Type'] = 'rok';

        $response = $this->loginArm($headers);

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::expectedBody(self::TOKENS), $response->body);
        self::assertSame([], $this->queuedCookies($response));
    }

    public function testNoSignalNativeLoginStaysBearerOnlyByteIdentical(): void
    {
        // curl/legacy natives send no Origin, no Sec-Fetch, no marker.
        $response = $this->loginArm(['Host' => 'hub.example.test']);

        self::assertSame(self::expectedBody(self::TOKENS), $response->body);
        self::assertSame([], $this->queuedCookies($response));
    }

    public function testForeignOriginLoginIsDeviceClass(): void
    {
        $headers = $this->browserHeaders();
        $headers['Origin'] = 'https://evil.example';

        $response = $this->loginArm($headers);

        self::assertSame([], $this->queuedCookies($response));
    }

    public function testPlainHttpNonLocalhostLoginCarriesNoCookies(): void
    {
        // §2.4: browser-class but scheme-ineligible → today's posture
        // (body tokens, localStorage mode), never a silently-issued cookie.
        $headers = $this->browserHeaders();
        $headers['X-Forwarded-Proto'] = 'http';
        $headers['Origin'] = 'http://hub.example.test';

        $response = $this->loginArm($headers);

        self::assertSame(self::expectedBody(self::TOKENS), $response->body);
        self::assertSame([], $this->queuedCookies($response));
    }

    public function testInsecureOverrideBypassesSchemeGateAndDropsSecure(): void
    {
        putenv(CookiePolicy::INSECURE_ENV . '=1');

        $headers = $this->browserHeaders();
        $headers['X-Forwarded-Proto'] = 'http';
        $headers['Origin'] = 'http://hub.example.test';

        $response = $this->loginArm($headers);

        $cookies = $this->queuedCookies($response);
        self::assertCount(2, $cookies);
        foreach ($cookies as $cookie) {
            self::assertFalse($cookie['secure'], 'insecure opt-in must drop Secure, never fake it');
        }
    }

    public function testMasterSwitchOffStopsEmissionOnEveryBrowserArm(): void
    {
        $auth = $this->authMgr();
        $auth->method('register')->willReturn(self::TOKENS);
        $auth->method('refresh')->willReturn(self::TOKENS);

        $off = CookiePolicyTestShapes::with(enabled: false);

        $browser = $this->browserHeaders();
        self::assertSame([], $this->queuedCookies($this->loginArm($browser, $off)));
        self::assertSame([], $this->queuedCookies($this->controller($auth, $off)->signupJson(
            $this->request('/api/v1/auth/register', ['username' => 'a', 'email' => 'e', 'password' => 'b'], $browser),
        )));
        self::assertSame([], $this->queuedCookies($this->controller($auth, $off)->refreshJson(
            $this->request('/api/v1/auth/refresh', ['refresh_token' => 'r'], $browser),
        )));
    }

    public function testBrowserRegisterDualEmitsAt201(): void
    {
        $auth = $this->authMgr();
        $auth->method('register')->willReturn(self::TOKENS);

        $response = $this->controller($auth)->signupJson($this->request(
            '/api/v1/auth/register',
            ['username' => 'a', 'email' => 'a@example.test', 'password' => 'b'],
            $this->browserHeaders(),
        ));

        self::assertSame(201, $response->statusCode);
        self::assertSame(self::expectedBody(self::TOKENS), $response->body);
        self::assertCount(2, $this->queuedCookies($response));
    }

    public function testRefreshBodyTokenTakesPrecedenceOverCookie(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('refresh')->with('body-token-wins')->willReturn(self::TOKENS);

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-token-loses';

        $this->controller($auth)->refreshJson($this->request(
            '/api/v1/auth/refresh',
            ['refresh_token' => 'body-token-wins'],
            $headers,
        ));
    }

    public function testRefreshFallsBackToCookieAndRotatesThePair(): void
    {
        $rotated = self::TOKENS;
        $rotated['access_token'] = 'access-tok-2';
        $rotated['refresh_token'] = 'refresh-tok-2';

        $auth = $this->authMgr();
        $auth->expects(self::once())->method('refresh')->with('cookie-token-only')->willReturn($rotated);

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-token-only';

        $response = $this->controller($auth)->refreshJson($this->request('/api/v1/auth/refresh', [], $headers));

        self::assertSame(self::expectedBody($rotated), $response->body);
        $cookies = $this->queuedCookies($response);
        self::assertSame('access-tok-2', $this->cookieByName($cookies, CookiePolicy::SESSION_COOKIE)['value']);
        self::assertSame('refresh-tok-2', $this->cookieByName($cookies, CookiePolicy::REFRESH_COOKIE)['value']);
    }

    public function testRefreshDeviceArmRotatesBodyOnly(): void
    {
        $auth = $this->authMgr();
        $auth->method('refresh')->willReturn(self::TOKENS);

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=r';
        $headers['X-Phlix-Device-Type'] = 'windows';

        $response = $this->controller($auth)->refreshJson(
            $this->request('/api/v1/auth/refresh', ['refresh_token' => 'r'], $headers),
        );

        self::assertSame(self::expectedBody(self::TOKENS), $response->body);
        self::assertSame([], $this->queuedCookies($response));
    }

    public function testRefreshWithoutAnyTokenStill400(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('refresh');

        $response = $this->controller($auth)
            ->refreshJson($this->request('/api/v1/auth/refresh', [], ['Host' => 'hub.example.test']));

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('auth.missing_credentials', $response->body);
        self::assertSame([], $this->queuedCookies($response));
    }

    public function testLogoutAlwaysClearsBothNamesEvenForDevices(): void
    {
        // Clearing is revocation hygiene, not issuance: no master-switch,
        // no classification gate on it (docblock carries the law).
        $auth = $this->authMgr();
        $auth->method('logout')->willReturnCallback(static function (): void {
        });

        $response = $this->controller($auth)->logoutJson(
            $this->request('/api/v1/auth/logout', ['refresh_token' => 'r'], ['X-Phlix-Device-Type' => 'rok']),
        );

        self::assertSame(204, $response->statusCode);
        self::assertSame('', $response->body);

        $cookies = $this->queuedCookies($response);
        self::assertCount(2, $cookies);
        $session = $this->cookieByName($cookies, CookiePolicy::SESSION_COOKIE);
        self::assertSame('', $session['value']);
        self::assertSame(0, $session['max_age']);
        $refresh = $this->cookieByName($cookies, CookiePolicy::REFRESH_COOKIE);
        self::assertSame('', $refresh['value']);
        self::assertSame(0, $refresh['max_age']);
    }

    public function testLogoutMasterSwitchOffStillClearsTheJar(): void
    {
        $auth = $this->authMgr();

        $response = $this->controller($auth, CookiePolicyTestShapes::with(enabled: false))
            ->logoutJson($this->request('/api/v1/auth/logout', ['refresh_token' => 'r'], $this->browserHeaders()));

        self::assertCount(2, $this->queuedCookies($response));
    }

    public function testLogoutRevokesLineageFromTheRefreshCookie(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::once())->method('logout')->with(
            '',
            self::anything(),
            self::anything(),
            'cookie-refresh-token',
        );

        $headers = $this->browserHeaders();
        $headers['Cookie'] = 'phlix_hub_refresh=cookie-refresh-token';

        $response = $this->controller($auth)->logoutJson($this->request('/api/v1/auth/logout', [], $headers));
        self::assertSame(204, $response->statusCode);
    }

    public function testAnonymousLogoutWithoutAnyCredentialOnlyClears(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('logout');

        $response = $this->controller($auth)
            ->logoutJson($this->request('/api/v1/auth/logout', [], ['Host' => 'hub.example.test']));

        self::assertSame(204, $response->statusCode);
        self::assertCount(2, $this->queuedCookies($response));
    }

    public function testWireComposeRidesBothSetCookieHeaders(): void
    {
        $response = $this->loginArm($this->browserHeaders());

        $wire = $response->toWorkermanResponse();
        /** @var array<string, mixed> $headers */
        $headers = $wire->getHeaders();
        /** @var list<string> $setCookie */
        $setCookie = $headers['Set-Cookie'] ?? [];

        self::assertCount(2, $setCookie);
        $joined = implode("\n", $setCookie);
        self::assertStringContainsString('phlix_hub_token=access-tok', $joined);
        self::assertStringContainsString('phlix_hub_refresh=refresh-tok', $joined);
        self::assertStringContainsString('HttpOnly', $joined);
        self::assertStringContainsString('Secure', $joined);
        self::assertStringContainsString('SameSite=Lax', $joined);
        self::assertStringContainsString('Max-Age=3600', $joined);
        self::assertStringContainsString('Max-Age=604800', $joined);
    }

    /** @param array<string, mixed> $payload */
    private static function expectedBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
