<?php

/**
 * Phlix hub component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers\Stats;

use Phlix\Hub\Http\Controllers\Stats\MetricsController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Characterization pins for the hub metrics-controller parameter surface
 * (finding A-13, wave-5).
 *
 * These tests pin WHAT THE HUB ACCEPTS TODAY — they are not a ratification
 * of the behavior. The server copy of MetricsController reads the same four
 * endpoints through a materially different parameter law: query-string PLUS
 * a legacy decoded-body channel (query wins), HARD upper ceilings per
 * parameter (`MetricsController::MAX_*`), and the shared
 * `PageLimit::coerce` (`is_numeric ? (int) : default`) — where this hub
 * controller reads `$request->query` only, applies `max(1, v)` with no
 * ceiling, and parses with `filter_var(FILTER_VALIDATE_INT)`. The three
 * divergences are concretely pinned in the paired tests below; see
 * `phlix-server/tests/Unit/Server/Http/Controllers/Stats/`
 * `MetricsControllerParamSourceCharacterizationTest.php` for the mirrored
 * server-side pins. The two files must be read together and any
 * harmonization commit must flip BOTH at once (register: SRV-METRICS-A13).
 *
 * Client census (2026-11-19): both first-party consumers — phlix-ui
 * `ApiClient.get()` and the console's `ApiClient::send('GET', …)` — send
 * these parameters as the query string exclusively, so the hub's
 * body-ignoring law loses nothing to the server's body fallback for real
 * traffic; the ceiling/coercion splits, however, are only reachable by
 * hand-crafted callers (curl/scripts), which is precisely why they are
 * characterized rather than converged in this lane.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers\Stats
 */
final class MetricsControllerParamSourceCharacterizationTest extends TestCase
{
    /**
     * @return MetricsRepositoryInterface&MockObject
     */
    private function mockRepo(): MetricsRepositoryInterface
    {
        return $this->createMock(MetricsRepositoryInterface::class);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(array $query = [], array $body = []): Request
    {
        $request        = new Request();
        $request->query = $query;
        $request->body  = $body;

        return $request;
    }

    // ---- defaults (shared law with the server copy) ----

    public function testDefaultsWhenNothingIsSupplied(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(60)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(60, 60)->willReturn([]);
        $repo->expects($this->once())->method('liveConnections')->with(15)->willReturn([]);
        $repo->expects($this->once())->method('topRoutes')->with(15, 20)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(), []);
        $controller->history($this->request(), []);
        $controller->connections($this->request(), []);
        $controller->routes($this->request(), []);
    }

    // ---- channels: query only — the body is never consulted ----

    public function testQueryStringChannelIsRead(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(120)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '120']), []);
    }

    public function testBodyChannelIsSilentlyIgnored(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(60)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(60, 60)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request([], ['window' => '120']), []);
        $controller->history($this->request([], ['minutes' => '90', 'resolution' => '30']), []);
    }

    // ---- floors only: NO upper ceilings (divergence vs the server copy) ----

    public function testOversizedValuesAreForwardedUnclamped(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(9999999)->willReturn([]);
        $repo->expects($this->once())->method('history')->with(999999, 999999)->willReturn([]);
        $repo->expects($this->once())->method('liveConnections')->with(9999999)->willReturn([]);
        $repo->expects($this->once())->method('topRoutes')->with(999999, 5000)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '9999999']), []);
        $controller->history($this->request(['minutes' => '999999', 'resolution' => '999999']), []);
        $controller->connections($this->request(['ttl' => '9999999']), []);
        $controller->routes($this->request(['minutes' => '999999', 'limit' => '5000']), []);
    }

    public function testNegativeValuesClampToTheFloorOfOne(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('snapshot')->with(1)->willReturn([]);
        $repo->expects($this->once())->method('topRoutes')->with(1, 1)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '-5']), []);
        $controller->routes($this->request(['minutes' => '-5', 'limit' => '-5']), []);
    }

    // ---- coercion law: filter_var(FILTER_VALIDATE_INT) — divergent edges ----

    public function testCoercionLawForEdgeShapes(): void
    {
        // Where the server's `is_numeric ? (int) : default` law yields
        // 12, 1000, 12, 60 for these shapes, the hub's strict-int filter
        // yields 60, 60, 12, 1: filter_var rejects '12.5'/'1e3' (both
        // is_numeric in PHP 8), '12 ' AGREES via the whitespace allowance,
        // and bool(true) casts through (string) to 1 (is_numeric falls back).
        $observed = [];
        $repo     = $this->mockRepo();
        $repo->expects($this->exactly(4))->method('snapshot')
            ->willReturnCallback(function (int $window) use (&$observed): array {
                $observed[] = $window;

                return [];
            });

        $controller = new MetricsController($repo);
        $controller->snapshot($this->request(['window' => '12.5']), []);
        $controller->snapshot($this->request(['window' => '1e3']), []);
        $controller->snapshot($this->request(['window' => '12 ']), []);
        $controller->snapshot($this->request(['window' => true]), []);

        $this->assertSame(
            [60, 60, 12, 1],
            $observed,
            'filter_var law (hub); the server PageLimit::coerce law diverges — see the server copy',
        );
    }

    public function testNonNumericGarbageFallsBackToTheDefault(): void
    {
        $repo = $this->mockRepo();
        $repo->expects($this->once())->method('history')->with(60, 60)->willReturn([]);

        $controller = new MetricsController($repo);
        $controller->history($this->request(['minutes' => '60x', 'resolution' => ['60']]), []);
    }
}
