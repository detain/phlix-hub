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
 *
 * @covers \Phlix\Hub\Application
 */
final class ApplicationStaticAssetCacheTest extends TestCase
{
    private const string CSS_MIME = 'text/css; charset=utf-8';

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
}
