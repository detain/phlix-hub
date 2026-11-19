<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Controllers\AuthController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Tests\Support\CookiePolicyTestShapes;
use Phlix\Shared\Security\OriginPolicy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A19 wire-parity + kernel-equivalence law for {@see CookiePolicy}.
 *
 * The S2-ADOPT FINDING bank: the hub's cookie SSOT "has NO tests of its own"
 * in the emission sense — the pre-existing suites pin the decision law and
 * the env accept-sets, but nothing on disk byte-pinned what actually reaches
 * the browser jar. This suite closes that hole BEFORE the A19 fold onto the
 * shared {@see OriginPolicy} kernel (red-first discipline): every expected
 * value below was captured from the pre-fold code, and the fold must keep
 * this file green with ZERO expectation edits — a moved expectation here is
 * a behavior change, which the fold is forbidden to make.
 *
 * Three pillars:
 *  1. decision tables — custody, lenient same-site, strict CP-012 law,
 *     forwarded-proto, localhost, scheme eligibility: ratified verdicts
 *     including the disclosed quirks (empty-Origin accept, the custody-limb
 *     no-trim vs belt trim asymmetry, raw-case strict hosts);
 *  2. wire bytes — the EXACT Set-Cookie strings each emission path puts on
 *     the response (signup/login/refresh/logout, secure and insecure arms,
 *     URL-encoded values, absence laws for device/no-signal/switch-off,
 *     and no Domain attribute anywhere; the CSRF guard is validation-only
 *     and issues nothing);
 *  3. kernel equivalence — a cross-product sweep asserting the hub's
 *     verdicts equal `OriginPolicy`'s (formEra=false) over thousands of
 *     header combinations: the same-process twin of the server-fold map,
 *     which is what licenses the fold to be a shape change and nothing else.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class CookiePolicyWireParityTest extends TestCase
{
    /** @var string The hub's canonical Host value used across the tables. */
    private const HOST = 'hub.example.test';

    /** @var array<string, mixed> AuthManager success payload for the wire arms. */
    private const TOKENS = [
        'access_token' => 'access-tok',
        'refresh_token' => 'refresh-tok',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user' => ['id' => 'u-1', 'username' => 'alice'],
        'claims' => ['sub' => 'u-1'],
    ];

    /** @var array<string, mixed> Rotated payload returned by the refresh mock. */
    private const ROTATED = [
        'access_token' => 'access-tok-2',
        'refresh_token' => 'refresh-tok-2',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user' => ['id' => 'u-1', 'username' => 'alice'],
        'claims' => ['sub' => 'u-1'],
    ];

    /** Secure-arm session cookie exactly as it rides the wire (pre-fold capture). */
    private const WIRE_ACCESS =
        'phlix_hub_token=access-tok; Max-Age=3600; Path=/; Secure; HttpOnly; SameSite=Lax';

    /** Secure-arm refresh cookie exactly as it rides the wire (pre-fold capture). */
    private const WIRE_REFRESH =
        'phlix_hub_refresh=refresh-tok; Max-Age=604800; Path=/; Secure; HttpOnly; SameSite=Lax';

    private const WIRE_ACCESS_ROT =
        'phlix_hub_token=access-tok-2; Max-Age=3600; Path=/; Secure; HttpOnly; SameSite=Lax';

    private const WIRE_REFRESH_ROT =
        'phlix_hub_refresh=refresh-tok-2; Max-Age=604800; Path=/; Secure; HttpOnly; SameSite=Lax';

    private const WIRE_ACCESS_INSECURE =
        'phlix_hub_token=access-tok; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax';

    private const WIRE_REFRESH_INSECURE =
        'phlix_hub_refresh=refresh-tok; Max-Age=604800; Path=/; HttpOnly; SameSite=Lax';

    /** Logout clears ride ungated: revocation hygiene, empty value, Max-Age=0. */
    private const WIRE_CLEAR_ACCESS =
        'phlix_hub_token=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    private const WIRE_CLEAR_REFRESH =
        'phlix_hub_refresh=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax';

    protected function setUp(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
    }

    protected function tearDown(): void
    {
        putenv(CookiePolicy::INSECURE_ENV);
        parent::tearDown();
    }

    /**
     * Failure-message formatter shared by the decision tables and sweeps:
     * names the limb and var-exports every input, so a red line is directly
     * reproducible against the pre-fold capture.
     *
     * @param array<string, string|int|bool|float|null> $parts
     */
    private static function describe(string $law, array $parts): string
    {
        $pieces = [];
        foreach ($parts as $name => $value) {
            $pieces[] = $name . '=' . var_export($value, true);
        }

        return $law . '(' . implode(', ', $pieces) . ')';
    }

    // ------------------------------------------------------------------
    // Pillar 1: decision tables (captured from the pre-fold implementation)
    // ------------------------------------------------------------------

    public function testCustodyDecisionTable(): void
    {
        $policy = CookiePolicyTestShapes::on();
        $sameSiteOrigin = 'https://' . self::HOST;

        $cases = [
            // dev marker wins over everything, even a same-site origin.
            ['rok', $sameSiteOrigin, self::HOST, 'same-origin', CookiePolicy::MODE_BEARER],
            ['ROK', null, null, null, CookiePolicy::MODE_BEARER],
            [' ', null, null, null, CookiePolicy::MODE_BEARER],
            // Present origin that is not same-site http(s) → bearer.
            [null, 'https://evil.test', self::HOST, null, CookiePolicy::MODE_BEARER],
            [null, 'app://localhost', self::HOST, 'same-origin', CookiePolicy::MODE_BEARER],
            // Same-site origin → browser-class cookie custody.
            [null, $sameSiteOrigin, self::HOST, null, CookiePolicy::MODE_COOKIE],
            [null, $sameSiteOrigin . ':443', self::HOST, null, CookiePolicy::MODE_COOKIE],
            [null, $sameSiteOrigin, self::HOST . ':8443', null, CookiePolicy::MODE_BEARER],
            [null, 'HTTPS://HUB.Example.Test', self::HOST, null, CookiePolicy::MODE_COOKIE],
            // Belt limb: Sec-Fetch-Site same-origin marks browser-class — and
            // the limb does NOT trim (ratified asymmetry vs the belt of the
            // request law, which does): ' same-origin ' stays bearer here.
            [null, null, self::HOST, 'same-origin', CookiePolicy::MODE_COOKIE],
            [null, null, self::HOST, 'SAME-ORIGIN', CookiePolicy::MODE_COOKIE],
            [null, null, self::HOST, ' same-origin ', CookiePolicy::MODE_BEARER],
            [null, null, self::HOST, 'none', CookiePolicy::MODE_BEARER],
            [null, null, self::HOST, 'cross-site', CookiePolicy::MODE_BEARER],
            // No signal at all → bearer (today's JSON posture for natives).
            [null, null, self::HOST, null, CookiePolicy::MODE_BEARER],
            // Null host can never make an Origin same-site.
            [null, $sameSiteOrigin, null, null, CookiePolicy::MODE_BEARER],
        ];

        foreach ($cases as [$device, $origin, $host, $secFetch, $expected]) {
            self::assertSame(
                $expected,
                $policy->custodyMode($device, $origin, $host, $secFetch),
                self::describe('custody', ['dev' => $device, 'origin' => $origin, 'host' => $host, 'sfs' => $secFetch])
            );
        }
    }

    public function testOriginIsSameSiteDecisionTable(): void
    {
        $cases = [
            ['https://' . self::HOST, self::HOST, true],
            ['http://' . self::HOST, self::HOST, true],
            ['https://' . self::HOST . ':443', self::HOST, true],
            ['https://' . self::HOST, self::HOST . ':443', true],
            ['http://' . self::HOST . ':80', self::HOST, true],
            ['https://' . self::HOST . ':8443', self::HOST . ':8443', true],
            ['https://' . self::HOST . ':8443', self::HOST, false],
            ['HTTPS://HUB.Example.Test', self::HOST, true],
            ['https://' . self::HOST, 'HUB.Example.Test', true],
            ['http://[::1]:8080', '[::1]:8080', true],
            ['http://[::1]', '[::1]', true],
            ['http://[::1]:80', '[::1]', true],
            ['app://localhost', 'localhost', false],
            ['file://', self::HOST, false],
            ['capacitor://localhost', 'localhost', false],
            ['not a url', self::HOST, false],
            ['http:///bad', self::HOST, false],
            ['https://evil-hub.example.test', self::HOST, false],
            ['https://hub.example.test.evil', self::HOST, false],
            ['https://hub.example.test', '', false],
            ['https://hub.example.test', null, false],
            ['', self::HOST, false],
            ['https://tizen.org', self::HOST, false],
        ];

        foreach ($cases as [$origin, $host, $expected]) {
            self::assertSame(
                $expected,
                CookiePolicy::originIsSameSite($origin, $host),
                self::describe('originIsSameSite', ['origin' => $origin, 'host' => $host])
            );
        }
    }

    public function testStateChangingOriginLawDecisionTable(): void
    {
        $origin = 'https://' . self::HOST;
        $goodReferer = 'https://' . self::HOST . '/x';

        $cases = [
            [$origin, null, self::HOST, null, true],
            [$origin, null, self::HOST, 'same-origin', true],
            [$origin, null, self::HOST, 'cross-site', false],
            // Belt trims+lowers before matching the exact promise 'cross-site'.
            [$origin, null, self::HOST, ' CROSS-SITE ', false],
            [null, null, self::HOST, null, false],
            // M-SERVER-76: literal-'null' Origin is the opaque marker — refuse.
            ['null', $goodReferer, self::HOST, null, false],
            // Ratified quirk, pinned not polished (both repos carry it):
            // empty-string Origin skips the origin arm; no Referer left to
            // refuse means the request PASSES on an empty Origin alone.
            ['', null, self::HOST, null, true],
            [null, $goodReferer, self::HOST, null, true],
            // Strict axis: raw hosts, both-or-neither ports, NO normalization.
            ['http://' . self::HOST . ':80', null, self::HOST, null, false],
            ['http://' . self::HOST . ':80', null, self::HOST . ':80', null, true],
            [$origin, null, self::HOST . ':8080', null, false],
            ['HTTPS://HUB.Example.Test', null, self::HOST, null, false],
            [$origin, null, 'HUB.Example.Test', null, false],
            ['https://evil-hub.example.test', null, self::HOST, null, false],
            ['https://hub.example.test.evil', null, self::HOST, null, false],
            [$origin, 'https://evil.test/x', self::HOST, null, false],
            [null, 'https://evil.test/x', self::HOST, null, false],
            [null, 'https://' . self::HOST . '/deep/path', self::HOST, null, true],
            ['not a url', $goodReferer, self::HOST, null, false],
            ['http:///bad', null, self::HOST, null, false],
            [null, 'http:///bad', self::HOST, null, false],
            [$origin, null, '', null, false],
            [$origin, null, null, null, false],
            ['http://[::1]:8080', null, '[::1]:8080', null, true],
            ['http://[::1]', null, '[::1]:80', null, false],
        ];

        foreach ($cases as [$o, $r, $h, $s, $expected]) {
            self::assertSame(
                $expected,
                CookiePolicy::stateChangingRequestOriginAllowed($o, $r, $h, $s),
                self::describe('strictLaw', ['origin' => $o, 'referer' => $r, 'host' => $h, 'sfs' => $s])
            );
        }
    }

    public function testHttpsForwardedDecisionTable(): void
    {
        $cases = [
            [null, false],
            ['', false],
            ['https', true],
            ['HTTPS', true],
            [' https ', true],
            ['https, http', true],
            ['http, https', false],
            ['http', false],
            ['wss', false],
            ['https:x', false],
            [',https', false],
        ];

        foreach ($cases as [$value, $expected]) {
            self::assertSame(
                $expected,
                CookiePolicy::isHttpsForwarded($value),
                self::describe('isHttpsForwarded', ['value' => $value])
            );
        }
    }

    public function testLocalhostHostDecisionTable(): void
    {
        $cases = [
            [null, false],
            ['', false],
            ['localhost', true],
            ['LOCALHOST', true],
            ['localhost:8080', true],
            ['127.0.0.1:3000', true],
            ['[::1]', true],
            ['[::1]:9000', true],
            ['::1', false],
            [self::HOST, false],
            ['evil-localhost', false],
            ['localhost.evil', false],
            ['not a host', false],
        ];

        foreach ($cases as [$value, $expected]) {
            self::assertSame(
                $expected,
                CookiePolicy::isLocalhostHost($value),
                self::describe('isLocalhostHost', ['value' => $value])
            );
        }
    }

    public function testSchemeEligibleDecisionTable(): void
    {
        $policy = CookiePolicyTestShapes::on();

        $cases = [
            ['https', self::HOST, true],
            [null, 'localhost', true],
            [null, self::HOST, false],
            ['http', '127.0.0.1:8080', true],
            [null, null, false],
        ];

        foreach ($cases as [$forwarded, $host, $expected]) {
            self::assertSame(
                $expected,
                $policy->schemeEligible($forwarded, $host),
                self::describe('schemeEligible', ['forwarded' => $forwarded, 'host' => $host])
            );
        }
    }

    // ------------------------------------------------------------------
    // Pillar 2: wire bytes per emission path (captured from the wire, too)
    // ------------------------------------------------------------------

    /** @return list<string> Every Set-Cookie line the response would put on the wire. */
    private function wireCookies(Response $response): array
    {
        /** @var array<string, mixed> $headers */
        $headers = $response->toWorkermanResponse()->getHeaders();
        /** @var list<string> $setCookie */
        $setCookie = $headers['Set-Cookie'] ?? [];

        return $setCookie;
    }

    private function authMgr(): AuthManager&MockObject
    {
        $auth = $this->createMock(AuthManager::class);
        $auth->method('accessTtl')->willReturn(3600);
        $auth->method('refreshTtl')->willReturn(604800);

        return $auth;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function browserHeaders(array $headers = []): array
    {
        return $headers + [
            'Origin' => 'https://' . self::HOST,
            'Host' => self::HOST,
            'X-Forwarded-Proto' => 'https',
        ];
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
     * @param array<string, string>  $headers
     * @param array<string, mixed>|null $payload
     */
    private function loginResponse(array $headers, ?CookiePolicy $policy = null, ?array $payload = null): Response
    {
        $auth = $this->authMgr();
        $auth->method('login')->willReturn($payload ?? self::TOKENS);

        return $this->controller($auth, $policy)->loginJson(
            $this->request('/api/v1/auth/login', ['username' => 'a', 'password' => 'b'], $headers)
        );
    }

    private function controller(AuthManager $auth, ?CookiePolicy $policy = null): AuthController
    {
        return new AuthController($auth, $policy ?? CookiePolicyTestShapes::on());
    }

    public function testLoginArmWireBytes(): void
    {
        $wire = $this->wireCookies($this->loginResponse($this->browserHeaders()));

        self::assertSame([self::WIRE_ACCESS, self::WIRE_REFRESH], $wire);
    }

    public function testSignupArmWireBytes(): void
    {
        $auth = $this->authMgr();
        $auth->method('register')->willReturn(self::TOKENS);

        $response = $this->controller($auth)->signupJson($this->request(
            '/api/v1/auth/register',
            ['username' => 'a', 'email' => 'a@example.test', 'password' => 'b'],
            $this->browserHeaders()
        ));

        self::assertSame(201, $response->statusCode);
        self::assertSame([self::WIRE_ACCESS, self::WIRE_REFRESH], $this->wireCookies($response));
    }

    public function testRefreshArmWireBytes(): void
    {
        $auth = $this->authMgr();
        $auth->method('refresh')->with('r')->willReturn(self::ROTATED);

        $response = $this->controller($auth)->refreshJson(
            $this->request('/api/v1/auth/refresh', ['refresh_token' => 'r'], $this->browserHeaders())
        );

        self::assertSame([self::WIRE_ACCESS_ROT, self::WIRE_REFRESH_ROT], $this->wireCookies($response));
    }

    public function testLogoutArmWireBytes(): void
    {
        $auth = $this->authMgr();
        $auth->method('logout')->willReturnCallback(static function (): void {
        });

        $response = $this->controller($auth)->logoutJson(
            $this->request('/api/v1/auth/logout', ['refresh_token' => 'r'], $this->browserHeaders())
        );

        self::assertSame(204, $response->statusCode);
        self::assertSame([self::WIRE_CLEAR_ACCESS, self::WIRE_CLEAR_REFRESH], $this->wireCookies($response));
    }

    public function testLogoutClearRidesDeviceArmsWithIdenticalBytes(): void
    {
        // Clearing is jar hygiene, not issuance — device-class logout must
        // still put the exact same two clears on the wire.
        $auth = $this->authMgr();
        $auth->method('logout')->willReturnCallback(static function (): void {
        });

        $response = $this->controller($auth)->logoutJson(
            $this->request('/api/v1/auth/logout', ['refresh_token' => 'r'], ['X-Phlix-Device-Type' => 'rok'])
        );

        self::assertSame([self::WIRE_CLEAR_ACCESS, self::WIRE_CLEAR_REFRESH], $this->wireCookies($response));
    }

    public function testInsecureArmWireBytesDropSecure(): void
    {
        putenv(CookiePolicy::INSECURE_ENV . '=1');

        $headers = $this->browserHeaders();
        $headers['Origin'] = 'http://' . self::HOST;
        $headers['X-Forwarded-Proto'] = 'http';

        $wire = $this->wireCookies($this->loginResponse($headers));

        self::assertSame([self::WIRE_ACCESS_INSECURE, self::WIRE_REFRESH_INSECURE], $wire);
    }

    public function testCookieValueIsRawUrlEncodedOnTheWire(): void
    {
        $payload = self::TOKENS;
        $payload['access_token'] = 'a.b+c=d/e~f g*h';

        $wire = $this->wireCookies($this->loginResponse($this->browserHeaders(), null, $payload));

        self::assertSame(
            'phlix_hub_token=a.b%2Bc%3Dd%2Fe~f%20g%2Ah; Max-Age=3600; Path=/; Secure; HttpOnly; SameSite=Lax',
            $wire[0]
        );
    }

    public function testDeviceArmCarriesNoSetCookieOnTheWire(): void
    {
        $headers = $this->browserHeaders();
        $headers['X-Phlix-Device-Type'] = 'rok';

        self::assertSame([], $this->wireCookies($this->loginResponse($headers)));
    }

    public function testNoSignalNativeArmCarriesNoSetCookieOnTheWire(): void
    {
        self::assertSame([], $this->wireCookies($this->loginResponse(['Host' => self::HOST])));
    }

    public function testMasterSwitchOffCarriesNoSetCookieOnTheWire(): void
    {
        $off = $this->loginResponse($this->browserHeaders(), CookiePolicyTestShapes::with(enabled: false));

        self::assertSame([], $this->wireCookies($off));
    }

    public function testNoEmissionArmCarriesADomainAttribute(): void
    {
        $wires = [
            $this->wireCookies($this->loginResponse($this->browserHeaders())),
            $this->wireCookies(
                $this->loginResponse($this->browserHeaders(), CookiePolicyTestShapes::with(insecure: true))
            ),
        ];

        $auth = $this->authMgr();
        $auth->method('logout')->willReturnCallback(static function (): void {
        });
        $wires[] = $this->wireCookies($this->controller($auth)->logoutJson(
            $this->request('/api/v1/auth/logout', ['refresh_token' => 'r'], $this->browserHeaders())
        ));

        foreach ($wires as $wire) {
            foreach ($wire as $line) {
                self::assertStringNotContainsString('Domain=', $line, 'auth cookies are host-only by design: ' . $line);
            }
        }
    }

    public function testCsrfGuardValidatesOnlyAndNeverIssuesCookies(): void
    {
        // The mission's csrf leg: the guard is the enforcement twin of the
        // request law, NOT an emission path — no cookie queue, no names.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Http/Middleware/CsrfOriginGuard.php');

        self::assertStringContainsString(
            'stateChangingRequestOriginAllowed',
            $source,
            'the guard must keep enforcing the CP-012 law'
        );
        self::assertSame([], $this->matchCookieIssuance($source), 'the CSRF guard must not issue cookies');
    }

    public function testModeConstantsMirrorTheKernelVocabulary(): void
    {
        self::assertSame(OriginPolicy::MODE_COOKIE, CookiePolicy::MODE_COOKIE);
        self::assertSame(OriginPolicy::MODE_BEARER, CookiePolicy::MODE_BEARER);
    }

    /** @return list<string> */
    private function matchCookieIssuance(string $source): array
    {
        preg_match_all('/->\s*cookie\s*\(/', $source, $matches);

        return $matches[0];
    }

    // ------------------------------------------------------------------
    // Pillar 3: kernel equivalence sweeps (what licenses the fold)
    // ------------------------------------------------------------------

    /** @var list<string> Header-shaped values for the sweeps (no nulls). */
    private const SWEEP_ORIGINS = [
        '',
        'null',
        'https://hub.example.test',
        'http://hub.example.test',
        'https://hub.example.test:443',
        'https://hub.example.test:8443',
        'http://hub.example.test:80',
        'HTTPS://HUB.EXAMPLE.TEST',
        'app://localhost',
        'file://',
        'not a url',
        'http:///bad',
        'https://evil-hub.example.test',
        'https://hub.example.test.evil',
        'http://[::1]:8080',
        'http://[::1]',
        'same-origin',
    ];

    /** @var list<string|null> Host values for the sweeps (null = absent header). */
    private const SWEEP_HOSTS = [
        null,
        '',
        'hub.example.test',
        'HUB.Example.Test',
        'hub.example.test:443',
        'hub.example.test:80',
        'hub.example.test:8443',
        'hub.example.test:8080',
        'localhost',
        '127.0.0.1',
        '[::1]',
        '[::1]:80',
        '::1',
    ];

    /** @var list<string|null> Sec-Fetch-Site values for the sweeps. */
    private const SWEEP_SEC_FETCH = [
        null,
        '',
        'same-origin',
        'same-site',
        'cross-site',
        ' CROSS-SITE ',
        'none',
        'junk',
    ];

    /** @var list<string|null> Referer values for the strict-law sweep. */
    private const SWEEP_REFERERS = [
        null,
        '',
        'https://hub.example.test/x',
        'https://evil.test/x',
        'http://hub.example.test:80/x',
        'hub.example.test/no-scheme',
        'http:///bad',
        'HTTPS://HUB.Example.Test/x',
    ];

    public function testCustodyEqualsSharedKernelAcrossTheSweep(): void
    {
        $policy = CookiePolicyTestShapes::on();

        foreach (['', 'rok', 'ROK', ' '] as $device) {
            foreach (self::SWEEP_ORIGINS as $origin) {
                foreach (self::SWEEP_HOSTS as $host) {
                    foreach (self::SWEEP_SEC_FETCH as $secFetch) {
                        self::assertSame(
                            OriginPolicy::custodyMode(false, $device, $origin, $host, $secFetch),
                            $policy->custodyMode($device, $origin, $host, $secFetch),
                            self::describe(
                                'custody',
                                ['dev' => $device, 'origin' => $origin, 'host' => $host, 'sfs' => $secFetch]
                            )
                        );
                    }
                }
            }
        }
    }

    public function testCustodyEqualsSharedKernelWithNullOriginLegs(): void
    {
        $policy = CookiePolicyTestShapes::on();

        foreach ([null, ''] as $device) {
            foreach (self::SWEEP_HOSTS as $host) {
                foreach (self::SWEEP_SEC_FETCH as $secFetch) {
                    self::assertSame(
                        OriginPolicy::custodyMode(false, $device, null, $host, $secFetch),
                        $policy->custodyMode($device, null, $host, $secFetch),
                        self::describe(
                            'custody/null-origin',
                            ['dev' => $device, 'host' => $host, 'sfs' => $secFetch]
                        )
                    );
                }
            }
        }
    }

    public function testOriginIsSameSiteEqualsSharedKernelAcrossTheSweep(): void
    {
        foreach (self::SWEEP_ORIGINS as $origin) {
            foreach (self::SWEEP_HOSTS as $host) {
                self::assertSame(
                    OriginPolicy::originIsSameSite($origin, $host),
                    CookiePolicy::originIsSameSite($origin, $host),
                    sprintf('same-site drift: origin=%s host=%s', $origin, (string) $host)
                );
            }
        }
    }

    public function testStrictOriginLawEqualsSharedKernelAcrossTheSweep(): void
    {
        foreach ([null, ...self::SWEEP_ORIGINS] as $origin) {
            foreach (self::SWEEP_REFERERS as $referer) {
                foreach (self::SWEEP_HOSTS as $host) {
                    foreach (self::SWEEP_SEC_FETCH as $secFetch) {
                        self::assertSame(
                            OriginPolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $secFetch),
                            CookiePolicy::stateChangingRequestOriginAllowed($origin, $referer, $host, $secFetch),
                            self::describe(
                                'strictLaw',
                                ['origin' => $origin, 'referer' => $referer, 'host' => $host, 'sfs' => $secFetch]
                            )
                        );
                    }
                }
            }
        }
    }

    public function testHttpsForwardedEqualsSharedKernelAcrossTheSweep(): void
    {
        $values = [
            null,
            '',
            'https',
            'HTTPS',
            ' https ',
            'https, http',
            'http, https',
            'http',
            'wss',
            'https:x',
            ',https',
        ];

        foreach ($values as $value) {
            self::assertSame(
                OriginPolicy::httpsForwardedValue($value),
                CookiePolicy::isHttpsForwarded($value),
                self::describe('isHttpsForwarded', ['value' => $value])
            );
        }
    }

    public function testLocalhostHostEqualsSharedKernelAcrossTheSweep(): void
    {
        $hosts = [
            ...self::SWEEP_HOSTS,
            'LOCALHOST',
            'localhost:8080',
            '127.0.0.1:3000',
            '[::1]:9000',
            'evil-localhost',
            'localhost.evil',
            'not a host',
        ];

        foreach ($hosts as $host) {
            self::assertSame(
                OriginPolicy::localhostHost($host),
                CookiePolicy::isLocalhostHost($host),
                self::describe('isLocalhostHost', ['value' => $host])
            );
        }
    }
}
