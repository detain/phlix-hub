<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\ViteAssets;
use PHPUnit\Framework\TestCase;

use function exec;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function file_exists;
use function json_decode;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Pins the ViteAssets entry-URL contract against the COMMITTED hub bundle.
 *
 * The method used to be getEntryJsPath() and returned '/' . $entry['file'].
 * Manifest `file` values are BASE-RELATIVE ('assets/index-X.js') and the
 * build is mounted under vite base '/assets/app/', so that string was
 * neither a filesystem path under public/ nor a fetchable URL — it 404'd in
 * every direction (only the sole caller's manifest-existence probe kept it
 * from being felt; the wrong value was discarded).
 *
 * These assertions run against public/assets/app/.vite/manifest.json as
 * committed — the same bytes the daemon reads — so they redden on any future
 * base/file drift, on the '/'-prefix regression class, and on the method
 * name disappearing again.
 */
final class ViteAssetsEntryUrlTest extends TestCase
{
    private const PUBLIC_ROOT = __DIR__ . '/../../../public';

    /** @var array<string, array{file: string, isEntry?: bool}> */
    private array $manifest;

    private ViteAssets $assets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = new ViteAssets(self::PUBLIC_ROOT);

        $raw = file_get_contents(self::PUBLIC_ROOT . '/assets/app/.vite/manifest.json');
        self::assertIsString($raw, 'the committed Vite manifest must be readable by the pin');

        /** @var array<string, array{file: string, isEntry?: bool}> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->manifest = $decoded;
    }

    public function testTheEntryUrlResolvesToAnExistingCommittedAsset(): void
    {
        $url = $this->assets->getEntryJsUrl();

        self::assertTrue(
            file_exists(self::PUBLIC_ROOT . $url),
            "the composed entry URL must map 1:1 onto a committed file under the "
            . "public root — that mapping is the whole point of the rename; got {$url}"
        );
    }

    public function testTheEntryUrlIsExactlyBasePlusManifestFile(): void
    {
        $entries = [];
        foreach ($this->manifest as $entry) {
            if (($entry['isEntry'] ?? false) === true && isset($entry['file'])) {
                $entries[] = $entry['file'];
            }
        }
        self::assertNotEmpty($entries, 'the committed manifest must carry an entry chunk');

        $url = $this->assets->getEntryJsUrl();
        self::assertTrue(
            str_starts_with($url, '/assets/app/'),
            "the entry URL must be served under the vite base '/assets/app/'; got {$url}"
        );

        $suffix = substr($url, strlen('/assets/app/'));
        self::assertContains(
            $suffix,
            $entries,
            "the part after the base must be the manifest's own file value (no '/' "
            . 'prefix resurrection, no second guess at the base); got ' . $suffix
        );
    }
    /**
     * Write a one-entry manifest under a scratch public root.
     */
    private function scratchRoot(string $entryFile): string
    {
        $root = sys_get_temp_dir() . '/phlix-hub-vite-' . uniqid('', true);
        mkdir($root . '/assets/app/.vite', 0700, true);
        $this->writeManifest($root, $entryFile);

        return $root;
    }

    private function writeManifest(string $root, string $entryFile): void
    {
        file_put_contents(
            $root . '/assets/app/.vite/manifest.json',
            json_encode(['src/main.ts' => ['file' => $entryFile, 'isEntry' => true]], JSON_THROW_ON_ERROR),
        );
    }

    private function prune(string $root): void
    {
        @unlink($root . '/assets/app/.vite/manifest.json');
        @rmdir($root . '/assets/app/.vite');
        @rmdir($root . '/assets/app');
        @rmdir($root . '/assets');
        @rmdir($root);
    }

    public function testMemoSelfHealsWhenTheManifestIsRebuilt(): void
    {
        $root = $this->scratchRoot('assets/index-AAAA.js');
        try {
            $assets = new ViteAssets($root);
            self::assertSame('/assets/app/assets/index-AAAA.js', $assets->getEntryJsUrl());
            // Memo hit: unchanged manifest answers from the memo (a second
            // instance, sharing the static memo, like a worker's next request).
            self::assertSame('/assets/app/assets/index-AAAA.js', (new ViteAssets($root))->getEntryJsUrl());

            // Rebuild with a different size (the memo key is mtime+size, and
            // mtime alone can collide inside one second).
            $this->writeManifest($root, 'assets/index-BBBBBBBBBB.js');
            // A brand-new instance shares the static memo, like a worker's next request.
            self::assertSame(
                '/assets/app/assets/index-BBBBBBBBBB.js',
                (new ViteAssets($root))->getEntryJsUrl(),
                'a rebuilt manifest must replace the memoised entry URL',
            );
        } finally {
            $this->prune($root);
        }
    }

    public function testRemovedManifestIsReportedEvenAfterAMemoisedRead(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() is disabled; cannot remove the manifest outside this process.');
        }
        $root = $this->scratchRoot('assets/index-CCCC.js');
        try {
            $assets = new ViteAssets($root);
            $assets->getEntryJsUrl();
            $path = $root . '/assets/app/.vite/manifest.json';
            self::assertTrue(file_exists($path)); // last stat'd path: primes a positive cache entry
            exec('rm -f ' . escapeshellarg($path));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Vite manifest not found');
            $assets->getEntryJsUrl();
        } finally {
            $this->prune($root);
        }
    }
}
