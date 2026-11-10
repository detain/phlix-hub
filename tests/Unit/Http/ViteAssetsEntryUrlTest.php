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

use function file_get_contents;
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
}
