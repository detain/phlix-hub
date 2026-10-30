<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * M-HUB-10 — the served hub portal carries a real browser floor.
 *
 * The review's finding: the SPA's mount point was an empty div with no build
 * target, so on a browser below Vite's transpile floor the portal rendered a
 * blank white page and never said why. The cure has two halves, pinned here
 * from the COMMITTED artifacts (the bundle gate S253 keeps them honest):
 *
 *  1. `web-ui/vite.config.ts` sets `build.target: 'baseline-widely-available'`
 *     explicitly — the floor is a declared decision, not a default that can
 *     drift under a future Vite major;
 *  2. BOTH index.html files (the web-ui template and the committed served
 *     bundle) carry a boot placeholder inside the mount div plus a
 *     `<noscript>` line, naming the supported floor in human words. Vue's
 *     mount replaces the placeholder the instant the app runs; a browser that
 *     cannot run it now sees WHY instead of a blank page.
 *
 * Reading files, not booting a browser: this is the estate's source-scan
 * idiom (HttpRequestBodyCapTest, the phpcs corpus gate). The determinism
 * build-x2 posture of the S253 gate keeps the committed served copy equal to
 * what the config actually emits.
 */
final class SpaBrowserFloorTest extends TestCase
{
    public function testTheViteConfigDeclaresTheWidelyAvailableBuildFloor(): void
    {
        $config = self::read('web-ui/vite.config.ts');

        self::assertStringContainsString(
            "target: 'baseline-widely-available'",
            $config,
            'the SPA build target must be declared explicitly (M-HUB-10) — '
            . 'an omitted target is a Vite default, and defaults move on majors',
        );
    }

    public function testTheTemplateCarriesTheBootPlaceholderAndNoscript(): void
    {
        $template = self::read('web-ui/index.html');

        self::assertStringContainsString('<noscript>', $template);
        self::assertStringContainsString('support floor', $template);
        self::assertStringContainsString('id="phlix-app"', $template);
    }

    public function testTheCommittedServedBundleCarriesTheSameFloor(): void
    {
        $served = self::read('public/assets/app/index.html');

        self::assertStringContainsString(
            '<noscript>',
            $served,
            'the SERVED portal must state the floor — the template alone is '
            . 'the defect class this item records (blank page, no explanation)',
        );
        self::assertStringContainsString('support floor', $served);
    }

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $body = file_get_contents($path);

        self::assertIsString($body, "committed artifact {$relative} must exist to be pinned");

        return $body;
    }
}
