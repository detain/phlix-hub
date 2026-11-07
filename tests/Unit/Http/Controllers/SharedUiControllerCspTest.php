<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\Controllers\SharedUiController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\TempLoggerCapture;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * R12-03 / R12-05 / R12-02 coverage for the hub SPA shell.
 *
 * Pins the three behaviours this lane shipped on SharedUiController + Response:
 *  1. a per-request `__PHLIX__` nonce that matches the `script-src` nonce in the
 *     shell's Content-Security-Policy (and nothing else weakened),
 *  2. the shell HTML defaulting to `Cache-Control: no-cache`,
 *  3. the `wsUrl` / `wsPath` bootstrap fields, null by default and populated
 *     (and grammar-validated) from `HUB_WS_URL` / `HUB_WS_PATH`.
 *
 * All fixtures build a throwaway `public/` tree (Vite manifest + a real
 * `index.html` shell) so `shell()` reaches its success branch; nothing touches
 * the committed bundle.
 */
final class SharedUiControllerCspTest extends TestCase
{
    use TempLoggerCapture;

    /** @var list<string> temp dirs to prune in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // One case below drives the 503 branch, and that branch now logs through
        // LoggerFactory (2026-11-03 review cure). The factory has to be pointed at
        // scratch space, or the diagnostic would resolve the real config/logger.php
        // and write into the install's .logs during a unit run.
        $this->startLoggerCapture();
    }

    protected function tearDown(): void
    {
        $this->stopLoggerCapture();

        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];

        putenv('HUB_WS_URL');
        putenv('HUB_WS_PATH');
        unset($_ENV['HUB_WS_URL'], $_ENV['HUB_WS_PATH']);

        parent::tearDown();
    }

    /**
     * The committed shell must boot unchanged apart from the injected bootstrap
     * block: module script + mount div survive, and the ONLY inline script is
     * the nonce'd `__PHLIX__` block.
     */
    public function testShellInjectsNonceBootstrapAndStillBoots(): void
    {
        $root = $this->makeShellRoot();
        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(200, $response->statusCode);
        // Original bundle wiring is preserved verbatim.
        self::assertStringContainsString('<script type="module" src="/assets/app/index.js"></script>', $response->body);
        self::assertStringContainsString('<div id="phlix-app"></div>', $response->body);
        // Exactly one inline script, and it is the bootstrap block.
        self::assertSame(1, substr_count($response->body, '<script nonce='));
        self::assertStringContainsString('window.__PHLIX__ =', $response->body);
        // The block rides immediately after <head>.
        self::assertMatchesRegularExpression('/<head>\s*<script nonce="/', $response->body);
    }

    /**
     * The CSP `script-src` nonce and the inline tag's nonce are the same fresh,
     * high-entropy value, and a second request gets a different one.
     */
    public function testCspNonceMatchesInlineTagAndRotatesPerRequest(): void
    {
        $root = $this->makeShellRoot();
        $controller = new SharedUiController($root);

        $first = $controller->shell(new Request(), []);
        $second = $controller->shell(new Request(), []);

        $tagNonce = self::inlineBootstrapNonce($first->body);
        $header = $first->headers['Content-Security-Policy'];

        self::assertStringContainsString("script-src 'self' 'nonce-" . $tagNonce . "'", $header);
        // 16 random bytes → 24 base64 chars, URL-safe enough for a CSP token.
        self::assertSame(24, strlen($tagNonce));

        // Fresh per response: the second shell carries a different nonce in BOTH
        // the tag and the header, and they still agree with each other.
        $secondNonce = self::inlineBootstrapNonce($second->body);
        self::assertNotSame($tagNonce, $secondNonce);
        self::assertStringContainsString("nonce-" . $secondNonce . "'", $second->headers['Content-Security-Policy']);
    }

    /**
     * Byte-shape pin of the full directive set (mirrors the server's shell test,
     * adapted to the hub). Fails loudly if a source expression is loosened.
     */
    public function testCspHeaderIsTheExactPinnedDirectiveSet(): void
    {
        $root = $this->makeShellRoot();
        $response = (new SharedUiController($root))->shell(new Request(), []);

        $nonce = self::inlineBootstrapNonce($response->body);
        $expected = SharedUiController::contentSecurityPolicy($nonce);

        self::assertSame($expected, $response->headers['Content-Security-Policy']);
        // Every relaxation this lane relies on, present and spelled exactly once.
        self::assertStringContainsString("script-src 'self' 'nonce-", $expected);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $expected);
        self::assertStringContainsString("img-src 'self' data: blob:", $expected);
        self::assertStringContainsString("connect-src 'self' ws: wss:", $expected);
        self::assertStringContainsString("media-src 'self' blob:", $expected);
        self::assertStringContainsString("worker-src 'self' blob:", $expected);
        self::assertStringContainsString("frame-ancestors 'none'", $expected);
        // And the load-bearing absences.
        self::assertStringNotContainsString('unsafe-eval', $expected);
        self::assertStringNotContainsString('image.tmdb.org', $expected);
        // script-src must NOT carry 'unsafe-inline' (only style-src may).
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $expected);
    }

    /**
     * The controller sets the CSP itself, so the Response F3 baseline must NOT
     * double-stamp a second CSP — but X-Frame-Options DENY (separate header) is
     * still emitted on the rendered response.
     */
    public function testRenderedResponseHasSingleCspAndKeepsFrameOptions(): void
    {
        $root = $this->makeShellRoot();
        $response = (new SharedUiController($root))->shell(new Request(), []);

        // Only one CSP header value is held, and it is the full policy (not the
        // baseline `frame-ancestors 'none'` alone).
        self::assertStringContainsString("script-src 'self'", $response->headers['Content-Security-Policy']);

        // Render to the wire the HTTP worker emits and inspect the header block.
        $wire = (string) $response->toWorkermanResponse();
        $headerBlock = explode("\r\n\r\n", $wire, 2)[0];

        $cspLines = preg_match_all('/^Content-Security-Policy:/mi', $headerBlock);
        self::assertSame(1, $cspLines, 'shell must emit exactly one CSP header on the wire');
        $expectedLine = 'Content-Security-Policy: ' . $response->headers['Content-Security-Policy'];
        self::assertStringContainsString($expectedLine, $headerBlock);
        self::assertStringContainsString("X-Frame-Options: DENY\r\n", $headerBlock);
    }

    /**
     * R12-05: a successful shell defaults to `Cache-Control: no-cache`.
     */
    public function testShellHtmlDefaultsToNoCache(): void
    {
        $root = $this->makeShellRoot();
        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertArrayHasKey('Cache-Control', $response->headers);
        self::assertSame('no-cache', $response->headers['Cache-Control']);
    }

    /**
     * R12-05 negative arm: an explicit Cache-Control set BEFORE html() survives.
     */
    public function testExplicitCacheControlSetBeforeHtmlIsNotOverwritten(): void
    {
        $response = (new \Phlix\Hub\Http\Response())
            ->header('Cache-Control', 'public, max-age=60')
            ->html('<h1>x</h1>');

        self::assertSame('public, max-age=60', $response->headers['Cache-Control']);
    }

    /**
     * R12-05: a caller that overrides AFTER html() (the OAuth secure() order)
     * still wins — proving the seed is a default, not a clamp.
     */
    public function testExplicitCacheControlSetAfterHtmlOverwritesSeed(): void
    {
        $response = (new \Phlix\Hub\Http\Response())
            ->html('<h1>x</h1>')
            ->header('Cache-Control', 'no-store');

        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    /**
     * Bare html() with no prior Cache-Control gets the seed (Response-level pin,
     * independent of the controller).
     */
    public function testBareHtmlSeedsNoCache(): void
    {
        $response = (new \Phlix\Hub\Http\Response())->html('<p>hi</p>');
        self::assertSame('no-cache', $response->headers['Cache-Control']);
    }

    /**
     * 503 pages: no controller-set CSP header (baseline supplies frame-ancestors
     * at render), and no `__PHLIX__` block. Server parity: error pages stay
     * minimal.
     */
    public function testMissingBundlePageHasNoControllerCspAndNoBootstrap(): void
    {
        // manifest present, but the physical index.html is absent → 503.
        $root = $this->makeShellRoot(writeIndex: false);
        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertArrayNotHasKey('Content-Security-Policy', $response->headers);
        self::assertStringNotContainsString('window.__PHLIX__', $response->body);
    }

    /**
     * HUB-m18 (review hub__fedev): an index.html whose `<head>` carries an
     * attribute does not match the bare-anchor regex. Silently returning the
     * UNSTAMPED shell would boot the SPA with guessed defaults — apiBase
     * inference and the wsUrl/wsPath seam vanish with no operator signal,
     * contradicting the fail-loud posture of every other broken-build branch
     * in the same method. Pin: such a shell must route through unavailable()
     * (503, no bootstrap, cause in the log).
     */
    public function testShellWithAttributedHeadFailsLoudInsteadOfSilentPassThrough(): void
    {
        $root = $this->makeShellRoot(indexHtml: '<!doctype html><html lang="en"><head data-built="v2">'
            . '<title>Hub</title></head><body><div id="phlix-app"></div>'
            . '<script type="module" src="/assets/app/index.js"></script></body></html>');

        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertStringNotContainsString('window.__PHLIX__', $response->body);
        self::assertStringNotContainsString('<script nonce=', $response->body);
        self::assertStringContainsString(
            'shell index.html has no <head> anchor for the bootstrap injection',
            $this->capturedLoggerOutput(),
        );
    }

    /**
     * The same gate must fire when the shell has no `<head>` tag at all
     * (a minified/foreign index sneaking into public/assets/app/).
     */
    public function testShellWithoutAnyHeadTagFailsLoud(): void
    {
        $root = $this->makeShellRoot(indexHtml: '<!doctype html><html><body><div id="phlix-app"></div></body></html>');

        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertStringNotContainsString('window.__PHLIX__', $response->body);
    }

    /**
     * Count-out teeth, not substring guesswork: an uppercase bare `<HEAD>`
     * still matches (the regex keeps its /i arm) and still injects exactly
     * once — the guard counts replacements, so matching shapes must not
     * degrade into 503s.
     */
    public function testUppercaseBareHeadStillInjectsOnce(): void
    {
        $root = $this->makeShellRoot(indexHtml: '<!doctype html><html><HEAD><title>Hub</title></HEAD>'
            . '<body><div id="phlix-app"></div></body></html>');

        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(1, substr_count($response->body, 'window.__PHLIX__ ='));
        // The replacement is a literal "<head>" (not a back-reference), so a
        // matched uppercase tag normalizes to lowercase in the output — a
        // pre-existing, HTML-harmless quirk, pinned here so the HUB-m18 guard
        // is never "fixed" into rejecting its own replacement shape.
        self::assertMatchesRegularExpression('/<head>\s*<script nonce="/', $response->body);
    }

    /**
     * The guard replaces ONE occurrence, so a malformed shell with two bare
     * `<head>` tags gets exactly one bootstrap block — and the surviving
     * second literal must not smuggle a second untrusted script position.
     */
    public function testDoublyMalformedHeadInjectsExactlyOnce(): void
    {
        $root = $this->makeShellRoot(indexHtml: '<!doctype html><html><head><head>'
            . '<title>Hub</title></head><body><div id="phlix-app"></div></body></html>');

        $response = (new SharedUiController($root))->shell(new Request(), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(1, substr_count($response->body, 'window.__PHLIX__ ='));
        self::assertSame(1, substr_count($response->body, '<script nonce='));
    }

    /**
     * The injection is LITERAL-SAFE by construction: `$N` back-reference
     * sequences inside the bootstrap block survive VERBATIM. The old
     * `preg_replace` replacement-string path consumed them — measured on the
     * shipped shape, a literal `$1` in the injected block VANISHED even with
     * no matching capture group (unmatched groups substitute empty), and with
     * a capturing pattern a present group would have been substituted IN.
     * Today's config grammars are `$`-free, so this pins the trap shut for
     * every future field, not just the current payload.
     */
    public function testBootstrapInjectionPreservesDollarSequencesVerbatim(): void
    {
        $block = '<script nonce="QUFCRUNkTm9uY2VWYWx1ZQ==">window.__PHLIX__ = '
            . '{"wsUrl":"wss://relay.example/ws$1${name}x","menu":["$&","$$","$0","$10"]};</script>' . "\n";

        $method = new ReflectionMethod(SharedUiController::class, 'injectBootstrapBlock');
        $method->setAccessible(true);
        $result = $method->invoke(null, '<html><head></head><body></body></html>', $block);

        self::assertIsString($result, 'a well-formed <head> must inject, not fail loud');
        // The whole block, dollar sequences and all, rides verbatim…
        self::assertStringContainsString($block, $result);
        // …belt-and-suspenders on the exact corruption classes the old code
        // exhibited (group-less $1 erasure) and the Perl idioms that must
        // survive as literals.
        self::assertStringContainsString('/ws$1${name}x', $result);
        self::assertStringContainsString('"$&","$$","$0","$10"', $result);

        // Null posture unchanged (the HUB-m18 fail-loud route): no anchor,
        // no injection — and through the seam, not just shell().
        self::assertNull(
            $method->invoke(null, '<html><body></body></html>', $block),
            'without a <head> anchor the seam must yield null so the caller fail-louds'
        );
    }

    /**
     * R12-02 default payload shape: keys present, socket hints null so the
     * client keeps its legacy same-origin derivation.
     */
    public function testBootstrapPayloadShapeDefaultsToNullSocketHints(): void
    {
        $root = $this->makeShellRoot();
        $config = self::phlixConfig((new SharedUiController($root))->shell(new Request(), [])->body);

        self::assertSame('hub', $config['app']);
        self::assertSame('', $config['apiBase']);
        self::assertSame('/app', $config['routerBase']);
        self::assertArrayHasKey('menu', $config);
        self::assertArrayHasKey('extraRoutes', $config);
        self::assertArrayHasKey('features', $config);
        self::assertArrayHasKey('wsUrl', $config);
        self::assertArrayHasKey('wsPath', $config);
        self::assertNull($config['wsUrl']);
        self::assertNull($config['wsPath']);
    }

    /**
     * R12-02: valid env populates the fields verbatim; JSON_HEX flags stay on so
     * a `<`/`>`/`&` in an env value can never break out of the inline script.
     */
    public function testBootstrapPayloadCarriesValidSocketHints(): void
    {
        putenv('HUB_WS_URL=wss://hub.example.com:8804');
        putenv('HUB_WS_PATH=/syncplay');

        $root = $this->makeShellRoot();
        $response = (new SharedUiController($root))->shell(new Request(), []);
        $config = self::phlixConfig($response->body);

        self::assertSame('wss://hub.example.com:8804', $config['wsUrl']);
        self::assertSame('/syncplay', $config['wsPath']);
        // JSON_UNESCAPED_SLASHES keeps the "//" readable in the raw payload.
        self::assertStringContainsString('wss://hub.example.com:8804', $response->body);
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function socketUrlProvider(): array
    {
        return [
            'wss host' => ['wss://hub.example.com', 'wss://hub.example.com'],
            'ws host:port' => ['ws://127.0.0.1:8804', 'ws://127.0.0.1:8804'],
            'wss host:port' => ['wss://relay.internal:8803', 'wss://relay.internal:8803'],
            'surrounding space trimmed' => ['  wss://hub.example.com  ', 'wss://hub.example.com'],
            'reject http scheme' => ['http://hub.example.com', null],
            'reject embedded path' => ['wss://hub.example.com/syncplay', null],
            'reject query' => ['wss://hub.example.com?x=1', null],
            'reject userinfo' => ['wss://user@hub.example.com', null],
            'reject protocol-relative' => ['//hub.example.com', null],
            'reject bare host' => ['hub.example.com', null],
            'reject empty' => ['', null],
            'reject whitespace only' => ['   ', null],
            // REVIEW-FIX: the docblock always promised range rejection; these
            // arms are what make that promise true (grammar-only admitted 99999).
            'accept highest valid port' => ['wss://hub.example.com:65535', 'wss://hub.example.com:65535'],
            'accept lowest valid port' => ['ws://127.0.0.1:1', 'ws://127.0.0.1:1'],
            'reject port zero' => ['wss://hub.example.com:0', null],
            'reject out-of-range port 65536' => ['wss://hub.example.com:65536', null],
            'reject out-of-range port 99999' => ['ws://127.0.0.1:99999', null],
        ];
    }

    /**
     * @dataProvider socketUrlProvider
     */
    public function testConfiguredSocketBaseUrlGrammar(string $env, ?string $expected): void
    {
        putenv('HUB_WS_URL=' . $env);
        self::assertSame($expected, SharedUiController::configuredSocketBaseUrl());
    }

    /**
     * @return array<string,array{0:string,1:?string}>
     */
    public static function socketPathProvider(): array
    {
        return [
            'single segment' => ['/syncplay', '/syncplay'],
            'nested segments' => ['/relay/syncplay', '/relay/syncplay'],
            'trailing slash' => ['/syncplay/', '/syncplay/'],
            'root' => ['/', '/'],
            'reject no leading slash' => ['syncplay', null],
            'reject query' => ['/syncplay?x=1', null],
            'reject fragment' => ['/syncplay#z', null],
            'reject space' => ['/a b', null],
            'reject backslash' => ['/a\\b', null],
            'reject empty' => ['', null],
        ];
    }

    /**
     * @dataProvider socketPathProvider
     */
    public function testConfiguredSocketBasePathGrammar(string $env, ?string $expected): void
    {
        putenv('HUB_WS_PATH=' . $env);
        self::assertSame($expected, SharedUiController::configuredSocketBasePath());
    }

    /**
     * contentSecurityPolicy(): null and empty-string nonces both omit the nonce
     * token; a real nonce is interpolated once.
     */
    public function testContentSecurityPolicyNonceArms(): void
    {
        $noNonce = SharedUiController::contentSecurityPolicy(null);
        $emptyNonce = SharedUiController::contentSecurityPolicy('');

        self::assertStringContainsString("script-src 'self';", $noNonce);
        self::assertStringNotContainsString('nonce-', $noNonce);
        self::assertSame($noNonce, $emptyNonce);

        $withNonce = SharedUiController::contentSecurityPolicy('ABC123');
        self::assertSame(1, substr_count($withNonce, "nonce-ABC123'"));
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Default shell fixture: a bare `<head>` (what Vite emits) so shell()
     * reaches its success branch.
     */
    private const DEFAULT_INDEX_HTML = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Hub</title></head><body><div id="phlix-app"></div>'
        . '<script type="module" src="/assets/app/index.js"></script></body></html>';

    /**
     * Build a throwaway `public/` root with a valid Vite manifest (so the
     * bundle-exists gate passes). When $writeIndex is true, drop a real
     * `index.html` at SHELL_RELATIVE_PATH so shell() returns 200; when false the
     * physical file is missing and shell() falls through to its 503.
     * $indexHtml overrides the shell bytes (attribute-laden `<head>` arms).
     *
     * @return non-empty-string absolute publicRoot path
     */
    private function makeShellRoot(bool $writeIndex = true, ?string $indexHtml = null): string
    {
        $base = realpath(sys_get_temp_dir());
        self::assertNotFalse($base);
        $root = $base . '/hub-csp-' . bin2hex(random_bytes(6));
        $appDir = $root . '/assets/app';

        self::assertTrue(mkdir($appDir . '/.vite', 0o777, true));

        $manifest = json_encode(['src/main.ts' => ['file' => 'assets/app/index.js', 'isEntry' => true]]);
        self::assertIsString($manifest);
        self::assertNotFalse(file_put_contents($appDir . '/.vite/manifest.json', $manifest));

        if ($writeIndex) {
            self::assertNotFalse(file_put_contents($appDir . '/index.html', $indexHtml ?? self::DEFAULT_INDEX_HTML));
        }

        $real = realpath($root);
        self::assertIsString($real);
        $this->tmpDirs[] = $real;

        return $real;
    }

    /**
     * Extract the nonce on the single inline bootstrap script.
     *
     * @return non-empty-string
     */
    private static function inlineBootstrapNonce(string $body): string
    {
        $count = preg_match_all('/<script nonce="([^"]+)">window\.__PHLIX__/', $body, $m);
        self::assertSame(1, $count, 'expected exactly one nonce-stamped bootstrap script');
        /** @var non-empty-string $nonce */
        $nonce = $m[1][0];
        self::assertNotSame('', $nonce);

        return $nonce;
    }

    /**
     * Decode the `window.__PHLIX__ = {...}` object out of the shell body.
     *
     * @return array<string,mixed>
     */
    private static function phlixConfig(string $body): array
    {
        $start = strpos($body, 'window.__PHLIX__ = ');
        self::assertIsInt($start);
        $json = substr($body, $start + strlen('window.__PHLIX__ = '));
        $end = strpos((string) $json, '</script>');
        self::assertIsInt($end);
        $json = rtrim((string) substr($json, 0, $end));

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
