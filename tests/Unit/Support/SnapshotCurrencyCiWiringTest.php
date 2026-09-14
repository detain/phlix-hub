<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S492 — the route-snapshot currency gate must keep its wiring AND its meaning.
 *
 * ## Why this test both reads a workflow file and RUNS a script
 *
 * `snapshot-currency:` (job id) / `Server Route Snapshot Currency` (check name)
 * is the estate's only automated tripwire against the vendored phlix-server route
 * snapshot rotting. Before S492 it was a bare `git ls-remote` string compare
 * pinned by NOTHING: no test checked the job still existed, and — worse — its
 * verdict was provenance-based, so every provenance-only server merge (bundle
 * bumps, docs, CI churn; spawn case server #781) reddened EVERY open hub PR over
 * a drift that did not exist.
 *
 * S492 (owner ruling batch39 F2 option (b), 2026-09-14) relaxed the verdict to a
 * route CONTENT compare. This guard is therefore two guards in one file:
 *
 *  (1) WIRING  — a mutation table over the job's shape (same family as
 *      {@see CrossRepoPathAssertCiWiringTest}); each assertion is a mutation that
 *      would put the silent gap back:
 *
 *      | mutation                                             | this test |
 *      | ---------------------------------------------------- | --------- |
 *      | delete / rename the `snapshot-currency:` job          | RED       |
 *      | drop the anonymous phlix-server fetch (URL/clone)     | RED       |
 *      | stop executing scripts/check-route-snapshot-currency  | RED       |
 *      | invoke the script against a path that was never fetched| RED      |
 *      | add `continue-on-error:` anywhere in the job          | RED       |
 *      | give the job a `needs:` chain                         | RED       |
 *      | delete or break the script / drop the content ladder  | RED       |
 *      | strip the S492 provenance token from the script       | RED       |
 *
 *  (2) BEHAVIOUR — EXECUTED assertions that the relaxed verdict is the correct
 *      verdict, not a toothless one. These are permanent and CI-encoded (they run
 *      in the suite, not just on a push):
 *
 *      | scenario (scratch server tree + scratch fixture)       | expected |
 *      | ------------------------------------------------------ | -------- |
 *      | content EQUAL, source_sha LAGS live master              | GREEN + ::warning:: |
 *      | content EQUAL, source_sha == live master                | GREEN, no warning   |
 *      | a route PLANTED in the live source of truth             | RED, names the tuple |
 *      | the other side cannot be read (no checkout)             | RED (fail-loud)     |
 *
 * The behavioural half is what the owner ruled mandatory: a relax from an exact
 * string to a content digest is only safe if a mutation test PROVES content drift
 * still turns it red. A gate that stopped turning red for real drift would be
 * strictly worse than the one it replaced.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class SnapshotCurrencyCiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const SCRIPT = __DIR__ . '/../../../scripts/check-route-snapshot-currency.php';

    /** The S492 provenance token, expected verbatim in the wired script. */
    private const S492_TOKEN = 'S492CURRCONTEX9P1';

    /**
     * Scratch application wire-guard routes as `METHOD path` tuples. One is a
     * deliberately long path so the concatenation-aware parser is exercised
     * permanently, and `GET /api/v1/shared/{id}` is shared with the web portal set
     * so the union-dedup (the 11 shared rails in production) is exercised too.
     *
     * @var list<string>
     */
    private const APP_TUPLES = [
        'GET /api/v1/widget/{id}',
        'POST /api/v1/widget',
        'GET /api/v1/shared/{id}',
        'DELETE /api/v1/admin/thing/{id}',
    ];

    /**
     * Scratch web-portal wire-guard routes; the first is intentionally shared with
     * {@see APP_TUPLES} to prove the union collapses duplicates, not counts them.
     *
     * @var list<string>
     */
    private const WEB_TUPLES = [
        'GET /api/v1/shared/{id}',
        'GET /portal/home',
    ];

    /** The single route planted only in the MUTATION scenario. */
    private const PLANTED_TUPLE = 'GET /__s492_mutated__/route';

    /**
     * The concat entry is written across two PHP lines in the guard source; it must
     * still parse to exactly one tuple.
     */
    private const CONCAT_TUPLE = 'DELETE /api/v1/admin/thing/{id}';

    /** @var list<string> temp directories to remove in tearDown */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $dir) {
            self::rrmdir($dir);
        }

        $this->tmp = [];

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (1) WIRING — mutation table over the job shape
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract the `snapshot-currency:` job block by indentation (jobs sit at two
     * spaces) and drop comment-only lines. Comment stripping is load-bearing: this
     * class and the job's own doctrine comment MENTION the clone URL and the script
     * path, and commented-out wiring must never satisfy a liveness pin.
     */
    private function currencyJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  snapshot-currency:\s*$/', $line) === 1) {
                $inJob = true;
                continue;
            }

            if ($inJob && preg_match('/^  \S/', $line) === 1) {
                break;
            }

            if (!$inJob) {
                continue;
            }

            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame(
            [],
            $block,
            'the workflow must still define a `snapshot-currency:` job — S332/S492 made it the ONLY '
            . 'automated tripwire on the vendored route snapshot, and a deleted job is the exact '
            . 'silent-return state this test exists to catch',
        );

        return implode("\n", $block);
    }

    public function testTheJobDefinesTheCurrencyGateWithItsVerbatimCheckName(): void
    {
        $job = $this->currencyJob();

        // The job `name:` becomes the GitHub check name; it is one of the 18 pinned
        // in the expect-file and must remain byte-identical or the merge ritual breaks.
        self::assertStringContainsString(
            'name: Server Route Snapshot Currency',
            $job,
            'the snapshot-currency job must keep the check name `Server Route Snapshot Currency` '
            . 'verbatim — the 18-name expect-file pins it',
        );
    }

    public function testTheJobKeepsBothOriginalStepNamesVerbatim(): void
    {
        $job = $this->currencyJob();

        self::assertStringContainsString(
            '- name: Checkout code',
            $job,
            'the original `Checkout code` step name must remain verbatim (spec §6: job id, both step '
            . 'names and check name stay verbatim)',
        );

        self::assertStringContainsString(
            '- name: Compare snapshot source_sha against phlix-server master',
            $job,
            'the original compare step must keep its name verbatim even though its logic is now a '
            . 'content digest — renaming it is not what the relax asked for',
        );
    }

    public function testTheJobFetchesThePhlixServerSourceAnonymously(): void
    {
        $job = $this->currencyJob();

        self::assertStringContainsString(
            'git clone',
            $job,
            'the job must fetch phlix-server source — the snapshot consts live on the SERVER side, so '
            . 'a hub-only checkout can measure nothing',
        );

        self::assertStringContainsString(
            'https://github.com/detain/phlix-server.git',
            $job,
            'the fetch must be the anonymous HTTPS clone of detain/phlix-server (public repo, no token) '
            . '— the same read pattern cross-repo-paths relies on',
        );

        self::assertStringContainsString(
            '--depth 1',
            $job,
            'the clone must be `--depth 1` — the whole point of S492 is that the gate is boot-free and '
            . 'cheap, mirroring the cross-repo-paths precedent',
        );
    }

    public function testTheJobExecutesTheCurrencyScript(): void
    {
        self::assertStringContainsString(
            'php scripts/check-route-snapshot-currency.php',
            $this->currencyJob(),
            'the compare must run through the real script — S411 proved a check that is not executed '
            . 'is a check that does not run',
        );
    }

    public function testTheScriptIsInvokedAgainstTheFetchedSiblingPath(): void
    {
        $job = $this->currencyJob();
        $steps = preg_split('/^(?=      - name:)/m', $job) ?: [];
        $running = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, 'php scripts/check-route-snapshot-currency.php'),
        ));

        self::assertCount(
            1,
            $running,
            'exactly one step of the snapshot-currency job may execute the currency script',
        );

        self::assertMatchesRegularExpression(
            '/check-route-snapshot-currency\.php\s+\.\.\/phlix-server\b/',
            $running[0],
            'the script invocation must name the SAME relative sibling the clone creates '
            . '(../phlix-server) — pin the join, not just the two ends',
        );
    }

    public function testTheJobCannotBeNeuteredByContinueOnError(): void
    {
        self::assertStringNotContainsString(
            'continue-on-error',
            $this->currencyJob(),
            'the snapshot-currency job must carry no `continue-on-error` — a drift gate whose failure is '
            . 'demoted to a warning reports success without protecting the S107 deny enumeration',
        );
    }

    public function testTheJobIsNotBehindANeedsChain(): void
    {
        self::assertStringNotContainsString(
            'needs:',
            $this->currencyJob(),
            'the snapshot-currency job must have no `needs:` — a skipped dependent reads as SUCCESS to '
            . 'the checks API, and the tripwire would stop running exactly when drift is likeliest',
        );
    }

    public function testTheCurrencyScriptExistsAndParses(): void
    {
        self::assertFileExists(
            self::SCRIPT,
            'scripts/check-route-snapshot-currency.php must exist — the snapshot-currency CI job runs '
            . 'exactly this file',
        );

        $output = [];
        $exit = 0;
        exec(
            sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::SCRIPT)),
            $output,
            $exit,
        );

        self::assertSame(
            0,
            $exit,
            "scripts/check-route-snapshot-currency.php does not parse:\n" . implode("\n", $output),
        );

        // Non-vacuity: `php -l` exiting 0 on an unreadable path would itself be the
        // self-adjusting failure mode, so the success line is required too.
        self::assertStringContainsString(
            'No syntax errors detected',
            implode("\n", $output),
            'the lint must have actually inspected the script',
        );
    }

    public function testTheScriptImplementsTheContentLadderAndCarriesTheToken(): void
    {
        $src = (string) file_get_contents(self::SCRIPT);

        // The provenance token is the identity pin (see S492_TOKEN).
        self::assertStringContainsString(
            "const S492_CURRENCY_GATE = '" . self::S492_TOKEN . "'",
            $src,
            'the wired script must declare the S492 provenance token, so this guard cannot silently '
            . 'pass against a substituted look-alike script',
        );

        // Digest-based content compare (not a bare source_sha string equality), plus
        // the non-blocking warning branch that keeps a lagging-but-current snapshot
        // from rotting silently.
        self::assertStringContainsString(
            "hash('sha256'",
            $src,
            'the script must hash the route corpus (content compare)',
        );
        self::assertStringContainsString(
            'sha256',
            $src,
            'the script must compare against the fixture digest',
        );
        self::assertStringContainsString(
            '::warning::',
            $src,
            'the script must be able to emit a non-blocking lag warning',
        );
        self::assertStringContainsString(
            'lags',
            $src,
            'the lag warning must actually say the snapshot lags live master',
        );
        self::assertStringContainsString(
            '::error::',
            $src,
            'the script must fail loud (::error::) when content drifts or cannot be measured',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (2) BEHAVIOUR — executed proofs over scratch trees
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * AC-1 + AC-3: identical route CONTENT but a source_sha that has moved past the
     * snapshot must be GREEN and must PRINT a visible non-blocking warning. This is
     * the exact #781-shaped drift the old gate falsely reddened, and the proof that
     * the relax does not hide the lag.
     */
    public function testContentEqualWithLaggingSourceShaIsGreenWithAWarning(): void
    {
        $server = $this->makeServerTree(self::APP_TUPLES, self::WEB_TUPLES);
        $fixture = $this->makeFixture($this->unionTuples(self::APP_TUPLES, self::WEB_TUPLES), self::OLD_SHA);

        [$exit, $out] = $this->runScript($server, $fixture, self::NEW_SHA);

        self::assertSame(0, $exit, "content-equal snapshot with a lagging source_sha must be GREEN:\n" . $out);
        self::assertStringContainsString('::warning::', $out, 'a lagging source_sha must surface a warning');
        self::assertStringContainsString(
            'lags',
            $out,
            'the warning must name the lag so the snapshot cannot rot silently',
        );
        self::assertStringContainsString(self::OLD_SHA_SHORT, $out, 'the warning must show the snapshot source_sha');
        self::assertStringContainsString(self::NEW_SHA_SHORT, $out, 'the warning must show the live master sha');
    }

    /** Fully current (source_sha == live master, content equal): GREEN, no warning. */
    public function testContentEqualAndCurrentIsGreenWithoutWarning(): void
    {
        $server = $this->makeServerTree(self::APP_TUPLES, self::WEB_TUPLES);
        $fixture = $this->makeFixture($this->unionTuples(self::APP_TUPLES, self::WEB_TUPLES), self::NEW_SHA);

        [$exit, $out] = $this->runScript($server, $fixture, self::NEW_SHA);

        self::assertSame(0, $exit, "a fully current snapshot must be GREEN:\n" . $out);
        self::assertStringNotContainsString(
            '::warning::',
            $out,
            'no warning when source_sha already matches live master',
        );
    }

    /**
     * AC-2 — THE mutation proof. A single route planted in the live source of truth,
     * while the snapshot digest is left untouched, MUST turn the gate red, and the
     * failure must name the drifted tuple. Without this row passing the whole relax
     * is unproven: the gate would be indistinguishable from one that never fails.
     */
    public function testAPlantedRouteTurnsTheGateRedAndNamesTheTuple(): void
    {
        $server = $this->makeServerTree(self::APP_TUPLES, self::WEB_TUPLES);
        // Fixture describes the PRE-mutation corpus; the live tree now has one extra route.
        $fixture = $this->makeFixture($this->unionTuples(self::APP_TUPLES, self::WEB_TUPLES), self::OLD_SHA);
        $this->plantRoute($server, self::PLANTED_TUPLE);

        [$exit, $out] = $this->runScript($server, $fixture, self::NEW_SHA);

        self::assertNotSame(0, $exit, "a real route drift MUST turn the currency gate red:\n" . $out);
        self::assertStringContainsString('::error::', $out, 'the drift must be a hard error, not a warning');
        self::assertStringContainsString(self::PLANTED_TUPLE, $out, 'the failure must name the tuple that drifted');
    }

    /** §7A network/measure doctrine: a gate that cannot read the other side is RED, never a vacuous 0==0 green. */
    public function testAnUnmeasurableServerSideIsRed(): void
    {
        $fixture = $this->makeFixture($this->unionTuples(self::APP_TUPLES, self::WEB_TUPLES), self::NEW_SHA);

        [$exit, $out] = $this->runScript('/tmp/definitely-not-phlix-server-' . getmypid(), $fixture, self::NEW_SHA);

        self::assertNotSame(
            0,
            $exit,
            'a missing/unfetchable phlix-server must be RED (network is a failure, never a pass)',
        );
        self::assertStringContainsString('::error::', $out, 'the unmeasurable state must fail loud');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // scratch-tree helpers
    // ─────────────────────────────────────────────────────────────────────────

    private const OLD_SHA = '1111111111111111111111111111111111111111';
    private const NEW_SHA = '2222222222222222222222222222222222222222';
    private const OLD_SHA_SHORT = '111111111111';
    private const NEW_SHA_SHORT = '222222222222';

    /**
     * Build a fake phlix-server root containing the two ROUTE_MANIFEST constants in
     * the same paths the real gate reads. The ` -> Handler [Middleware]` suffix is
     * attached so the parse must strip it, and one app route is written across two
     * PHP-concatenated lines.
     *
     * @param list<string> $app
     * @param list<string> $web
     */
    private function makeServerTree(array $app, array $web): string
    {
        $root = $this->newTempDir();
        mkdir($root . '/tests/Unit/Server/Core', 0777, true);
        mkdir($root . '/tests/Unit/Server/WebPortal', 0777, true);

        file_put_contents(
            $root . '/tests/Unit/Server/Core/ApplicationRouterWirePathGuardTest.php',
            $this->constFile($app, 'App\\Widget'),
        );
        file_put_contents(
            $root . '/tests/Unit/Server/WebPortal/WebPortalRouterWirePathGuardTest.php',
            $this->constFile($web, 'Web\\Portal'),
        );

        return $root;
    }

    /**
     * @param list<string> $tuples
     */
    private function constFile(array $tuples, string $handler): string
    {
        $lines = "<?php\n\nclass WireGuard\n{\n    private const ROUTE_MANIFEST = [\n";
        foreach ($tuples as $tuple) {
            if ($tuple === self::CONCAT_TUPLE) {
                // Deliberate multi-line concatenation: 'METHOD path'\n  . ' -> Handler [MW]',
                $lines .= "        '" . $tuple . "'\n            . ' -> " . $handler . "::handle [AuthMiddleware]',\n";
                continue;
            }

            $lines .= "        '" . $tuple . " -> " . $handler . "::handle [AuthMiddleware]',\n";
        }

        $lines .= "    ];\n}\n";

        return $lines;
    }

    private function plantRoute(string $serverRoot, string $tuple): void
    {
        $path = $serverRoot . '/tests/Unit/Server/Core/ApplicationRouterWirePathGuardTest.php';
        $src = (string) file_get_contents($path);
        $inject = "        '" . $tuple . " -> App\\Widget::planted [AuthMiddleware]',\n";
        $pos = strpos($src, 'private const ROUTE_MANIFEST = [');
        if ($pos === false) {
            self::fail('scratch guard lost its manifest const before planting');
        }
        $end = strpos($src, '[', $pos);
        if ($end === false) {
            self::fail('scratch guard lost its manifest array before planting');
        }
        $src = substr($src, 0, $end + 1) . "\n" . $inject . substr($src, $end + 1);
        file_put_contents($path, $src);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private function unionTuples(array $a, array $b): array
    {
        $union = [];
        foreach (array_merge($a, $b) as $t) {
            $union[$t] = true;
        }

        $keys = array_keys($union);
        sort($keys);

        return $keys;
    }

    /**
     * Write a snapshot fixture describing exactly `$tuples` and pinned to
     * `$sourceSha`. The sha256 is the dumper's corpus digest (sorted-unique
     * `METHOD path`, newline-joined) recomputed here INDEPENDENTLY of the script,
     * which is what makes the GREEN cases a real agreement rather than a tautology.
     *
     * @param list<string> $tuples
     */
    private function makeFixture(array $tuples, string $sourceSha): string
    {
        $routes = [];
        foreach ($tuples as $tuple) {
            [$method, $path] = self::splitTuple($tuple);
            $routes[] = ['method' => $method, 'path' => $path];
        }

        $digest = hash('sha256', implode("\n", $tuples));

        $json = (string) json_encode([
            'generator' => 'S492 scratch fixture',
            'source_repo' => 'detain/phlix-server',
            'source_sha' => $sourceSha,
            'route_count' => count($tuples),
            'sha256' => $digest,
            'routes' => $routes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $path = $this->newTempDir() . '/route-manifest.json';
        file_put_contents($path, $json);

        return $path;
    }

    /**
     * @return array{0: non-empty-string, 1: non-empty-string}
     */
    private static function splitTuple(string $tuple): array
    {
        $parts = explode(' ', $tuple, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            self::fail("malformed scratch tuple: {$tuple}");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string $serverRoot, string $fixturePath, string $liveSha): array
    {
        $cmd = sprintf(
            '%s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::SCRIPT),
            escapeshellarg($serverRoot),
            escapeshellarg($fixturePath),
            escapeshellarg($liveSha),
        );

        $out = [];
        $exit = 0;
        exec($cmd, $out, $exit);

        return [$exit, implode("\n", $out)];
    }

    /** Monotonic counter so scratch dirs are unique per-process without random_bytes (which throws). */
    private static int $scratchSeq = 0;

    private function newTempDir(): string
    {
        self::$scratchSeq++;
        $base = sprintf(
            '%s/s492-%d-%d-%d',
            sys_get_temp_dir(),
            getmypid(),
            self::$scratchSeq,
            (int) (microtime(true) * 1000000),
        );
        mkdir($base, 0777, true);
        $this->tmp[] = $base;

        return $base;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
