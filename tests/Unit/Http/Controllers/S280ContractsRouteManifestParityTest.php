<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_keys;
use function array_map;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function ltrim;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function var_export;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * S280 — hub gate pinning the live router dump to the canonical contracts route manifest.
 *
 * The hub keeps TWO independent derivations of phlix-server's route table:
 *
 *  - `Fixtures/phlix-server-route-manifest.json` — the S332 snapshot, booted
 *    from the server's two production registrars in-process by
 *    `Fixtures/dump-phlix-server-route-manifest.php`;
 *  - `Fixtures/contracts-server-route-manifest.json` — the vendored
 *    `@phlix/contracts` export (`dist/server-route-manifest.json`), the same
 *    bytes mobile (#52) and roku (#49) vendored: the canonical UNION of the
 *    Application (364) and WebPortal (47) wire-path guard sets minus the 11
 *    shared tuples = 400 tuples.
 *
 * Before this gate the two copies could drift INDEPENDENTLY: the hub could
 * refresh its own snapshot while the vendored contracts copy aged (or the
 * reverse), and nothing in the hub's CI would notice — the two-stale-copies
 * escape. This file kills that escape by pinning the copies against EACH
 * OTHER: the same `source_sha`, tuple-exact identical route sets, mutually
 * stated counts, and identical per-module group counts. Every comparison is
 * EXACT (sorted string lists / map compares) — never a substring one, because
 * sibling-wildcard absorption is the known trap this estate has repeatedly
 * pinned (S332/S350). The export is VENDORED, not imported: contracts'
 * `exports` map blocks subpath JSON access, and vendoring byte-identical
 * snapshots is the sanctioned cross-repo pattern (md5 at commit time:
 * 9727f2d39d1a453440cf72bf6e17d320).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class S280ContractsRouteManifestParityTest extends TestCase
{
    private const HUB_FIXTURE_PATH = __DIR__ . '/Fixtures/phlix-server-route-manifest.json';

    private const CONTRACTS_EXPORT_PATH = __DIR__ . '/Fixtures/contracts-server-route-manifest.json';

    /**
     * Code-resident survival token for this gate: unique, space-free, and
     * asserted absent from both vendored files (it identifies the GATE, not
     * the data). Bump the sha/count suffix in lockstep with the pins below.
     */
    private const S280_SURVIVAL_TOKEN = 'S280hubgate-manifest-parity@400-888a42b2';

    /**
     * The tuple count BOTH derivations must carry — the canonical contracts
     * total (364 Application + 47 WebPortal − 11 shared), pinned positively
     * so an empty or gutted scan can never pass.
     */
    private const S280_EXPECTED_ROUTE_COUNT = 400;

    /**
     * The vendored S332 snapshot and the vendored contracts export must be
     * pinned to the SAME phlix-server commit. This is what makes refresh
     * lock-step: a currency-sync that moves the hub fixture without
     * re-vendoring the contracts copy (or the reverse) goes RED here.
     */
    public function testS280BothManifestsArePinnedToTheSameServerSha(): void
    {
        $fixture = self::hubFixture();
        $export = self::contractsExport();

        $this->assertSame(
            $fixture['source_sha'],
            $export['provenance']['serverSha'],
            sprintf(
                '%s: the hub S332 snapshot is pinned to server %s but the vendored contracts export '
                . 'carries %s — the two copies have drifted apart. Re-run the currency-sync '
                . '(dump-phlix-server-route-manifest.php) AND re-vendor '
                . 'contracts dist/server-route-manifest.json in the SAME commit.',
                self::S280_SURVIVAL_TOKEN,
                $fixture['source_sha'],
                $export['provenance']['serverSha'],
            ),
        );
    }

    /**
     * EXACT set-equality of the two route sets after shape normalisation:
     * fixture objects {method, path} and export tuples [method, path] both
     * become `"METHOD path"` strings, and the sorted unique lists must be
     * byte-identical. A tuple present on only ONE side — added, removed, or
     * re-spelled (sibling-wildcard absorption, `{id}` vs `{profileId}`, a
     * verb swap) — fails here with the symmetric difference named.
     */
    public function testS280RouteSetsAreTupleIdenticalAcrossBothDerivations(): void
    {
        $fixtureLines = self::hubFixtureLines();
        $exportLines = self::contractsExportLines();

        $missingFromExport = array_values(array_diff($fixtureLines, $exportLines));
        $missingFromFixture = array_values(array_diff($exportLines, $fixtureLines));

        $this->assertSame(
            $fixtureLines,
            $exportLines,
            sprintf(
                "%s: the hub snapshot and the contracts export are not tuple-identical. "
                . "In hub snapshot only: [%s]. In contracts export only: [%s].",
                self::S280_SURVIVAL_TOKEN,
                implode(', ', $missingFromExport),
                implode(', ', $missingFromFixture),
            ),
        );
    }

    /**
     * Non-vacuity, stated positively from BOTH sides: each file's own recorded
     * count must equal the number of entries it carries AND the canonical
     * 400 — so neither copy can pass (b) by being empty, gutted, or silently
     * re-cut to a different total.
     */
    public function testS280BothDerivationsAreNonVacuousAndCarryFourHundredTuples(): void
    {
        $fixture = self::hubFixture();
        $export = self::contractsExport();

        $this->assertSame(
            self::S280_EXPECTED_ROUTE_COUNT,
            $fixture['route_count'],
            sprintf(
                '%s: the hub fixture header must declare route_count %d, got %s.',
                self::S280_SURVIVAL_TOKEN,
                self::S280_EXPECTED_ROUTE_COUNT,
                var_export($fixture['route_count'], true),
            ),
        );
        $this->assertCount(
            self::S280_EXPECTED_ROUTE_COUNT,
            $fixture['routes'],
            'the hub fixture route list must carry exactly as many entries as its header claims',
        );
        $this->assertSame(
            self::S280_EXPECTED_ROUTE_COUNT,
            $export['provenance']['total'],
            sprintf(
                '%s: the contracts export provenance.total must be %d, got %s.',
                self::S280_SURVIVAL_TOKEN,
                self::S280_EXPECTED_ROUTE_COUNT,
                var_export($export['provenance']['total'], true),
            ),
        );
        $this->assertCount(
            self::S280_EXPECTED_ROUTE_COUNT,
            $export['routes'],
            'the contracts export route list must carry exactly provenance.total entries',
        );
    }

    /**
     * Per-module covered counts must be IDENTICAL between the two derivations,
     * grouped the same mechanical way on both sides: the first path segment
     * after `/api/v1/` (`api/v1/<module>`), and for routes outside that shape
     * the bare first path segment (`hls`, `media`, `trickplay`, …). The group
     * map is compared exactly (ksort + assertSame), and both sums must hit the
     * 400 denominator — the breakdown is printed so a future failure names
     * the module that diverged.
     */
    public function testS280PerModuleGroupCountsMatchAcrossBothDerivations(): void
    {
        $fixtureGroups = self::groupCounts(self::hubFixtureLines());
        $exportGroups = self::groupCounts(self::contractsExportLines());

        $this->assertNotEmpty($fixtureGroups, 'the fixture grouping must not be empty');
        $this->assertSame(
            self::S280_EXPECTED_ROUTE_COUNT,
            array_sum($fixtureGroups),
            'the fixture per-module groups must sum to the full 400-tuple denominator',
        );
        $this->assertSame(
            self::S280_EXPECTED_ROUTE_COUNT,
            array_sum($exportGroups),
            'the contracts export per-module groups must sum to the full 400-tuple denominator',
        );
        $this->assertSame(
            $fixtureGroups,
            $exportGroups,
            sprintf(
                '%s: per-module route counts diverge between the hub snapshot and the contracts '
                . 'export — hub: %s — contracts: %s',
                self::S280_SURVIVAL_TOKEN,
                (string) json_encode($fixtureGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                (string) json_encode($exportGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ),
        );

        fwrite(STDERR, sprintf(
            "S280 parity groups (both derivations, %d route(s) total):\n  %s\n",
            self::S280_EXPECTED_ROUTE_COUNT,
            implode(
                "\n  ",
                array_map(
                    static fn (string $group): string => sprintf('%s = %d', $group, $exportGroups[$group]),
                    array_keys($exportGroups),
                ),
            ),
        ));
    }

    /**
     * The survival token is CODE-resident, not data-resident: it must not have
     * leaked into either vendored JSON file.
     */
    public function testS280SurvivalTokenIsResidentInTheGateAndNotInTheData(): void
    {
        $this->assertStringNotContainsString(
            self::S280_SURVIVAL_TOKEN,
            (string) file_get_contents(self::HUB_FIXTURE_PATH),
            'the survival token belongs to this test file, not to the vendored hub fixture',
        );
        $this->assertStringNotContainsString(
            self::S280_SURVIVAL_TOKEN,
            (string) file_get_contents(self::CONTRACTS_EXPORT_PATH),
            'the survival token belongs to this test file, not to the vendored contracts export',
        );
    }

    /**
     * Load and boundary-validate the vendored S332 hub snapshot.
     *
     * @return array{
     *     source_sha: string,
     *     route_count: int,
     *     routes: list<array{method: string, path: string}>
     * }
     */
    private static function hubFixture(): array
    {
        $decoded = self::decode(self::HUB_FIXTURE_PATH, 'hub S332 snapshot');
        $routes = $decoded['routes'] ?? null;
        if (!is_array($routes) || !isset($decoded['source_sha'], $decoded['route_count'])) {
            throw new \RuntimeException(
                'S280: the vendored hub S332 snapshot is missing source_sha / route_count / '
                . 'routes — regenerate it with dump-phlix-server-route-manifest.php.',
            );
        }

        /** @var array{source_sha: string, route_count: int, routes: list<array{method: string, path: string}>} $decoded */
        return $decoded;
    }

    /**
     * Load and boundary-validate the vendored contracts export.
     *
     * @return array{
     *     provenance: array{serverSha: string, total: int},
     *     routes: list<array{0: string, 1: string}>
     * }
     */
    private static function contractsExport(): array
    {
        $decoded = self::decode(self::CONTRACTS_EXPORT_PATH, 'contracts route manifest export');
        $routes = $decoded['routes'] ?? null;
        if (!is_array($routes) || !isset($decoded['provenance']['serverSha'], $decoded['provenance']['total'])) {
            throw new \RuntimeException(
                'S280: the vendored contracts export is missing provenance.serverSha / provenance.total / '
                . 'routes — re-vendor it verbatim from ../phlix-contracts/dist/server-route-manifest.json.',
            );
        }

        /** @var array{provenance: array{serverSha: string, total: int}, routes: list<array{0: string, 1: string}>} $decoded */
        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $path, string $label): array
    {
        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException("S280: the vendored {$label} at {$path} could not be read.");
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("S280: the vendored {$label} is not valid JSON: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException("S280: the vendored {$label} does not decode to an object.");
        }

        return $decoded;
    }

    /**
     * Normalise the hub fixture's {method, path} objects to `"METHOD path"`
     * lines, deduped and sorted — the same shape the generator hashes.
     *
     * @return list<string>
     */
    private static function hubFixtureLines(): array
    {
        $lines = [];
        foreach (self::hubFixture()['routes'] as $route) {
            if (!isset($route['method'], $route['path'])) {
                throw new \RuntimeException(
                    'S280: a hub fixture route entry is missing method/path: '
                    . (string) json_encode($route, JSON_UNESCAPED_SLASHES),
                );
            }
            $lines[] = strtoupper((string) $route['method']) . ' ' . (string) $route['path'];
        }

        return self::uniqueSorted($lines);
    }

    /**
     * Normalise the contracts export's [method, path] tuples to `"METHOD path"`
     * lines, deduped and sorted.
     *
     * @return list<string>
     */
    private static function contractsExportLines(): array
    {
        $lines = [];
        foreach (self::contractsExport()['routes'] as $tuple) {
            if (!is_array($tuple) || count($tuple) !== 2 || $tuple[0] === '' || $tuple[1] === '') {
                throw new \RuntimeException(
                    'S280: a contracts export route tuple is not a [method, path] pair: '
                    . (string) json_encode($tuple, JSON_UNESCAPED_SLASHES),
                );
            }
            $lines[] = strtoupper((string) $tuple[0]) . ' ' . (string) $tuple[1];
        }

        return self::uniqueSorted($lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function uniqueSorted(array $lines): array
    {
        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * Group `"METHOD path"` lines by module: the first path segment after
     * `/api/v1/` becomes `api/v1/<module>`; every other shape groups on its
     * bare first path segment (`hls`, `media`, `trickplay`, …). Exact segment
     * compare — never a substring one.
     *
     * @param list<string> $lines
     *
     * @return array<string, int>
     */
    private static function groupCounts(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $separator = strpos($line, ' ');
            if ($separator === false) {
                throw new \RuntimeException("S280: manifest line '{$line}' is not a 'METHOD path' pair.");
            }
            $path = substr($line, $separator + 1);
            if (str_starts_with($path, '/api/v1/')) {
                $module = explode('/', substr($path, strlen('/api/v1/')))[0] ?? '';
                if ($module !== '') {
                    $groups['api/v1/' . $module] = ($groups['api/v1/' . $module] ?? 0) + 1;
                    continue;
                }
            }

            $first = explode('/', ltrim($path, '/'))[0] ?? '';
            if ($first === '') {
                throw new \RuntimeException("S280: route path '{$path}' has no groupable path segment.");
            }
            $groups[$first] = ($groups[$first] ?? 0) + 1;
        }

        ksort($groups);

        return $groups;
    }
}
