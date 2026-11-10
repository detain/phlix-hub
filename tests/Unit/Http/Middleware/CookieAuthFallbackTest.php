<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use support\Context;

/**
 * CP-011 read-path seam: {@see AuthMiddleware} prefers the bearer header and
 * falls back to the `phlix_hub_token` session cookie (COOKIE-DESIGN §4.2 —
 * the hub twin of the server's RequestAuthenticator precedence). The cookie
 * carrier passes the SAME validation gate as a bearer and fails with the
 * SAME byte-ident challenge frames; no carrier oracle.
 *
 * Cookie names are asserted as WIRE LITERALS here (not class constants):
 * this suite also proves the read path independently of where the name
 * constant lives, and pins the revived legacy-surface names on the wire.
 * The class_exists guard around Context::destroy() keeps the suite honest
 * on non-Swoole hosts while matching the sibling AuthMiddlewareTest setup.
 */
final class CookieAuthFallbackTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';

    protected function setUp(): void
    {
        if (class_exists(Context::class)) {
            Context::destroy();
        }
        AuthMiddleware::resetCache();
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me';
        $request->headers = $headers;

        return $request;
    }

    private function middleware(?UserRepository $repo = null): AuthMiddleware
    {
        return new AuthMiddleware(new JwtHandler(self::SECRET), $repo ?? $this->existingUsers());
    }

    private function existingUsers(): UserRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->willReturn(true);

        return $repo;
    }

    public function testSessionCookieAloneAuthenticates(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-cookie');

        $middleware = $this->middleware();
        $request = $this->request(['Cookie' => 'phlix_hub_token=' . $token]);

        self::assertNull($middleware($request));
        self::assertSame('u-cookie', $request->userId);
        self::assertNotNull($request->claims);
    }

    public function testBearerTakesPrecedenceWhenBothPresentAndDiffer(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $bearer = $jwt->createAccessToken('u-bearer');
        $cookie = $jwt->createAccessToken('u-cookie');

        $middleware = $this->middleware();
        $request = $this->request(['Cookie' => 'phlix_hub_token=' . $cookie]);
        // bearerToken is the populated property the seam reads (fromWorkerman
        // fills it from the Authorization header; the sibling suite sets it
        // directly — same posture here).
        $request->bearerToken = $bearer;

        self::assertNull($middleware($request));
        self::assertSame('u-bearer', $request->userId, 'a presenting bearer always wins the identity');
    }

    public function testGarbageCookieFailsWithTheSameInvalidTokenFrame(): void
    {
        $response = $this->middleware()($this->request(['Cookie' => 'phlix_hub_token=not-a-jwt']));

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.invalid_token', $response->body);
    }

    public function testRefreshTypedCookieIsRefusedByTheAccessGate(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $refresh = $jwt->createRefreshToken('u-cookie');

        $response = $this->middleware()($this->request(['Cookie' => 'phlix_hub_token=' . $refresh]));

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.invalid_token', $response->body);
    }

    public function testCookieForMissingUserHitsTheExistenceGate(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-gone');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('userExists')->with('u-gone')->willReturn(false);

        $response = $this->middleware($repo)($this->request(['Cookie' => 'phlix_hub_token=' . $token]));

        self::assertNotNull($response);
        self::assertStringContainsString('auth.user_not_found', $response->body);
    }

    public function testCookieOnlyRequestOnHtmlRouteStillBouncesToLoginWhenInvalid(): void
    {
        $request = $this->request(['Cookie' => 'phlix_hub_token=nonsense']);
        $request->path = '/my-servers';

        $response = $this->middleware()($request);

        self::assertNotNull($response);
        self::assertSame(302, $response->statusCode);
        self::assertSame('/app/login', $response->headers['Location'] ?? '');
    }

    public function testNoCredentialChallengeFramesAreUnchanged(): void
    {
        // Regression guard for the non-regression law: with no bearer AND no
        // cookie, the frames this middleware has always answered must be
        // byte-stable — the fallback adds a carrier, not a new rejection.
        $api = $this->middleware()($this->request());
        self::assertNotNull($api);
        self::assertSame(401, $api->statusCode);
        self::assertStringContainsString('auth.required', $api->body);
    }

    public function testCookieHeaderParsingIgnoresSiblingsAndSpaces(): void
    {
        $jwt = new JwtHandler(self::SECRET);
        $token = $jwt->createAccessToken('u-parse');

        $request = $this->request(['Cookie' => 'theme=dark; phlix_hub_token=' . $token . '; other=1']);
        self::assertNull($this->middleware()($request));
        self::assertSame('u-parse', $request->userId);

        // Empty-value twin of "absent".
        $empty = $this->request(['Cookie' => 'phlix_hub_token=; a=b']);
        self::assertNull($empty->getCookie('phlix_hub_token'));
    }
}
