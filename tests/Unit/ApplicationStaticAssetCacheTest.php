<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use Phlix\Hub\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for {@see Application}'s static-asset caching: the hashed-immutable
 * vs. short-max-age+ETag header decision, the conditional-GET (304) handling,
 * and the per-worker realpath/stat memo that keeps blocking syscalls off the
 * event loop.
 */
final class ApplicationStaticAssetCacheTest extends TestCase
{
    private const string CSS_MIME = 'text/css; charset=utf-8';

    /** A real committed non-hashed asset (JSON, revalidating shape). */
    private const string NON_HASHED_ASSET = '/assets/app/.vite/manifest.json';

    private function etagFor(int $mtime, int $size): string
    {
        return sprintf('"%x-%x"', $mtime, $size);
    }

    // ---- header presence ---------------------------------------------------

    public function testNonHashedAssetCarriesEtagAndShortCacheControl(): void
    {
        $mtime = 1_700_000_000;
        $size = 4096;
        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            $size,
            null,
            null,
        );

        $this->assertSame(200, $decision['status']);
        $this->assertSame('public, max-age=86400', $decision['headers']['Cache-Control']);
        $this->assertSame($this->etagFor($mtime, $size), $decision['headers']['ETag']);
        $this->assertArrayHasKey('Last-Modified', $decision['headers']);
        $this->assertSame(self::CSS_MIME, $decision['headers']['Content-Type']);
        // Non-hashed assets must be revalidatable, so NOT immutable.
        $this->assertStringNotContainsString('immutable', $decision['headers']['Cache-Control']);
    }

    public function testHashedAssetCarriesImmutableLongMaxAgeAndNoEtag(): void
    {
        $decision = Application::computeStaticCacheDecision(
            'application/javascript; charset=utf-8',
            true,
            1_700_000_000,
            512,
            null,
            null,
        );

        $this->assertSame(200, $decision['status']);
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $decision['headers']['Cache-Control'],
        );
        // Immutable assets need no revalidation validators.
        $this->assertArrayNotHasKey('ETag', $decision['headers']);
        $this->assertArrayNotHasKey('Last-Modified', $decision['headers']);
    }

    public function testHashedAssetIgnoresConditionalHeadersAndStays200(): void
    {
        // Even if a client sends If-None-Match, an immutable asset is served 200
        // (the browser never revalidates it, so no ETag / 304 machinery).
        $decision = Application::computeStaticCacheDecision(
            'application/javascript; charset=utf-8',
            true,
            1_700_000_000,
            512,
            '"anything"',
            null,
        );

        $this->assertSame(200, $decision['status']);
        $this->assertArrayNotHasKey('ETag', $decision['headers']);
    }

    // ---- conditional GET / 304 --------------------------------------------

    public function testMatchingIfNoneMatchYields304WithValidatorsNoBody(): void
    {
        $mtime = 1_700_000_000;
        $size = 4096;
        $etag = $this->etagFor($mtime, $size);

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            $size,
            $etag,
            null,
        );

        $this->assertSame(304, $decision['status']);
        $this->assertSame($etag, $decision['headers']['ETag']);
        $this->assertSame('public, max-age=86400', $decision['headers']['Cache-Control']);
        // A 304 must not describe a body payload.
        $this->assertArrayNotHasKey('Content-Type', $decision['headers']);
    }

    public function testNonMatchingIfNoneMatchYields200WithBodyAndEtag(): void
    {
        $mtime = 1_700_000_000;
        $size = 4096;

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            $size,
            '"stale-etag"',
            null,
        );

        $this->assertSame(200, $decision['status']);
        $this->assertSame($this->etagFor($mtime, $size), $decision['headers']['ETag']);
        $this->assertSame(self::CSS_MIME, $decision['headers']['Content-Type']);
    }

    public function testWeakIfNoneMatchMatchesStrongEtag(): void
    {
        $mtime = 1_700_000_000;
        $size = 4096;
        $weak = 'W/' . $this->etagFor($mtime, $size);

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            $size,
            $weak,
            null,
        );

        $this->assertSame(304, $decision['status']);
    }

    public function testStarIfNoneMatchYields304(): void
    {
        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            1_700_000_000,
            4096,
            '*',
            null,
        );

        $this->assertSame(304, $decision['status']);
    }

    public function testIfNoneMatchListContainingEtagYields304(): void
    {
        $mtime = 1_700_000_000;
        $size = 4096;
        $list = '"other", ' . $this->etagFor($mtime, $size) . ', "third"';

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            $size,
            $list,
            null,
        );

        $this->assertSame(304, $decision['status']);
    }

    public function testIfModifiedSinceNotOlderThanMtimeYields304(): void
    {
        $mtime = 1_700_000_000;
        $ims = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            4096,
            null,
            $ims,
        );

        $this->assertSame(304, $decision['status']);
    }

    public function testIfModifiedSinceOlderThanMtimeYields200(): void
    {
        $mtime = 1_700_000_000;
        $ims = gmdate('D, d M Y H:i:s', $mtime - 3600) . ' GMT';

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            4096,
            null,
            $ims,
        );

        $this->assertSame(200, $decision['status']);
        $this->assertArrayHasKey('Content-Type', $decision['headers']);
    }

    public function testIfNoneMatchTakesPrecedenceOverIfModifiedSince(): void
    {
        // Non-matching If-None-Match wins even though If-Modified-Since would 304.
        $mtime = 1_700_000_000;
        $ims = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $decision = Application::computeStaticCacheDecision(
            self::CSS_MIME,
            false,
            $mtime,
            4096,
            '"stale-etag"',
            $ims,
        );

        $this->assertSame(200, $decision['status']);
    }

    // ---- realpath/stat memo ------------------------------------------------

    /**
     * @return array{real: string, mtime: int, size: int}|false
     */
    private function invokeStaticFileMemo(string $candidate): array|false
    {
        $method = new ReflectionMethod(Application::class, 'getStaticFileMemo');
        $method->setAccessible(true);

        /** @var array{real: string, mtime: int, size: int}|false $result */
        $result = $method->invoke(null, $candidate);

        return $result;
    }

    public function testStatMemoResolvesRealMtimeAndSize(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_asset_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, str_repeat('a', 123));

        $file = $this->invokeStaticFileMemo($tmp);

        $this->assertIsArray($file);
        $this->assertSame(realpath($tmp), $file['real']);
        $this->assertSame(123, $file['size']);
        $this->assertSame((int) filemtime($tmp), $file['mtime']);

        unlink($tmp);
    }

    public function testStatMemoHitDoesNotRestatAfterFileRemoved(): void
    {
        // A second lookup for the same path must return the memoized stat even
        // after the file is deleted — proving no re-stat/re-realpath syscall.
        $tmp = tempnam(sys_get_temp_dir(), 'phlix_asset_');
        $this->assertIsString($tmp);
        file_put_contents($tmp, str_repeat('b', 77));

        $first = $this->invokeStaticFileMemo($tmp);
        $this->assertIsArray($first);

        unlink($tmp);

        $second = $this->invokeStaticFileMemo($tmp);
        $this->assertIsArray($second);
        $this->assertSame($first, $second);
        $this->assertSame(77, $second['size']);
    }

    public function testStatMemoReturnsFalseForMissingPath(): void
    {
        $missing = sys_get_temp_dir() . '/phlix_asset_does_not_exist_' . uniqid('', true);

        $this->assertFalse($this->invokeStaticFileMemo($missing));
    }

    // ---- R20-03 static deny list ------------------------------------------

    public function testDeniedStaticPathRefusesRawPhpAndSourceMaps(): void
    {
        $this->assertTrue(Application::isDeniedStaticPath('/public/index.php'));
        $this->assertTrue(Application::isDeniedStaticPath('/public/TOOLS.PHP'));
        // pathinfo takes the LAST dot: the shipped SPA maps are `.js.map`.
        $this->assertTrue(
            Application::isDeniedStaticPath('/public/assets/app/assets/users-CNniaPj9-BfHRV-eJ.js.map'),
        );
        $this->assertTrue(Application::isDeniedStaticPath('/public/views/page.tpl'));
    }

    public function testDeniedStaticPathKeepsServingRealAssets(): void
    {
        $this->assertFalse(Application::isDeniedStaticPath('/public/assets/app/assets/app-abc123.js'));
        $this->assertFalse(Application::isDeniedStaticPath('/public/assets/app/styles.css'));
        $this->assertFalse(Application::isDeniedStaticPath('/public/index.html'));
        $this->assertFalse(Application::isDeniedStaticPath('/public/favicon.ico'));
        // Extension decision, not substring: lookalikes must stay servable.
        $this->assertFalse(Application::isDeniedStaticPath('/public/sitemap.xml'));
        $this->assertFalse(Application::isDeniedStaticPath('/public/tool.phpx'));
        $this->assertFalse(Application::isDeniedStaticPath('/public/map'));
    }

    public function testDeniedStaticExtensionSetIsTheCoordinatedEstateList(): void
    {
        // Byte-coordinated with phlix-server HttpHandler::DENIED_STATIC_EXTENSIONS
        // (85c06de1). Changing one side without the other splits the estate's
        // static-exposure posture — this pin makes that loud.
        $constant = (new ReflectionMethod(Application::class, 'isDeniedStaticPath'))
            ->getDeclaringClass()
            ->getConstant('DENIED_STATIC_EXTENSIONS');

        $this->assertSame(['php', 'map', 'tpl'], $constant);
    }

    // ---- SH-071: hashed-asset detection over the real Vite bundle ----------

    /**
     * The legacy detector (`.hex6-12.`) never fired on a single committed
     * chunk: Vite hashes use mixed-case base64url segments glued with a DASH
     * (`Foo-DeZOGC0--DAyU1OvF.js`), so every immutable candidate was served
     * with the short-max-age revalidating shape. The detector must therefore
     * keep the legacy branch (additive) and learn the dash-hashed shape.
     */
    public function testIsHashedAssetPathMatchesRealViteChunkNames(): void
    {
        // Real committed names (census test below enforces all of them).
        $hashed = [
            '/assets/app/assets/AdminLayout-DeZOGC0--DAyU1OvF.js',
            '/assets/app/assets/CommandPalette-MAUsLYzl-Bj_6TB9X.js',
            '/assets/app/assets/jetbrains-mono-var-latin-6fWv1k7M.woff2',
            // Generic Vite-era shapes.
            '/assets/app/assets/index-abcdefgh.js',
            '/assets/app/assets/style-ZYXWVUTS.css',
            '/assets/app/assets/x-Ab3Cd5Ef.mjs',
            // Legacy dotted-hex branch stays honoured (purely additive).
            '/assets/app/app.abc123def456.js',
        ];
        foreach ($hashed as $path) {
            $this->assertTrue(Application::isHashedAssetPath($path), $path);
        }

        $plain = [
            '/assets/app/index.html',
            '/assets/app/.vite/manifest.json',
            // Maps end in .map — pathinfo extension is not in the set.
            '/assets/app/assets/AdminLayout-DeZOGC0--DAyU1OvF.js.map',
            // No dash segment before the extension at all.
            '/assets/app/logo.png',
            // Final segment run is 10 chars — longer than a Vite hash; the
            // exact {7,8} boundary must refuse (disclosed in the docblock).
            '/assets/app/vendor-library-abcdefghij.js',
        ];
        foreach ($plain as $path) {
            $this->assertFalse(Application::isHashedAssetPath($path), $path);
        }
    }

    public function testHashedAssetPathCensusOverCommittedBundle(): void
    {
        $publicRoot = dirname(__DIR__, 2) . '/public';
        $assetsDir = $publicRoot . '/assets/app/assets';
        $hashedExtensions = ['js', 'mjs', 'css', 'woff', 'woff2', 'ttf', 'otf'];

        $walked = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $extension = strtolower($entry->getExtension());
            if ($extension === 'map') {
                $path = substr($entry->getPathname(), strlen($publicRoot));
                $this->assertFalse(
                    Application::isHashedAssetPath($path),
                    "source map must stay revalidatable: $path",
                );
                continue;
            }
            if (!in_array($extension, $hashedExtensions, true)) {
                continue;
            }
            $path = substr($entry->getPathname(), strlen($publicRoot));
            $walked++;
            $this->assertTrue(
                Application::isHashedAssetPath($path),
                "hashed Vite output must classify immutable: $path",
            );
        }

        // Zero walked files would make the loop vacuous — the bundle ships
        // dozens of chunks; a floor keeps the census honest across eras.
        $this->assertGreaterThan(20, $walked);
        $this->assertFalse(Application::isHashedAssetPath('/assets/app/index.html'));
    }

    // ---- SH-071: fast-path method disposition (HEAD/GET) -------------------

    public function testStaticFastPathMethodDisposition(): void
    {
        $this->assertSame('body', Application::staticFastPathMethodDisposition('GET'));
        $this->assertSame('head', Application::staticFastPathMethodDisposition('HEAD'));
        // Case tolerance at the seam (clients are not always canonical).
        $this->assertSame('body', Application::staticFastPathMethodDisposition('get'));
        $this->assertSame('head', Application::staticFastPathMethodDisposition('Head'));
        // Everything else falls through to the router (the hub's documented
        // no-verb-oracle posture answers unknown paths 404, never 405).
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertNull(Application::staticFastPathMethodDisposition($method), $method);
        }
    }

    /**
     * @return array{real: string, mtime: int, size: int}
     */
    private function staticFileShape(string $relative): array
    {
        $real = dirname(__DIR__, 2) . '/public' . $relative;
        $size = filesize($real);
        $mtime = filemtime($real);
        $this->assertIsInt($size);
        $this->assertIsInt($mtime);

        return ['real' => $real, 'mtime' => $mtime, 'size' => $size];
    }

    public function testFaviconIsServedByTheStaticFastPathWithIconContentType(): void
    {
        $real = dirname(__DIR__, 2) . '/public/favicon.ico';
        $this->assertFileExists($real);
        $this->assertFalse(Application::isDeniedStaticPath($real));
        $this->assertFalse(Application::isStaticHtmlDenial($real));
        $size = filesize($real);
        $mtime = filemtime($real);
        $this->assertIsInt($size);
        $this->assertIsInt($mtime);
        $wire = (string) Application::buildStaticFastPathResponse(
            'body',
            '/favicon.ico',
            ['real' => (string) realpath($real), 'mtime' => $mtime, 'size' => $size],
            null,
            null,
        );
        $this->assertStringContainsString('HTTP/1.1 200 OK', $wire);
        $this->assertStringContainsString("Content-Type: image/x-icon\r\n", $wire);
    }

    public function testHeadStaticReplyIsBodylessWithAuthoritativeContentLength(): void
    {
        $file = $this->staticFileShape(self::NON_HASHED_ASSET);
        $response = Application::buildStaticFastPathResponse('head', self::NON_HASHED_ASSET, $file, null, null);
        $wire = (string) $response;

        // Exact terminator: the head ends and NO entity bytes follow.
        $this->assertStringEndsWith("\r\n\r\n", $wire);
        $this->assertStringNotContainsString('_Button-Dfl4y6vO-B2IjqPMe.js', $wire);
        // The GET-equivalent length is advertised, per RFC 9110 §9.3.2.
        $this->assertStringContainsString('HTTP/1.1 200 OK', $wire);
        $this->assertStringContainsString("Content-Length: {$file['size']}\r\n", $wire);
        $this->assertStringContainsString("Content-Type: application/json; charset=utf-8\r\n", $wire);
        $this->assertStringContainsString("X-Content-Type-Options: nosniff\r\n", $wire);
        $this->assertStringContainsString('Cache-Control: public, max-age=86400', $wire);
        $this->assertStringContainsString('ETag: ', $wire);
    }

    public function testGetOfNonHashedAssetKeepsLegacyByteShape(): void
    {
        $file = $this->staticFileShape(self::NON_HASHED_ASSET);
        $response = Application::buildStaticFastPathResponse('body', self::NON_HASHED_ASSET, $file, null, null);
        $wire = (string) $response;

        // File-backed reply: the head is the whole rendering; Http::encode
        // appends `Content-Length` (from filesize at send time) and
        // `Accept-Ranges: bytes` and streams the entity. The pin therefore
        // covers the full decision-header set plus the file pointer — the
        // entity bytes are the file itself, unchanged (byte-identity by
        // construction: offset 0, full length, no body rewriting).
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $wire);
        $this->assertStringContainsString('Cache-Control: public, max-age=86400', $wire);
        $this->assertStringContainsString(sprintf('ETag: "%x-%x"', $file['mtime'], $file['size']), $wire);
        $this->assertStringContainsString('Connection: keep-alive', $wire);
        $this->assertStringContainsString('Content-Type: application/json; charset=utf-8', $wire);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $wire);
        $this->assertSame(
            ['file' => $file['real'], 'offset' => 0, 'length' => 0],
            $response->file,
        );
    }

    public function testGetOfHashedChunkCarriesImmutableYear(): void
    {
        // Resolve the live committed chunk by prefix: the content hash rotates on every
        // /app regen, so a literal name would rot at each ui re-vendor.
        $found = glob(dirname(__DIR__, 2) . '/public/assets/app/assets/AdminLayout-*.js');
        $this->assertNotEmpty($found, 'committed /app bundle carries an AdminLayout chunk');
        $relative = '/assets/app/assets/' . basename($found[0]);
        $file = $this->staticFileShape($relative);
        $response = Application::buildStaticFastPathResponse('body', $relative, $file, null, null);
        $wire = (string) $response;

        $this->assertStringContainsString('Cache-Control: public, max-age=31536000, immutable', $wire);
        $this->assertStringNotContainsString('ETag:', $wire);
        $this->assertStringContainsString('Content-Type: application/javascript', $wire);
    }

    public function testConditionalGetStillAnswers304OnNonHashedAsset(): void
    {
        $file = $this->staticFileShape(self::NON_HASHED_ASSET);
        $etag = sprintf('"%x-%x"', $file['mtime'], $file['size']);
        $response = Application::buildStaticFastPathResponse('body', self::NON_HASHED_ASSET, $file, $etag, null);
        $wire = (string) $response;

        $this->assertStringStartsWith('HTTP/1.1 304 Not Modified', $wire);
        $this->assertStringNotContainsString("\r\n\r\n\r\n", $wire);
        $this->assertStringEndsWith("\r\n\r\n", $wire);
    }

    // ---- SH-071: raw SPA shell denial (hub-only gate) ----------------------

    /**
     * The coordinated estate DENIED list stays ['php','map','tpl'] (server
     * twin — do not extend). The SPA shell is a HUB-only concern: raw
     * /assets/app/index.html bypassed the nonce/CSP-configured shell()
     * emitter. A separate gate keeps the coordinated list untouched.
     */
    public function testStaticHtmlDenialRefusesShellAndKeepsRealAssets(): void
    {
        $this->assertTrue(Application::isStaticHtmlDenial('/srv/hub/public/assets/app/index.html'));
        $this->assertTrue(Application::isStaticHtmlDenial('/x/Index.HTM'));
        $this->assertTrue(Application::isStaticHtmlDenial('/x/page.htm'));
        $this->assertFalse(Application::isStaticHtmlDenial('/x/App-Bj_6TB9X.js'));
        $this->assertFalse(Application::isStaticHtmlDenial('/x/manifest.json'));
        $this->assertFalse(Application::isStaticHtmlDenial('/x/noext'));
    }

    /**
     * The gates are unit-pinned at their seams, but the fast path lives in an
     * onMessage closure that no HTTP-level unit test drives — a dropped arm
     * there would be silent. This source pin keeps the closure's wiring to
     * the extracted gates load-bearing (SH-071 convictions M-A/M-B/M-C):
     * every conjunct of the admission condition is required verbatim, and a
     * reformat that preserves the law trips the pin so the reviewer can see
     * exactly which arm moved.
     */
    public function testFastPathClosureWiresTheExtractedGates(): void
    {
        $location = (new \ReflectionClass(Application::class))->getFileName();
        if (!is_string($location)) {
            $this->fail('Application must be loaded from a file for the source pin.');
        }
        $source = file_get_contents($location);
        if (!is_string($source)) {
            $this->fail('Could not read the Application source for the pin.');
        }

        $required = [
            '$fastPathMethod = self::staticFastPathMethodDisposition($request->method());',
            '$fastPathMethod !== null',
            '&& !self::isDeniedStaticPath($file[\'real\'])',
            '&& !self::isStaticHtmlDenial($file[\'real\'])',
            '$resp = self::buildStaticFastPathResponse(',
        ];
        foreach ($required as $needle) {
            $this->assertSame(
                1,
                substr_count($source, $needle),
                "fast-path arm missing or duplicated: $needle",
            );
        }
        // The old inline classifier must not survive beside the extracted one.
        $this->assertStringNotContainsString('$isHashedAsset = (bool) preg_match(', $source);
    }
}
