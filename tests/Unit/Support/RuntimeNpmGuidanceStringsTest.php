<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use Phlix\Hub\Http\Controllers\SharedUiController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\ViteAssets;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function scandir;
use function str_replace;
use function str_starts_with;
use function substr_count;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

/**
 * S467 — pin the five runtime guidance strings that tell an operator how to build
 * the hub SPA bundle, so they carry the CI-true incantation and not a bare install.
 *
 * ## Why this test both reads source text and calls the real code
 *
 * The S466 finding is a wrong-signal defect in SHIPPED runtime strings, not in CI
 * wiring. Five sites — the four `503 — Shared UI not built` responses in
 * {@see SharedUiController::shell()} and the `Vite manifest not found` exception in
 * {@see ViteAssets::getEntryJsPath()} — directed `cd web-ui && npm install && npm run
 * build`. A bare install floats resolution against the host `~/.npmrc`; the estate CI
 * law (the `spa-bundle:` gate and this repo's documented build command) is
 * `NPM_CONFIG_USERCONFIG=/dev/null npm ci`. Nothing else in the suite referenced these
 * strings, so a green run proved nothing about them and the wrong instruction could
 * drift back unnoticed.
 *
 * Two independent layers, because each catches what the other cannot:
 *
 *  - the *source* layer counts each command step per file, so it catches the case where
 *    one of the four identical 503 literals is reverted while its three siblings stay
 *    aligned — a single behavioural sample cannot see that;
 *  - the *behavioural* layer drives the real controller through all four `not built`
 *    branches (missing manifest, missing shell, shell outside the root, shell not a
 *    file) and the real exception, asserting the exact concatenated frame on the artefact
 *    that actually leaves the process, so a source that merely LOOKS right on paper cannot
 *    pass (S345 rule 2: assert the real artefact, not your idea of it — and rule 1: every
 *    exit path is exercised, not a representative one);
 *
 * | mutation                                             | this test |
 * | ---------------------------------------------------- | --------- |
 * | any of the five strings reverted to a bare `install` | RED       |
 * | one 503 site edited, a sibling left behind           | RED       |
 * | the `cd web-ui` / `npm run build` frame dropped      | RED       |
 * | the emitted runtime frame altered                    | RED       |
 * | any one of the four 503 branches' frame altered      | RED       |
 * | the survival token moved out of PHP code             | RED       |
 *
 * Scope: the bare `npm install` in {@see SpaBundleCiWiringTest} and
 * {@see McpE2ECiWiringTest} is deliberate workflow-YAML counter-example prose and is
 * not policed here (nor anywhere else in this file).
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class RuntimeNpmGuidanceStringsTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../../src/Http/Controllers/SharedUiController.php';

    private const VITE_ASSETS = __DIR__ . '/../../../src/Http/ViteAssets.php';

    private const CHANGELOG = __DIR__ . '/../../../CHANGELOG.md';

    /** A public root that never exists, so both classes take their "not built" path. */
    private const MISSING_ROOT = __DIR__ . '/s467-hub-missing-public-root';

    private const CD_STEP = 'cd web-ui';

    /** The CI-true install incantation: the neutralised-venue `npm ci`. */
    private const CI_INSTALL = 'NPM_CONFIG_USERCONFIG=/dev/null npm ci';

    private const BUILD_STEP = 'npm run build';

    /** The pre-S467 wrong signal: a bare install that floats resolution off the lockfile. */
    private const LEGACY_INSTALL = 'npm install';

    /** Whole frame the four 503 bodies emit (HTML-entity `&&`, matching the source). */
    private const HTML_FRAME = self::CD_STEP . ' &amp;&amp; ' . self::CI_INSTALL . ' &amp;&amp; ' . self::BUILD_STEP;

    /** Whole frame the Vite exception emits (literal `&&`, matching the source). */
    private const PLAIN_FRAME = self::CD_STEP . ' && ' . self::CI_INSTALL . ' && ' . self::BUILD_STEP;

    /** Number of guidance sites per runtime file. */
    private const CONTROLLER_SITES = 4;

    private const VITE_SITES = 1;

    /**
     * Code-resident survival token for S467. Declared as a PHP string literal so the
     * merge ritual's comment-stripped `--token` corpus (every tracked *.php through
     * php_strip_whitespace) finds it. Zero homes in any markdown.
     */
    public const SURVIVAL_TOKEN = 'S467RUNTIMEPNPX9K8';

    /** @var list<string> ephemeral public-root trees built by a test, removed in tearDown(). */
    private array $baseDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->baseDirs as $baseDir) {
            $this->removeTree($baseDir);
        }
        $this->baseDirs = [];

        parent::tearDown();
    }

    /**
     * Build an ephemeral `public/` whose Vite manifest is present, so {@see SharedUiController::shell()}
     * clears the manifest guard (branch 1) and proceeds to the index.html checks. Returns the public
     * root plus a real file that lives OUTSIDE that root, used to drive the symlink-escape branch.
     *
     * @return array{0: string, 1: string}  [publicRoot, outsideTarget]
     */
    private function makeBuiltRoot(): array
    {
        $base = sys_get_temp_dir() . '/phlix-hub-s467-' . bin2hex(random_bytes(6));
        self::assertTrue(
            mkdir($base . '/pub/assets/app/.vite', 0777, true),
            'the ephemeral public root must be creatable'
        );
        // Canonicalise so a symlinked temp dir cannot confuse the controller's own realpath() checks.
        $base = (string) (realpath($base) ?: $base);
        $publicRoot = $base . '/pub';
        file_put_contents(
            $publicRoot . '/assets/app/.vite/manifest.json',
            '{"src/main.ts":{"file":"assets/app/index.js","isEntry":true}}'
        );
        $outside = $base . '/outside.html';
        file_put_contents($outside, '<html>built</html>');

        $this->baseDirs[] = $base;

        return [$publicRoot, $outside];
    }

    /**
     * Delete a tree created by {@see makeBuiltRoot()} — scoped, by guard, to the system temp area.
     */
    private function removeTree(string $path): void
    {
        if ($path === '' || ! str_starts_with($path, sys_get_temp_dir())) {
            return; // never reach outside the temp area
        }
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);

            return;
        }
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function controllerSource(): string
    {
        self::assertFileExists(self::CONTROLLER, 'the shared-UI controller must exist to be pinned');

        return (string) file_get_contents(self::CONTROLLER);
    }

    private function viteSource(): string
    {
        self::assertFileExists(self::VITE_ASSETS, 'the Vite asset resolver must exist to be pinned');

        return (string) file_get_contents(self::VITE_ASSETS);
    }

    /**
     * @param string $label human name for the file under test
     * @param string $source raw source text of the file
     * @param int    $sites  expected occurrences of each command step
     */
    private function assertEachCommandStepAppearsNTimes(string $label, string $source, int $sites): void
    {
        foreach ([self::CD_STEP, self::CI_INSTALL, self::BUILD_STEP] as $step) {
            self::assertSame(
                $sites,
                substr_count($source, $step),
                $label . ': every guidance site must carry the command step `' . $step . '` exactly '
                . $sites . ' time(s); a mismatch means a site was left behind or partially edited'
            );
        }
    }

    public function testTheControllerSourceCarriesTheCiTrueStepsAtEverySite(): void
    {
        $this->assertEachCommandStepAppearsNTimes(
            'SharedUiController',
            $this->controllerSource(),
            self::CONTROLLER_SITES
        );
    }

    public function testTheViteSourceCarriesTheCiTrueStepsAtItsSite(): void
    {
        $this->assertEachCommandStepAppearsNTimes(
            'ViteAssets',
            $this->viteSource(),
            self::VITE_SITES
        );
    }

    public function testNeitherRuntimeSourceStillInstructsABareNpmInstall(): void
    {
        self::assertSame(
            0,
            substr_count($this->controllerSource(), self::LEGACY_INSTALL),
            'the shared-UI guidance must not direct a bare `npm install` where the CI law is '
            . '`NPM_CONFIG_USERCONFIG=/dev/null npm ci` — the exact S466 finding'
        );
        self::assertSame(
            0,
            substr_count($this->viteSource(), self::LEGACY_INSTALL),
            'the Vite manifest guidance must not direct a bare `npm install`'
        );
    }

    public function testTheRealControllerResponseCarriesTheCiTrueFrame(): void
    {
        // A never-existent root: the manifest guard throws and branch 1 answers 503.
        $this->assertShell503CarriesTheCiTrueFrame(self::MISSING_ROOT, 'missing Vite manifest');
    }

    /**
     * Drive {@see SharedUiController::shell()} at a given public root and assert the artefact
     * it actually returns is a 503 carrying the CI-true frame — reused for every `not built`
     * branch so each of the four identical literals is proven on a real response, not read off
     * the source.
     */
    private function assertShell503CarriesTheCiTrueFrame(string $publicRoot, string $branch): void
    {
        $response = (new SharedUiController($publicRoot))->shell(new Request(), []);

        self::assertSame(503, $response->statusCode, $branch . ': a broken bundle must answer 503');
        self::assertStringContainsString(
            self::HTML_FRAME,
            $response->body,
            $branch . ': the emitted 503 body must contain the whole CI-true frame'
        );
        self::assertStringNotContainsString(
            self::LEGACY_INSTALL,
            $response->body,
            $branch . ': the emitted 503 body must not direct a bare `npm install`'
        );
    }

    public function testTheMissingShellResponseCarriesTheCiTrueFrame(): void
    {
        // Manifest present so branch 1 is cleared; index.html absent so realpath() is false.
        [$publicRoot] = $this->makeBuiltRoot();

        $this->assertShell503CarriesTheCiTrueFrame($publicRoot, 'missing index.html');
    }

    public function testTheEscapedShellResponseCarriesTheCiTrueFrame(): void
    {
        // index.html is a symlink to a file outside the public root → the containment guard fires.
        [$publicRoot, $outside] = $this->makeBuiltRoot();
        self::assertTrue(
            symlink($outside, $publicRoot . '/assets/app/index.html'),
            'the escape symlink must be creatable for this branch'
        );

        $this->assertShell503CarriesTheCiTrueFrame($publicRoot, 'shell resolves outside the public root');
    }

    public function testTheNonFileShellResponseCarriesTheCiTrueFrame(): void
    {
        // index.html is a directory → passes the containment guard but fails the is_file() guard.
        [$publicRoot] = $this->makeBuiltRoot();
        self::assertTrue(
            mkdir($publicRoot . '/assets/app/index.html'),
            'the index.html directory must be creatable for this branch'
        );

        $this->assertShell503CarriesTheCiTrueFrame($publicRoot, 'shell path is a directory, not a file');
    }

    public function testTheRealViteExceptionCarriesTheCiTrueFrame(): void
    {
        $message = null;
        try {
            (new ViteAssets(self::MISSING_ROOT))->getEntryJsPath();
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertNotNull($message, 'a missing Vite manifest must throw');
        self::assertStringContainsString(
            self::PLAIN_FRAME,
            (string) $message,
            'the thrown message must contain the whole CI-true frame as actually emitted'
        );
        self::assertStringNotContainsString(
            self::LEGACY_INSTALL,
            (string) $message,
            'the thrown message must not tell the operator to run a bare `npm install`'
        );
    }

    /**
     * Anti-vacuity (S345 rule 3): the per-step source counts above must actually bite.
     * Reverting the aligned incantation to the wrong signal in the loaded source MUST
     * drop the CI-step count to zero and raise the bare-install count to the site count,
     * so a file that drifted back to `npm install` cannot pass these assertions.
     */
    public function testTheSourceDetectorBitesOnAPlantedRegression(): void
    {
        $this->assertMutationBites(
            'SharedUiController',
            $this->controllerSource(),
            self::CONTROLLER_SITES
        );
        $this->assertMutationBites('ViteAssets', $this->viteSource(), self::VITE_SITES);
    }

    /**
     * @param string $label  human name for the file under test
     * @param string $source raw source text of the file
     * @param int    $sites  expected bare-install count once the CI step is reverted
     */
    private function assertMutationBites(string $label, string $source, int $sites): void
    {
        $mutant = str_replace(self::CI_INSTALL, self::LEGACY_INSTALL, $source);

        self::assertNotSame(
            $source,
            $mutant,
            $label . ': the CI-true incantation must really be present for the mutation to bite'
        );
        self::assertSame(
            0,
            substr_count($mutant, self::CI_INSTALL),
            $label . ': after the planted regression the CI-true step must be entirely gone'
        );
        self::assertSame(
            $sites,
            substr_count($mutant, self::LEGACY_INSTALL),
            $label . ': the planted bare-install mutant must be exactly what the zero-wrong-signal '
            . 'assertion is built to reject'
        );
    }

    public function testTheSurvivalTokenIsCodeResidentAndAbsentFromMarkdown(): void
    {
        // Literal pin: a rename of the token must be a deliberate edit, not silent drift.
        self::assertSame(
            'S467RUNTIMEPNPX9K8',
            self::SURVIVAL_TOKEN,
            "the code-resident survival token literal is the merge ritual's --token search key"
        );

        // Zero homes in markdown: a CHANGELOG copy would recreate the exact string the
        // survival check greps for and defeat it (the S345 lesson this repo records).
        $changelog = (string) file_get_contents(self::CHANGELOG);
        self::assertSame(
            0,
            substr_count($changelog, self::SURVIVAL_TOKEN),
            'the survival token must NOT appear in CHANGELOG.md — a doc copy would defeat the '
            . 'tokenized-corpus survival assertion'
        );
    }
}
