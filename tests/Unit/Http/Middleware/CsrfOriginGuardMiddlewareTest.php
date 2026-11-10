<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use support\Context;

/**
 * CP-012 (COOKIE-DESIGN §2.5/§4.2): the CSRF Origin guard riding the hub's
 * auth seam. The server gates at global dispatch because its authenticator
 * runs on every request; the hub authenticates per-route INSIDE
 * {@see AuthMiddleware}, so the identical law — state-changing request +
 * COOKIE lineage + Origin/Referer not naming this host → 403
 * `csrf.invalid_origin` — is enforced here, after the token validated and
 * the existence gate passed, BEFORE any identity lands on the request.
 *
 * The two non-negotiable pins this suite owns:
 *  - BEARER IS UNAFFECTED. Browsers never auto-attach Authorization
 *    cross-origin, so a bearer request with a foreign (or entirely missing)
 *    Origin MUST sail through — a guard that 403s a valid bearer on Origin
 *    grounds would brick every native client in the estate (the law the
 *    CP-011 register carries forward).
 *  - THE GUARD RUNS ONLY AFTER AUTH SUCCEEDS. An invalid or dead cookie on
 *    a cross-origin POST answers the existing 401 frame, not a 403 — the
 *    rejection order must never become a carrier oracle (server parity:
 *    the gate only sees isCookieAuthenticated requests).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class CsrfOriginGuardMiddlewareTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';
    private const HOST = 'hub.example.test';
    /** The server's ratified rejection bytes, mirrored verbatim. */
    private const REJECT_FRAME = '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}';

    protected function setUp(): void
    {
        if (class_exists(Context::class)) {
            Context::destroy();
        }
        AuthMiddleware::resetCache();
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = []): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->headers = $headers;

        return $request;
    }

    private function validCookie(): string
    {
        return (new JwtHandler(self::SECRET))->createAccessToken('u-guard');
    }

    private function middleware(?UserRepository $repo = null): AuthMiddleware
    {
        if ($repo === null) {
            $repo = $this->createMock(UserRepository::class);
            $repo->method('userExists')->willReturn(true);
        }

        return new AuthMiddleware(new JwtHandler(self::SECRET), $repo);
    }

    /** @return array<string, string> */
    private function cookieHeader(): array
    {
        return ['Cookie' => 'phlix_hub_token=' . $this->validCookie()];
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return [
            'Origin' => 'https://' . self::HOST,
            'Host' => self::HOST,
        ];
    }

    /** @return array<string, string> cookie carrier + a cross-site origin */
    private function evilBrowserHeaders(): array
    {
        return $this->cookieHeader() + [
            'Origin' => 'https://evil.example',
            'Host' => self::HOST,
        ];
    }

    public function testCookieLineageStateChangingCrossOriginGetsTheByteExactRejectionFrame(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $request = $this->request($method, '/api/v1/servers', $this->evilBrowserHeaders());
            $response = $this->middleware()($request);

            self::assertNotNull($response, $method . ' on a cookie-lineage cross-site request must short-circuit');
            self::assertSame(403, $response->statusCode, $method);
            self::assertSame(self::REJECT_FRAME, $response->body, $method);
            self::assertSame('application/json', $response->headers['Content-Type'] ?? null, $method);
            // The rejection happens BEFORE identity: a refused request must
            // not leave a hydrated userId/claims on the object.
            self::assertNull($request->userId, $method);
            self::assertNull($request->claims, $method);
        }
    }

    public function testCookieLineageStateChangingWithNoOriginNamedIsRefusedFailClosed(): void
    {
        $headers = $this->cookieHeader();
        $headers['Host'] = self::HOST;

        $response = $this->middleware()($this->request('POST', '/api/v1/servers', $headers));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function testCookieLineageSameOriginStateChangingPassesAndHydrates(): void
    {
        $request = $this->request('POST', '/api/v1/servers', $this->cookieHeader() + $this->browserHeaders());

        self::assertNull($this->middleware()($request));
        self::assertSame('u-guard', $request->userId);
        self::assertNotNull($request->claims);
    }

    public function testCookieLineageSameSiteRefererWithoutOriginPasses(): void
    {
        $request = $this->request('DELETE', '/api/v1/servers/7', $this->cookieHeader() + [
            'Referer' => 'https://' . self::HOST . '/app/servers',
            'Host' => self::HOST,
        ]);

        self::assertNull($this->middleware()($request));
    }

    public function testGetOnCookieLineageStaysExemptEvenCrossSite(): void
    {
        // The method limb of the ratified law: GETs are not CSRF vectors on
        // this surface (the hub has NO GET logout route to carve — the
        // server's one exception has no hub twin; the absence is pinned in
        // ApplicationRouteCompositionTest's route census).
        $request = $this->request('GET', '/api/v1/me', $this->evilBrowserHeaders());

        self::assertNull($this->middleware()($request));
        self::assertSame('u-guard', $request->userId);
    }

    public function testBearerLineageStateChangingIsUntouchedByTheGuard(): void
    {
        // THE CP-012 non-regression centerpiece: valid bearer, foreign
        // Origin, state-changing verb — must sail through. Browsers never
        // auto-attach Authorization cross-origin; gating bearer here would
        // be the Critical regression the lane register names.
        $bearer = (new JwtHandler(self::SECRET))->createAccessToken('u-bearer');
        $request = $this->request('POST', '/api/v1/servers', [
            'Host' => self::HOST,
        ]);
        $request->bearerToken = $bearer;

        $mw = $this->middleware();
        self::assertNull($mw($request));
        self::assertSame('u-bearer', $request->userId);
    }

    public function testBearerLineageWithNoOriginAtAllPassesForNativeClients(): void
    {
        // curl/rok/tizen/windows natives send zero Origin/Referer. The
        // fail-closed limb may only fire on COOKIE lineage — a bearer
        // request naming no origin is the estate's normal wire.
        $bearer = (new JwtHandler(self::SECRET))->createAccessToken('u-native');
        $request = $this->request('PUT', '/api/v1/me/settings', ['Host' => self::HOST]);
        $request->bearerToken = $bearer;

        self::assertNull($this->middleware()($request));
        self::assertSame('u-native', $request->userId);
    }

    public function testBearerAndCookieBothPresentForeignOriginStillPasses(): void
    {
        // Precedence is absolute (CP-011 law): a dual-carrying request is
        // bearer-lineage, so the Origin guard never applies to it.
        $bearer = (new JwtHandler(self::SECRET))->createAccessToken('u-bearer');
        $request = $this->request('PATCH', '/api/v1/servers/7', $this->cookieHeader() + [
            'Origin' => 'https://evil.example',
            'Host' => self::HOST,
        ]);
        $request->bearerToken = $bearer;

        self::assertNull($this->middleware()($request));
        self::assertSame('u-bearer', $request->userId);
    }

    public function testInvalidCookieOnCrossOriginPostAnswers401NotTheGuardFrame(): void
    {
        // Server parity: the gate only sees AUTHENTICATED cookie lineage.
        // A garbage cookie must hit the existing invalid-token envelope —
        // 403-vs-401 ordering here would be a carrier oracle.
        $response = $this->middleware()($this->request('POST', '/api/v1/servers', [
            'Cookie' => 'phlix_hub_token=not-a-jwt',
            'Origin' => 'https://evil.example',
            'Host' => self::HOST,
        ]));

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.invalid_token', $response->body);
    }

    public function testGoneUserOnCrossOriginPostAnswersTheExistenceFrameFirst(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->willReturn(false);

        $response = $this->middleware($repo)($this->request('POST', '/api/v1/servers', $this->evilBrowserHeaders()));

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.user_not_found', $response->body);
    }

    public function testNoCredentialCrossOriginPostStillAnswersAuthRequired(): void
    {
        $response = $this->middleware()($this->request('POST', '/api/v1/servers', [
            'Origin' => 'https://evil.example',
            'Host' => self::HOST,
        ]));

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testOptionsIsNotStateChangingSoPreflightStaysUntouched(): void
    {
        // Router answers OPTIONS through the CORS chain; the guard's verb
        // list (mirror of the server's) excludes it — a cookie-carrying
        // OPTIONS with a foreign Origin must not short-circuit to 403.
        $request = $this->request('OPTIONS', '/api/v1/servers', $this->evilBrowserHeaders());

        self::assertNull($this->middleware()($request));
        self::assertSame('u-guard', $request->userId);
    }

    public function testCookieLineageCrossSitePostOnHtmlPathGetsJsonRejectionNotRedirect(): void
    {
        // The guard fires after auth SUCCEEDED, so the HTML-route 302
        // challenge shape is irrelevant here: a session-carrying cross-site
        // POST to a page path answers the same 403 JSON frame the API
        // answers (server mirror — the gate precedes routing).
        $response = $this->middleware()($this->request('POST', '/my-servers', $this->evilBrowserHeaders()));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame(self::REJECT_FRAME, $response->body);
    }
}
