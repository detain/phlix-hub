<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Container\Providers\HttpServicesProvider;
use Phlix\Hub\Hub\CookiePolicy;
use PHPUnit\Framework\TestCase;

/**
 * CP-011 single-seam law (the hub twin of the server's
 * CookiePolicyWiringGuardTest, filed-against-its-own-DD lesson: a bypassed
 * minter makes the master switch a no-op on that path — the Major class the
 * server round 2 just caught on its OAuth callback arms).
 *
 * The hub has exactly ONE issuance choke point by construction:
 *  - among files that QUEUE auth cookies (`->cookie(` with the hub cookie
 *    names), only AuthController may do it outside Response/CookiePolicy;
 *  - the attach body itself must consult `cookieModeEnabled()` (belt, per
 *    the §7 rollback wording this seam makes literally true);
 *  - every emit arm (signup/login/refresh) must route through
 *    `classifyRequest()`;
 *  - `classifyRequest()` must consult all three policy questions
 *    (custodyMode + cookieModeEnabled + schemeEligible);
 *  - the container binds the policy and hands the SAME singleton to the
 *    controller (no per-request divergent readings between classify/attach).
 *
 * Source-scan assertions are the estate's established law-pin idiom
 * (StaticAnalysisScopeTest, ParallelRunnerPipeGuardWiringTest); the
 * behavioral teeth live in CookieDualEmitTest's per-leg mutations.
 */
final class CookiePolicyWiringGuardTest extends TestCase
{
    private const CONTROLLER_FILE = __DIR__ . '/../../../../src/Http/Controllers/AuthController.php';

    private function controllerSource(): string
    {
        $src = file_get_contents(self::CONTROLLER_FILE);
        self::assertIsString($src);

        return $src;
    }

    /** @return list<string> */
    private function methodBody(string $source, string $method): array
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, "method {$method} vanished from the controller");

        $open = strpos($source, '{', $start);
        self::assertIsInt($open);

        $depth = 0;
        $end = $open;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        return [substr($source, $open, $end - $open + 1)];
    }

    public function testAuthControllerIsTheOnlyCookieMinterOutsideThePrimitive(): void
    {
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../../../../src',
            \FilesystemIterator::SKIP_DOTS,
        ));

        $minters = [];
        /** @var \SplFileInfo $file */
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, 'Http/Response.php')) {
                continue; // the primitive itself
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/->cookie\(\s*(?:CookiePolicy::|self::)?(?:SESSION|REFRESH)_COOKIE/', $source)) {
                $minters[] = basename($path);
            }
        }

        // Logout clears + attach issues are BOTH inside AuthController; any
        // NEW file showing up here is a bypassed minter until it goes through
        // the policy seam (and then still only via this controller — add it
        // here deliberately or not at all).
        self::assertSame(['AuthController.php'], array_values(array_unique($minters)));
    }

    public function testCookieNameLiteralsStayConfinedToPolicyControllerAndMiddleware(): void
    {
        $allowed = ['CookiePolicy.php', 'AuthController.php', 'AuthMiddleware.php'];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../../../../src',
            \FilesystemIterator::SKIP_DOTS,
        ));

        /** @var \SplFileInfo $file */
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!preg_match('/phlix_hub_(?:token|refresh)/', $source)) {
                continue;
            }
            self::assertContains(
                basename($file->getPathname()),
                $allowed,
                'a new surface hard-coding the hub cookie names bypasses the CookiePolicy seam',
            );
        }
    }

    public function testAttachBeltConsultsTheMasterSwitch(): void
    {
        $source = $this->controllerSource();
        $body = $this->methodBody($source, 'attachAuthCookies')[0];

        self::assertStringContainsString('cookieModeEnabled()', $body);
    }

    public function testEveryEmitArmRoutesThroughTheClassifySeam(): void
    {
        $source = $this->controllerSource();

        foreach (['signupJson', 'loginJson', 'refreshJson'] as $arm) {
            $body = $this->methodBody($source, $arm)[0];
            self::assertStringContainsString('classifyRequest(', $body, "{$arm} bypasses custody classification");
            self::assertStringContainsString('attachAuthCookies(', $body, "{$arm} never reaches the emit choke point");
        }
    }

    public function testClassifyConsultsAllThreePolicyQuestions(): void
    {
        $body = $this->methodBody($this->controllerSource(), 'classifyRequest')[0];

        self::assertStringContainsString('custodyMode(', $body);
        self::assertStringContainsString('cookieModeEnabled()', $body);
        self::assertStringContainsString('schemeEligible(', $body);
    }

    public function testReadPathFallbackCarriesTheWireNameLiterally(): void
    {
        // The middleware is the OTHER consumer of the session cookie name;
        // pinning it here (literal, not constant) keeps the CP-011 read seam
        // from being silently narrowed back to bearer-only.
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/Http/Middleware/AuthMiddleware.php');
        self::assertStringContainsString('CookiePolicy::SESSION_COOKIE', $source);
        self::assertStringContainsString('getCookie(', $source);
    }

    public function testContainerBindsThePolicySingletonToTheController(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(HttpServicesProvider::class))->getFileName(),
        );

        self::assertStringContainsString('CookiePolicy::class => factory(', $source);
        self::assertStringContainsString('new AuthController($auth, $cookiePolicy)', $source);
        // The default-policy construction stays a single line in src: the
        // controller must never grow a second, unbound CookiePolicy() site.
        $controller = $this->controllerSource();
        self::assertSame(1, substr_count($controller, 'new CookiePolicy()'));
    }

    public function testWireVocabularyNamesAreTheSharedSchemaAndModeWords(): void
    {
        // contracts COOKIE_MODE alignment + shared 2683579 key alignment
        // (read-through only: the hub NEVER re-declares the schema).
        self::assertSame('cookie', CookiePolicy::MODE_COOKIE);
        self::assertSame('bearer', CookiePolicy::MODE_BEARER);

        $providerSource = (string) file_get_contents(
            (string) (new \ReflectionClass(HttpServicesProvider::class))->getFileName(),
        );
        self::assertStringNotContainsString('server-settings.schema', $providerSource);
    }
}
