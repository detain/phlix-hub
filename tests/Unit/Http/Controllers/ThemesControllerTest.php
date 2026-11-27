<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\Controllers\ThemesController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_keys;
use function count;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;
use function strpos;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * HUB-S48: `GET /api/v1/themes` serves the three built-ins in the server wire shape.
 *
 * S-tokens-20 (finding phlix-tokens-015): the wire-shape test above only ever checked
 * the controller against ITSELF, so `PAYLOADS` could age away from the `@phlix/tokens`
 * package forever. The tests below pin the served token maps to a vendored palette
 * snapshot (`Fixtures/phlix-tokens-palette.json`, regenerated from the package's
 * committed `dist/tokens.json` at tag time by `Fixtures/dump-phlix-tokens-palette.php`)
 * — the same vendored-fixture discipline `dump-phlix-server-route-manifest.php` / S280
 * established for the route tables. CI clones only phlix-hub, so the package is not
 * readable at test time; fixture currency lives OUTSIDE CI in the regeneration ritual
 * (maintainer run + `--check`), and within CI the pins here go RED on any value, key,
 * order or count drift.
 */
final class ThemesControllerTest extends TestCase
{
    /**
     * The vendored palette snapshot (the package's served surface, as of the last
     * tag-time regeneration).
     */
    private const FIXTURE_PATH = __DIR__ . '/Fixtures/phlix-tokens-palette.json';

    /**
     * Code-resident survival token for this gate: unique, space-free, asserted absent
     * from the vendored data file (it identifies the GATE, not the data), and
     * lockstep-pinned: the `@<pair-count>-<first-8-of-dist-sha256>` suffix must equal
     * the fixture's declared pair_count and its source_dist_sha256 prefix. Bump the
     * suffix in the same commit that regenerates the fixture.
     */
    private const S20_SURVIVAL_TOKEN = 'S20hubgate-palette-fixture-parity@159-b1bf6434';

    /**
     * The served surface, pinned positively so an empty or gutted fixture can never
     * pass: 3 built-ins x 53 token keys = 159 pairs.
     */
    private const EXPECTED_THEME_COUNT = 3;

    private const EXPECTED_TOKENS_PER_THEME = 53;

    private const EXPECTED_PAIR_COUNT = 159;

    public function testIndexServesBuiltInCatalogueInServerWireShape(): void
    {
        $response = (new ThemesController())->index(new Request());

        self::assertSame(200, $response->statusCode);
        /** @var array{themes: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['nocturne', 'daylight', 'midnight'], array_column($body['themes'], 'id'));
        foreach ($body['themes'] as $theme) {
            self::assertSame(['id', 'name', 'dark', 'extends', 'tokens', 'source', 'builtIn'], array_keys($theme));
            self::assertTrue($theme['builtIn']);
            self::assertNull($theme['source']);
            self::assertNull($theme['extends']);
            self::assertIsArray($theme['tokens']);
            self::assertNotSame([], $theme['tokens']);
        }
    }

    /**
     * THE FIXTURE-EQUALITY ARM: every CSS custom-property value the controller serves
     * must equal the vendored package snapshot — value drift, key drift (added/dropped/
     * re-spelled), and serving-order drift all convict. `assertSame` on the maps is the
     * exact compare (=== on arrays covers key set, order and types), never a subset or
     * substring one.
     */
    public function testServedTokenMapsMatchVendoredPackageFixture(): void
    {
        $fixture = self::paletteFixture();

        $response = (new ThemesController())->index(new Request());
        self::assertSame(200, $response->statusCode);
        /** @var array{themes: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $servedIds = array_column($body['themes'], 'id');
        $fixtureIds = array_keys($fixture['themes']);
        self::assertSame(
            $fixtureIds,
            $servedIds,
            sprintf(
                '%s: the hub serves a different theme set or order than the vendored package snapshot '
                . '(served: %s / fixture: %s). Reconcile ThemesController and the fixture in the SAME commit.',
                self::S20_SURVIVAL_TOKEN,
                json_encode($servedIds, JSON_THROW_ON_ERROR),
                json_encode($fixtureIds, JSON_THROW_ON_ERROR),
            ),
        );

        $checkedPairs = 0;
        foreach ($body['themes'] as $theme) {
            $id = $theme['id'];
            if (!is_string($id)) {
                throw new RuntimeException('every served theme must carry a string id');
            }

            $served = $theme['tokens'];
            if (!is_array($served)) {
                throw new RuntimeException(sprintf('served theme %s must carry a token map', $id));
            }

            $expected = $fixture['themes'][$id] ?? null;
            if (!is_array($expected)) {
                throw new RuntimeException(sprintf(
                    '%s: the hub serves theme %s that the vendored fixture does not carry — regenerate '
                    . 'or reconcile in the SAME commit.',
                    self::S20_SURVIVAL_TOKEN,
                    $id,
                ));
            }

            self::assertSame(
                $expected,
                $served,
                sprintf(
                    '%s: theme %s has drifted from the vendored @phlix/tokens palette. Repair ritual: run '
                    . 'Fixtures/dump-phlix-tokens-palette.php --check against the tokens checkout; if the '
                    . 'PACKAGE moved, re-generate the fixture at tag time and correct ThemesController::PAYLOADS '
                    . 'to the regenerated values in the SAME commit (the fixture is authoritative; hand-copies '
                    . 'are the defect class phlix-tokens-015 named).',
                    self::S20_SURVIVAL_TOKEN,
                    $id,
                ),
            );
            $checkedPairs += count($expected);
        }

        self::assertSame(
            self::EXPECTED_PAIR_COUNT,
            $checkedPairs,
            sprintf(
                '%s: the equality arm must actually compare %d key-value pairs (3 themes x 53 keys), got %d '
                . '— a thinner comparison is vacuous by definition.',
                self::S20_SURVIVAL_TOKEN,
                self::EXPECTED_PAIR_COUNT,
                $checkedPairs,
            ),
        );
    }

    /**
     * The vendored snapshot must be non-vacuous and honestly shaped: positive counts
     * stated from BOTH the declared header fields and the entries they describe,
     * full-length provenance stamps, well-formed token values, and an identical key
     * surface across the three themes. The gate's survival token must be code-resident
     * ONLY — its presence in the data file would mean someone vendored the gate
     * instead of the data.
     */
    public function testVendoredPaletteFixtureIsNonVacuousAndCarriesPackageProvenance(): void
    {
        $fixtureBytes = file_get_contents(self::FIXTURE_PATH);
        if (!is_string($fixtureBytes)) {
            throw new RuntimeException(sprintf('cannot read the vendored palette fixture at %s', self::FIXTURE_PATH));
        }

        self::assertFalse(
            strpos($fixtureBytes, self::S20_SURVIVAL_TOKEN),
            sprintf(
                '%s: the survival token must stay code-resident — finding it inside the data file means '
                . 'the fixture stopped being package-derived.',
                self::S20_SURVIVAL_TOKEN,
            ),
        );

        $fixture = self::paletteFixture();

        self::assertSame(
            'detain/phlix-tokens',
            $fixture['source_repo'],
            sprintf('%s: the palette fixture must be stamped as coming from detain/phlix-tokens.', self::S20_SURVIVAL_TOKEN),
        );

        $stamps = [
            'source_sha' => '/^[0-9a-f]{40}$/',
            'source_dist_sha256' => '/^[0-9a-f]{64}$/',
        ];
        foreach ($stamps as $field => $pattern) {
            $value = $field === 'source_sha' ? $fixture['source_sha'] : $fixture['source_dist_sha256'];
            self::assertSame(
                1,
                preg_match($pattern, $value),
                sprintf('%s: fixture provenance field %s must be a full lowercase hex stamp.', self::S20_SURVIVAL_TOKEN, $field),
            );
        }

        self::assertSame(
            self::EXPECTED_THEME_COUNT,
            $fixture['theme_count'],
            sprintf('%s: fixture must declare theme_count %d.', self::S20_SURVIVAL_TOKEN, self::EXPECTED_THEME_COUNT),
        );
        self::assertSame(
            self::EXPECTED_TOKENS_PER_THEME,
            $fixture['token_count'],
            sprintf('%s: fixture must declare token_count %d.', self::S20_SURVIVAL_TOKEN, self::EXPECTED_TOKENS_PER_THEME),
        );
        self::assertSame(
            self::EXPECTED_PAIR_COUNT,
            $fixture['pair_count'],
            sprintf('%s: fixture must declare pair_count %d.', self::S20_SURVIVAL_TOKEN, self::EXPECTED_PAIR_COUNT),
        );

        $keySurface = null;
        $actualPairs = 0;
        foreach ($fixture['themes'] as $themeId => $map) {
            self::assertCount(
                self::EXPECTED_TOKENS_PER_THEME,
                $map,
                sprintf(
                    '%s: fixture theme %s must carry exactly %d token entries.',
                    self::S20_SURVIVAL_TOKEN,
                    $themeId,
                    self::EXPECTED_TOKENS_PER_THEME,
                ),
            );

            $keys = array_keys($map);
            if ($keySurface === null) {
                $keySurface = $keys;
            } else {
                self::assertSame(
                    $keySurface,
                    $keys,
                    sprintf(
                        '%s: fixture themes must carry an identical key surface in identical order (theme %s).',
                        self::S20_SURVIVAL_TOKEN,
                        $themeId,
                    ),
                );
            }

            foreach ($map as $key => $value) {
                self::assertNotSame(
                    '',
                    trim($value),
                    sprintf('%s: fixture value for %s/%s must not be empty.', self::S20_SURVIVAL_TOKEN, $themeId, $key),
                );
            }

            $actualPairs += count($map);
        }

        self::assertSame(
            self::EXPECTED_PAIR_COUNT,
            $actualPairs,
            sprintf('%s: the fixture entries must sum to the %d-pair denominator.', self::S20_SURVIVAL_TOKEN, self::EXPECTED_PAIR_COUNT),
        );
    }

    /**
     * Lockstep pin: the survival token's `@<count>-<sha8>` suffix must match what the
     * fixture declares — regenerating the fixture against new package bytes without
     * bumping the code token goes RED, which is what keeps a re-vendor from being a
     * silent, unexamined act.
     */
    public function testSurvivalTokenSuffixIsLockStepWithTheFixtureProvenance(): void
    {
        $fixture = self::paletteFixture();

        $suffix = [];
        $shaped = preg_match('/@(\d+)-([0-9a-f]{8})$/', self::S20_SURVIVAL_TOKEN, $suffix);
        if ($shaped !== 1) {
            throw new RuntimeException('the survival token must carry an @<pair-count>-<dist-sha256-prefix> suffix.');
        }

        self::assertSame(
            (int) $suffix[1],
            $fixture['pair_count'],
            sprintf(
                '%s: the fixture declares %d pairs but the gate token is pinned to %s — bump the token '
                . 'suffix in the same commit that regenerates the fixture.',
                self::S20_SURVIVAL_TOKEN,
                $fixture['pair_count'],
                $suffix[1],
            ),
        );

        self::assertSame(
            $suffix[2],
            substr($fixture['source_dist_sha256'], 0, 8),
            sprintf(
                '%s: the fixture was regenerated from package dist bytes sha256 %s… but this gate is '
                . 'pinned to %s… — the re-vendor must be examined and the token suffix rotated in the '
                . 'same commit.',
                self::S20_SURVIVAL_TOKEN,
                substr($fixture['source_dist_sha256'], 0, 8),
                $suffix[2],
            ),
        );
    }

    /**
     * Decode the vendored palette fixture into a fully typed shape, failing LOUD
     * (never a skip) on any missing or malformed structure — absence of the fixture
     * is exactly a red. The runtime checks below are the TYPE boundary: every field
     * and every theme map's entries are proven string/int here, so the assertions in
     * the tests carry the GATE semantics, not defensive casts.
     *
     * @return array{
     *     source_repo: string,
     *     source_sha: string,
     *     source_tag: string,
     *     source_dist_sha256: string,
     *     theme_count: int,
     *     token_count: int,
     *     pair_count: int,
     *     themes: array<string, array<string, string>>
     * }
     */
    private static function paletteFixture(): array
    {
        $bytes = file_get_contents(self::FIXTURE_PATH);
        if (!is_string($bytes)) {
            throw new RuntimeException(sprintf(
                'cannot read the vendored palette fixture at %s — regenerate it with '
                . 'tests/Unit/Http/Controllers/Fixtures/dump-phlix-tokens-palette.php',
                self::FIXTURE_PATH,
            ));
        }

        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('the palette fixture at %s did not decode to an object', self::FIXTURE_PATH));
        }

        $sourceRepo = $decoded['source_repo'] ?? null;
        $sourceSha = $decoded['source_sha'] ?? null;
        $sourceTag = $decoded['source_tag'] ?? null;
        $sourceDistSha = $decoded['source_dist_sha256'] ?? null;
        $themeCount = $decoded['theme_count'] ?? null;
        $tokenCount = $decoded['token_count'] ?? null;
        $pairCount = $decoded['pair_count'] ?? null;
        if (
            !is_string($sourceRepo)
            || !is_string($sourceSha)
            || !is_string($sourceTag)
            || !is_string($sourceDistSha)
            || !is_int($themeCount)
            || !is_int($tokenCount)
            || !is_int($pairCount)
        ) {
            throw new RuntimeException('the palette fixture is missing or mistyping a provenance header field');
        }

        if (!isset($decoded['themes']) || !is_array($decoded['themes'])) {
            throw new RuntimeException('the palette fixture themes field is not a map');
        }

        $themes = [];
        foreach ($decoded['themes'] as $themeId => $map) {
            if (!is_string($themeId) || !is_array($map)) {
                throw new RuntimeException('the palette fixture theme map is malformed');
            }

            $entries = [];
            foreach ($map as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new RuntimeException(sprintf('the palette fixture entry %s/%s is malformed', $themeId, (string) $key));
                }

                $entries[$key] = $value;
            }

            $themes[$themeId] = $entries;
        }

        return [
            'source_repo' => $sourceRepo,
            'source_sha' => $sourceSha,
            'source_tag' => $sourceTag,
            'source_dist_sha256' => $sourceDistSha,
            'theme_count' => $themeCount,
            'token_count' => $tokenCount,
            'pair_count' => $pairCount,
            'themes' => $themes,
        ];
    }
}
