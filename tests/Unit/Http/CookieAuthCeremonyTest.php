<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\CookiePolicyTestShapes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use support\Context;

/**
 * CP-013 (COOKIE-DESIGN §7): the ceremony layer — end-to-end auth flows that
 * COMBINE the CP-011 issuance seam and the CP-012 Origin guard in one wire
 * story, the layer §7 names but the per-seam suites cannot express: the
 * issuance-side pair (CookieDualEmitTest, CookieOriginAuthRoutesTest) pins its
 * seam with MOCKED string tokens, and the read-side pair
 * (CookieAuthFallbackTest, CsrfOriginGuardMiddlewareTest) mints REAL JWTs via a
 * same-secret JwtHandler but injects each artifact directly into the single
 * seam its suite owns — neither shape crosses the issuance→read CONTINUITY
 * between the two layers. Here the access token the AuthController mints is a
 * REAL JWT under the same secret the AuthMiddleware validates, so every leg
 * proves the two layers actually speak the same artifact: login → cookie
 * issued → hostile Origin write → 403 → friendly Origin → pass →
 * cookie-sourced refresh rotates the custody pair → rotated access
 * authenticates → logout clears the jar → the emptied jar is refused.
 * The ceremony additionally owns the
 * cross-layer laws that live only at the seam BETWEEN suites:
 *
 *  - token continuity (the issued value authenticates — a per-arm string-mock
 *    pin could never catch issuance and the read seam disagreeing);
 *  - the §2.5 Sec-Fetch belt ON THE MIDDLEWARE ROUTE (CP-012 pinned the belt
 *    in the pure law and the controller arms; the AuthMiddleware seam had no
 *    belt leg — this suite owns it);
 *  - bearer/device immunity through the WHOLE lifecycle (login → guarded
 *    write → refresh → logout all hostile-Origin, all ungated);
 *  - the master-switch rollback law end-to-end (issuance OFF must stop every
 *    Set-Cookie, and must NEVER leave a still-authenticated jar UNGUARDED nor
 *    stop the read path — CookiePolicy's class docblock carried that pair of
 *    laws testlessly until here);
 *  - the custody cross-checks between the two axes: default-port normalization
 *    belongs to ISSUANCE only (Origin `:443` vs bare Host earns the cookie and
 *    then refuses the refresh — deliberate, disclosed divergence), and the
 *    device marker classifies issuance only (a cookie-lineage write flying a
 *    marker behind a hostile Origin is still 403 — a future "honour the marker
 *    in the guard too" refactor is convicted by name).
 *
 * Zero wire change: this suite tests what 506110fa + 6a3d4b76 shipped.
 */
final class CookieAuthCeremonyTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';
    private const HOST = 'hub.example.test';
    private const USER_ID = 'u-ceremony';
    /** The server's ratified rejection bytes, mirrored verbatim. */
    private const REJECT_FRAME = '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}';

    private JwtHandler $jwt;

    protected function setUp(): void
    {
        if (class_exists(Context::class)) {
            Context::destroy();
        }
        AuthMiddleware::resetCache();
        putenv(CookiePolicy::INSECURE_ENV);

        $this->jwt = new JwtHandler(self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(string $access, string $refresh): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => self::USER_ID, 'username' => 'alice'],
            'claims' => ['sub' => self::USER_ID],
        ];
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

    private function middleware(): AuthMiddleware
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->willReturn(true);

        return new AuthMiddleware($this->jwt, $repo);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function request(string $method, string $path, array $headers = [], array $body = []): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->headers = $headers;
        $request->body = $body;
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
            'Origin' => 'https://' . self::HOST,
            'Host' => self::HOST,
            'X-Forwarded-Proto' => 'https',
        ];
    }

    /** @return array<string, string> */
    private function evilOrigin(): array
    {
        return ['Origin' => 'https://evil.example', 'Host' => self::HOST];
    }

    /** @return array<string, string> */
    private function sessionJar(string $accessToken): array
    {
        return ['Cookie' => CookiePolicy::SESSION_COOKIE . '=' . $accessToken];
    }

    /** @return array<string, string> */
    private function fullJar(string $accessToken, string $refreshToken): array
    {
        return ['Cookie' => CookiePolicy::SESSION_COOKIE . '=' . $accessToken
            . '; ' . CookiePolicy::REFRESH_COOKIE . '=' . $refreshToken];
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

    private function assertBodyCarries(string $body, string $needle): void
    {
        self::assertStringContainsString($needle, $body);
    }

    public function testBrowserCeremonyIssueGuardRotateClearKeepsTokenContinuity(): void
    {
        // THE §7 scenario centerpiece: every token crossing a seam here is
        // the real minted artifact, so the suite fails if issuance and the
        // read path ever stop speaking the same JWT.
        $access1 = $this->jwt->createAccessToken(self::USER_ID);
        $auth = $this->authMgr();
        $auth->method('login')->willReturn($this->payload($access1, 'rl-1'));

        $login = $this->controller($auth)->loginJson(
            $this->request(
                'POST',
                '/api/v1/auth/login',
                $this->browserHeaders(),
                ['username' => 'a', 'password' => 'b'],
            ),
        );
        self::assertSame(200, $login->statusCode);
        self::assertCount(2, $login->cookies);
        self::assertSame(
            $access1,
            $this->cookieByName($login->cookies, CookiePolicy::SESSION_COOKIE)['value'],
            'the HttpOnly session cookie must carry exactly the minted access JWT',
        );

        // (a) the issued cookie authenticates a same-site state-changing write.
        $write = $this->request('POST', '/api/v1/servers', $this->sessionJar($access1) + $this->browserHeaders());
        self::assertNull($this->middleware()($write));
        self::assertSame(self::USER_ID, $write->userId);

        // (b) the SAME jar behind a hostile Origin is refused BEFORE hydration.
        $evilWrite = $this->request('POST', '/api/v1/servers', $this->sessionJar($access1) + $this->evilOrigin());
        $refusal = $this->middleware()($evilWrite);
        self::assertNotNull($refusal);
        self::assertSame(403, $refusal->statusCode);
        self::assertSame(self::REJECT_FRAME, $refusal->body);
        self::assertNull($evilWrite->userId);

        // (c) refresh from the issued custody pair rotates the pair; the
        // controller receives exactly the cookie the login response set.
        $auth2 = $this->authMgr();
        $auth2->expects(self::once())->method('refresh')->with('rl-1')->willReturn($this->payload($access1, 'rl-2'));
        $refresh = $this->controller($auth2)->refreshJson($this->request(
            'POST',
            '/api/v1/auth/refresh',
            $this->browserHeaders() + ['Cookie' => CookiePolicy::REFRESH_COOKIE . '=rl-1'],
        ));
        self::assertSame(200, $refresh->statusCode);
        self::assertSame('rl-2', $this->cookieByName($refresh->cookies, CookiePolicy::REFRESH_COOKIE)['value']);

        // (d) the rotated jar authenticates the next guarded write.
        $write2 = $this->request('POST', '/api/v1/servers', $this->fullJar($access1, 'rl-2') + $this->browserHeaders());
        self::assertNull($this->middleware()($write2));
        self::assertSame(self::USER_ID, $write2->userId);

        // (e) logout reads the ROTATED refresh cookie and clears the jar.
        $auth3 = $this->authMgr();
        $auth3->expects(self::once())->method('logout')->with(
            '',
            '203.0.113.7',
            self::anything(),
            'rl-2',
        );
        $logout = $this->controller($auth3)->logoutJson($this->request(
            'POST',
            '/api/v1/auth/logout',
            $this->fullJar($access1, 'rl-2') + $this->browserHeaders(),
        ));
        self::assertSame(204, $logout->statusCode);
        self::assertCount(2, $logout->cookies);
        foreach ($logout->cookies as $cookie) {
            self::assertSame('', $cookie['value']);
            self::assertSame(0, $cookie['max_age']);
        }
        $wire = $logout->toWorkermanResponse();
        /** @var array<string, mixed> $wireHeaders */
        $wireHeaders = $wire->getHeaders();
        /** @var list<string> $setCookie */
        $setCookie = $wireHeaders['Set-Cookie'] ?? [];
        self::assertCount(2, $setCookie);
        self::assertSame(2, count(array_filter(
            $setCookie,
            static fn (string $line): bool => str_contains($line, 'Max-Age=0'),
        )));

        // (f) the emptied jar (what Max-Age=0 leaves the browser with) is a
        // fresh unauthenticated request: auth.required, not the guard frame.
        $after = $this->middleware()($this->request(
            'POST',
            '/api/v1/servers',
            ['Cookie' => CookiePolicy::SESSION_COOKIE . '=; ' . CookiePolicy::REFRESH_COOKIE . '=;']
                + $this->browserHeaders(),
        ));
        self::assertNotNull($after);
        self::assertSame(401, $after->statusCode);
        self::assertBodyCarries($after->body, 'auth.required');
    }

    public function testSecFetchBeltRefusesCookieWriteOnTheMiddlewareRouteBehindForgedSameHostOrigin(): void
    {
        // §2.5 design-ADD belt, middleware-route leg (CP-012 pinned the belt
        // in the pure law and the controller arms only): an honest UA naming
        // the right Origin while reporting cross-site is refused on the
        // guarded route exactly like the law refuses it in isolation.
        $access = $this->jwt->createAccessToken(self::USER_ID);
        $belted = $this->request('POST', '/api/v1/servers', $this->sessionJar($access) + $this->browserHeaders() + [
            'Sec-Fetch-Site' => 'cross-site',
        ]);
        $refusal = $this->middleware()($belted);
        self::assertNotNull($refusal);
        self::assertSame(403, $refusal->statusCode);
        self::assertSame(self::REJECT_FRAME, $refusal->body);
        self::assertNull($belted->userId);

        // Control: same ceremony state, honest belt value → the write proceeds.
        $honest = $this->request('POST', '/api/v1/servers', $this->sessionJar($access) + $this->browserHeaders() + [
            'Sec-Fetch-Site' => 'same-origin',
        ]);
        self::assertNull($this->middleware()($honest));
        self::assertSame(self::USER_ID, $honest->userId);
    }

    public function testDeviceBearerCeremonyStaysGuardImmuneThroughTheWholeLifecycle(): void
    {
        // The CP-012 non-regression law, ceremony-scale: a device client that
        // EARNED its token through a same-site-shaped login stays bearer
        // lineage for every later state change — foreign Origin, missing
        // Origin, whatever the guard reads, it never applies.
        $access = $this->jwt->createAccessToken(self::USER_ID);
        $deviceHeaders = $this->browserHeaders() + ['X-Phlix-Device-Type' => 'rok'];

        $auth = $this->authMgr();
        $auth->method('login')->willReturn($this->payload($access, 'rl-device'));
        $login = $this->controller($auth)->loginJson(
            $this->request('POST', '/api/v1/auth/login', $deviceHeaders, ['username' => 'a', 'password' => 'b']),
        );
        self::assertSame(200, $login->statusCode);
        self::assertSame([], $login->cookies, 'device class never earns a jar, same-site Origin or not');
        self::assertBodyCarries($login->body, $access);

        // State-changing write: valid bearer, hostile Origin → untouched.
        $write = $this->request('POST', '/api/v1/servers', $this->evilOrigin());
        $write->bearerToken = $access;
        self::assertNull($this->middleware()($write));
        self::assertSame(self::USER_ID, $write->userId);

        // Body-token refresh with a hostile Origin: never gated, never cookies.
        $auth2 = $this->authMgr();
        $auth2->expects(self::once())->method('refresh')->with('rl-device')
            ->willReturn($this->payload($access, 'rl-device-2'));
        $refresh = $this->controller($auth2)->refreshJson(
            $this->request('POST', '/api/v1/auth/refresh', $this->evilOrigin(), ['refresh_token' => 'rl-device']),
        );
        self::assertSame(200, $refresh->statusCode);
        self::assertSame([], $refresh->cookies);

        // Body-token logout from the hostile Origin: revocation runs (the
        // rejection is not a logout, and this is not a rejection).
        $auth3 = $this->authMgr();
        $auth3->expects(self::once())->method('logout')->with('', '203.0.113.7', self::anything(), 'rl-device-2');
        $logout = $this->controller($auth3)->logoutJson(
            $this->request('POST', '/api/v1/auth/logout', $this->evilOrigin(), ['refresh_token' => 'rl-device-2']),
        );
        self::assertSame(204, $logout->statusCode);
    }

    public function testMasterSwitchOffCeremonyStopsIssuanceButKeepsReadPathAndGuardAlive(): void
    {
        // §7 rollback law at ceremony scale (first test to own the pair the
        // CookiePolicy class docblock has carried testlessly since CP-011):
        // switch OFF ⇒ no Set-Cookie anywhere, while the still-live jar keeps
        // authenticating AND keeps meeting the Origin guard — flipping
        // issuance off must never leave an authenticated jar UNGUARDED.
        $off = CookiePolicyTestShapes::with(enabled: false);
        $legacyAccess = $this->jwt->createAccessToken(self::USER_ID);

        $auth = $this->authMgr();
        $auth->method('login')->willReturn($this->payload($legacyAccess, 'rl-legacy'));
        $login = $this->controller($auth, $off)->loginJson(
            $this->request(
                'POST',
                '/api/v1/auth/login',
                $this->browserHeaders(),
                ['username' => 'a', 'password' => 'b'],
            ),
        );
        self::assertSame(200, $login->statusCode);
        self::assertSame([], $login->cookies);

        // Pre-rollback jar still authenticates (the read path validates
        // presented artifacts, never re-adjudicates the toggle).
        $write = $this->request('POST', '/api/v1/servers', $this->sessionJar($legacyAccess) + $this->browserHeaders());
        self::assertNull($this->middleware()($write));
        self::assertSame(self::USER_ID, $write->userId);

        // Same jar, hostile Origin → still 403: issuance OFF is not guard OFF.
        $evilWrite = $this->request('POST', '/api/v1/servers', $this->sessionJar($legacyAccess) + $this->evilOrigin());
        $refusal = $this->middleware()($evilWrite);
        self::assertNotNull($refusal);
        self::assertSame(403, $refusal->statusCode);
        self::assertSame(self::REJECT_FRAME, $refusal->body);

        // Cookie-sourced refresh under OFF rotates the BODY and mints zero
        // cookies (classification gate + the attach choke-point belt).
        $auth2 = $this->authMgr();
        $auth2->expects(self::once())->method('refresh')->with('rl-legacy')
            ->willReturn($this->payload($legacyAccess, 'rl-legacy-2'));
        $refresh = $this->controller($auth2, $off)->refreshJson($this->request(
            'POST',
            '/api/v1/auth/refresh',
            $this->browserHeaders() + ['Cookie' => CookiePolicy::REFRESH_COOKIE . '=rl-legacy'],
        ));
        self::assertSame(200, $refresh->statusCode);
        self::assertSame([], $refresh->cookies);
        self::assertBodyCarries($refresh->body, 'rl-legacy-2');
    }

    public function testIssuanceNormalizesTheDefaultPortTheGuardRefusesItByDesign(): void
    {
        // The custody cross-check between the two CookiePolicy laws, on the
        // WIRE: originIsSameSite() normalizes the default port (issuance axis,
        // §2.1), stateChangingRequestOriginAllowed() does not (request axis,
        // §2.5 — "refuses anything not literally named"). A client presenting
        // `Origin: https://HOST:443` against a bare-Host request therefore
        // EARNS the cookie and is then REFUSED the guarded rotation — not a
        // bug but the ratified divergence, disclosed in the method docblock;
        // real browsers never send the default port in Origin, so the shape
        // is theoretical. A future collapse of the two axes into one helper
        // is convicted here by name.
        $auth = $this->authMgr();
        $auth->method('login')->willReturn($this->payload($this->jwt->createAccessToken(self::USER_ID), 'rl-p'));
        $ported = ['Origin' => 'https://' . self::HOST . ':443', 'Host' => self::HOST, 'X-Forwarded-Proto' => 'https'];

        $login = $this->controller($auth)->loginJson(
            $this->request('POST', '/api/v1/auth/login', $ported, ['username' => 'a', 'password' => 'b']),
        );
        self::assertCount(2, $login->cookies, 'issuance normalizes :443 to the default port — browser-class');

        $auth2 = $this->authMgr();
        $auth2->expects(self::never())->method('refresh');
        $refusal = $this->controller($auth2)->refreshJson($this->request(
            'POST',
            '/api/v1/auth/refresh',
            $ported + ['Cookie' => CookiePolicy::REFRESH_COOKIE . '=rl-p'],
        ));
        self::assertSame(403, $refusal->statusCode);
        self::assertSame(self::REJECT_FRAME, $refusal->body);

        // Control: name the port on BOTH sides and the same rotation passes —
        // the law is literal-naming precision, not an Origin-value ban.
        $auth3 = $this->authMgr();
        $auth3->expects(self::once())->method('refresh')->with('rl-p')
            ->willReturn($this->payload('access-x', 'rl-p-2'));
        $ok = $this->controller($auth3)->refreshJson($this->request(
            'POST',
            '/api/v1/auth/refresh',
            [
                'Origin' => 'https://' . self::HOST . ':443',
                'Host' => self::HOST . ':443',
                'X-Forwarded-Proto' => 'https',
            ] + ['Cookie' => CookiePolicy::REFRESH_COOKIE . '=rl-p'],
        ));
        self::assertSame(200, $ok->statusCode);
    }

    public function testDeviceMarkerIsNotAGuardExemptionOnCookieLineage(): void
    {
        // The second custody cross-check: `X-Phlix-Device-Type` classifies
        // ISSUANCE custody only. A request authenticated by the session
        // cookie (no bearer) flying a marker behind a hostile Origin is the
        // lineage the guard owns — marker or not, it is refused. (If the
        // guard ever learned to honour the marker, device-class theft of a
        // browser jar would become the guard's blind spot.)
        $access = $this->jwt->createAccessToken(self::USER_ID);
        $marked = $this->request('POST', '/api/v1/servers', $this->sessionJar($access) + $this->evilOrigin() + [
            'X-Phlix-Device-Type' => 'windows',
        ]);
        $refusal = $this->middleware()($marked);
        self::assertNotNull($refusal);
        self::assertSame(403, $refusal->statusCode);
        self::assertSame(self::REJECT_FRAME, $refusal->body);
        self::assertNull($marked->userId);
    }
}
