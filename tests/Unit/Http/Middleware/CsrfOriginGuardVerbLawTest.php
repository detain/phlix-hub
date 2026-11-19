<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Http\Middleware\CsrfOriginGuard;
use Phlix\Hub\Http\Request;
use Phlix\Shared\Security\OriginPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HUB-4.2 (bug #3 hub half, drift pin): the CSRF verb-law parity contract
 * between the two hosts' Origin guards.
 *
 * The verb list that used to be hand-carried here (`CsrfOriginGuard::
 * STATE_CHANGING_METHODS`) now lives in the shared kernel — phlix-shared
 * v0.56.0 `OriginPolicy::stateChangingMethod()` — so drift is structurally
 * impossible, exactly the fold this file's original docblock anticipated.
 * The kernel's list is the server-ratified one verbatim: phlix-server
 * `src/Server/Http/RequestAuthenticator.php` gates `in_array($method,
 * ['POST', 'PUT', 'DELETE', 'PATCH'], true)`. This test pins that compiled
 * law and the strict-case exemption shape the guard actually runs; the pin
 * lands GREEN by construction — its teeth were proven by the mutation probe
 * recorded in the HUB-4 report (loosen the list to ['POST'] and the
 * PUT/DELETE/PATCH legs plus the literal pin redden).
 *
 * The full Origin/Referer/Sec-Fetch decision logic is arm-for-arm identical
 * to the server already (server canonicals re-verified during HUB-4.2; the
 * literal-`null` Origin refusal from M-SERVER-76 and the §2.5 belt are both
 * grown here and pinned by CookiePolicyOriginLawTest). The remaining real
 * delta — the missing production insecure-cookie warning — is ported in
 * src/Hub/CookiePolicy.php and pinned by CookiePolicyProductionInsecureWarnTest.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class CsrfOriginGuardVerbLawTest extends TestCase
{
    /**
     * The server's ratified verb list — verbatim from phlix-server
     * RequestAuthenticator::validateCsrfOrigin's in_array literal.
     */
    private const SERVER_CANONICAL = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /** @return list<string> verbs the shared kernel's predicate accepts */
    private function kernelStateChangingVerbs(): array
    {
        $candidates = [
            'POST', 'PUT', 'DELETE', 'PATCH', 'GET', 'HEAD', 'OPTIONS', 'TRACE',
            'post', 'put', 'delete', 'patch', 'CONNECT', '',
        ];

        $accepted = [];
        foreach ($candidates as $verb) {
            if (OriginPolicy::stateChangingMethod($verb)) {
                $accepted[] = $verb;
            }
        }

        return $accepted;
    }

    private function request(string $method, ?string $origin, ?string $host): Request
    {
        $request = new Request();
        $request->method = $method;
        $headers = [];
        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }
        if ($host !== null) {
            $headers['Host'] = $host;
        }
        $request->headers = $headers;

        return $request;
    }

    public function testVerbListIsTheServerCanonicalListExactly(): void
    {
        // Same-name, same-contents, same-order — one deny vocabulary across
        // the two hosts, now structurally so: the shared kernel carries the
        // list and the hub guard delegates its METHOD limb to it.
        self::assertSame(self::SERVER_CANONICAL, $this->kernelStateChangingVerbs());
    }

    /**
     * @return array{0: string}[]
     */
    public static function stateChangingVerbProvider(): array
    {
        return [
            ['POST'],
            ['PUT'],
            ['DELETE'],
            ['PATCH'],
        ];
    }

    #[DataProvider('stateChangingVerbProvider')]
    public function testEveryStateChangingVerbWithAForeignOriginIsRefused(string $method): void
    {
        $rejection = CsrfOriginGuard::enforce(
            $this->request($method, 'https://evil.example', 'hub.example.test')
        );

        self::assertNotNull($rejection, "{$method} is inside the verb limb");
        $rendered = (string) $rejection->toWorkermanResponse();
        self::assertStringContainsString('HTTP/1.1 403', $rendered);
        self::assertStringContainsString('csrf.invalid_origin', $rendered);
    }

    /**
     * @return array{0: string}[]
     */
    public static function exemptVerbProvider(): array
    {
        return [
            ['GET'],
            ['HEAD'],
            ['OPTIONS'],
            // Strict-case law, identical to the server's in_array(..., true):
            // a lowercase verb is NOT state-changing for the guard. Wire
            // methods arrive uppercase from the parsers; the pin documents
            // that the comparison is case-strict on both hosts.
            ['post'],
        ];
    }

    #[DataProvider('exemptVerbProvider')]
    public function testVerbsOutsideTheLimbAreExempt(string $method): void
    {
        self::assertNull(
            CsrfOriginGuard::enforce($this->request($method, 'https://evil.example', 'hub.example.test')),
            "{$method} must never reach the origin law"
        );
    }

    public function testLiteralNullOriginStillRefusesThroughTheGuard(): void
    {
        // M-SERVER-76 opaque-origin marker, at the enforcement shell (the
        // pure law already pins it in CookiePolicyOriginLawTest; this leg
        // proves the guard wires the same law, not a weakened copy).
        $rejection = CsrfOriginGuard::enforce($this->request('POST', 'null', 'hub.example.test'));

        self::assertNotNull($rejection);
        self::assertStringContainsString('csrf.invalid_origin', (string) $rejection->toWorkermanResponse());
    }

    public function testSameSiteOriginPassesThroughTheGuard(): void
    {
        // The neighborhood-accept shape (exact host match) so the refusal
        // legs above cannot pass on a guard that always 403s.
        self::assertNull(
            CsrfOriginGuard::enforce($this->request('POST', 'https://hub.example.test', 'hub.example.test'))
        );
    }
}
