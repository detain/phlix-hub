<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Support\WorkermanProcessReloader;
use Phlix\Hub\Http\Controllers\HubRestartController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\FakeProcessReloader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graceful restart endpoint (Phase 10).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests.
 * Here we assert the controller's restart-signal behaviour.
 */
final class HubRestartControllerTest extends TestCase
{
    /** Temp PID file path. */
    private string $pidFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidFile = sys_get_temp_dir() . '/phlix_hub_test_pid_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
        }
        parent::tearDown();
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->body = [];

        return $request;
    }

    /**
     * The controller's failure detail goes to the injected hub log; none of
     * these cases exercise that path, so a quiet mock stands in.
     */
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    public function testRestartFailsWhenPidFileIsMissing(): void
    {
        $controller = new HubRestartController($this->pidFile, $this->makeLogger());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('pid_file_not_found', $body['error']);
    }

    public function testRestartFailsWhenPidFileIsEmpty(): void
    {
        file_put_contents($this->pidFile, '');

        $controller = new HubRestartController($this->pidFile, $this->makeLogger());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('invalid_pid', $body['error']);
    }

    public function testRestartFailsWhenPidFileContainsNonNumericValue(): void
    {
        file_put_contents($this->pidFile, "not-a-pid\n");

        $controller = new HubRestartController($this->pidFile, $this->makeLogger());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('invalid_pid', $body['error']);
    }

    public function testRestartFailsWhenSignalSendFails(): void
    {
        file_put_contents($this->pidFile, '99999'); // non-existent PID

        $controller = new HubRestartController($this->pidFile, $this->makeLogger(), new FakeProcessReloader(false));
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('signal_send_failed', $body['error']);
    }

    public function testRestartSucceedsWhenSignalIsSent(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new HubRestartController($this->pidFile, $this->makeLogger(), new FakeProcessReloader());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);

        /** @var array{success: true, message: string} $body */
        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        // Review hub__webux: the ack must disclose the restart's own cost
        // (workers cycle, relay tunnels drop) — rotating this literal with
        // the copy, strict identity keeps the pin's teeth.
        self::assertSame(
            'Restart signal sent — workers are cycling; active relay tunnels will drop '
                . 'and servers reconnect on their own.',
            $body['message'],
        );
    }

    /**
     * plan_settings.md §3.35: "Return a JSON ack, then reload/restart *after*
     * the response flushes (never mid-request)."
     *
     * The only OS limb exercised while building the response is the no-op
     * liveness probe; the reload itself must still be pending (scheduled, not
     * delivered) when the Response is handed back — the recording fake fires
     * it exactly once afterwards, standing in for the Workerman timer.
     */
    public function testAckIsBuiltBeforeTheReloadSignalIsDelivered(): void
    {
        file_put_contents($this->pidFile, '12345');

        $reloader   = new FakeProcessReloader();
        $controller = new HubRestartController($this->pidFile, $this->makeLogger(), $reloader);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame([12345], $reloader->scheduled, 'reload must be deferred, not sent inline');
        self::assertSame([], $reloader->delivered, 'nothing may be delivered before the ack');
        self::assertSame([12345], $reloader->probes, 'only the liveness probe may run before the ack');

        // Now let the deferred one-shot fire, as the Workerman timer would.
        self::assertTrue($reloader->fireScheduledReload());
        self::assertSame([[12345, SIGUSR2]], $reloader->delivered);
    }

    /**
     * Workerman treats SIGUSR1 and SIGUSR2 as reload, but only SIGUSR2 is the
     * GRACEFUL one (`Worker.php:1390` — `$gracefulStop = $signal === SIGUSR2`;
     * `Worker.php:2010` only arms the SIGKILL timer when it is false). The hub
     * carries long-lived relay tunnels, so the reload must be graceful, and
     * `scripts/install.sh`'s ExecReload must send the same signal.
     */
    public function testDeferredSignalIsTheGracefulSigusr2(): void
    {
        file_put_contents($this->pidFile, '4242');

        $reloader   = new FakeProcessReloader();
        $controller = new HubRestartController($this->pidFile, $this->makeLogger(), $reloader);
        $controller->restart($this->makeRequest(), []);
        self::assertTrue($reloader->fireScheduledReload());

        $delivered = array_values(array_filter(
            $reloader->delivered,
            static fn (array $pair): bool => $pair[1] !== 0,
        ));

        self::assertSame([[4242, SIGUSR2]], $delivered);
        self::assertNotSame(SIGUSR1, $delivered[0][1], 'SIGUSR1 is the NON-graceful reload');
    }

    /**
     * A stale pid file (process gone) must produce a real error rather than a
     * cheerful ack whose deferred signal then silently fails.
     */
    public function testStalePidYieldsErrorAndSchedulesNothing(): void
    {
        file_put_contents($this->pidFile, '99999');

        $reloader   = new FakeProcessReloader(false);
        $controller = new HubRestartController($this->pidFile, $this->makeLogger(), $reloader);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        self::assertSame([], $reloader->scheduled);
        self::assertFalse($reloader->fireScheduledReload());
    }

    public function testRecycledPidIdentityMismatchRefusesToSignal(): void
    {
        // Minor 55: a live, signallable pid that is NOT the hub master
        // (crashed master, PID recycled by another tenant) must be refused
        // — no SIGUSR2 scheduled, no cheerful ack.
        file_put_contents($this->pidFile, '99999');

        $reloader          = new FakeProcessReloader();
        $reloader->cmdline = "/usr/sbin/nginx\0-g\0daemon\0off\0";
        $controller        = new HubRestartController($this->pidFile, $this->makeLogger(), $reloader);
        $response = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertSame('pid_identity_mismatch', $body['error'] ?? null);
        self::assertSame([], $reloader->scheduled, 'a foreign process must never receive the reload signal');
    }

    public function testUnreadableCmdlineRefusesFailClosed(): void
    {
        file_put_contents($this->pidFile, '99999');

        $reloader          = new FakeProcessReloader();
        $reloader->cmdline = null;
        $controller        = new HubRestartController($this->pidFile, $this->makeLogger(), $reloader);
        $response = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertSame('pid_identity_mismatch', $body['error'] ?? null);
        self::assertSame([], $reloader->scheduled);
    }

    public function testCmdlineIdentityLawReadsOnlyTheFirstTwoArgvSlots(): void
    {
        self::assertTrue(HubRestartController::cmdlineIdentifiesHub("php\0/opt/phlix-hub/start.php\0start\0"));
        self::assertTrue(HubRestartController::cmdlineIdentifiesHub("/usr/bin/php8.3\0start.php\0start\0"));
        self::assertFalse(
            HubRestartController::cmdlineIdentifiesHub("tail\0-f\0/opt/phlix-hub/start.php\0"),
            'a deep argv token must not certify identity (grep/tail shapes)'
        );
        self::assertFalse(HubRestartController::cmdlineIdentifiesHub(''));
        self::assertFalse(HubRestartController::cmdlineIdentifiesHub("php\0artisan\0serve\0"));
    }

    /**
     * The pid path the controller reads must be the one `start.php` writes.
     * `config/server.php` is the single source of truth for both, so its
     * default must resolve inside the install's `var/` directory (a systemd
     * ReadWritePath) — not `/var/run/...`, which is neither writable by the
     * hardened unit nor ever created by `scripts/install.sh`.
     */
    public function testConfiguredPidFileDefaultLivesUnderInstallVar(): void
    {
        if (getenv('HUB_PID_FILE') !== false) {
            self::markTestSkipped('HUB_PID_FILE is set in this environment; the default is not in play.');
        }

        $config = include dirname(__DIR__, 3) . '/config/server.php';

        self::assertIsArray($config);
        self::assertArrayHasKey('pid_file', $config);
        self::assertIsString($config['pid_file']);

        $expected = dirname(__DIR__, 3) . '/var/hub.pid';
        self::assertSame(
            $expected,
            $config['pid_file'],
            'config pid_file must match the path start.php assigns to Worker::$pidFile',
        );
    }

    /**
     * Whole `openapi.yaml` text — the artefact whose restart section is pinned below.
     */
    private function specSource(): string
    {
        $path = dirname(__DIR__, 3) . '/openapi.yaml';
        self::assertFileExists($path, 'the hub specification must exist to be pinned');

        return (string) file_get_contents($path);
    }

    /**
     * Whole `HubRestartController.php` text — the subject of that section.
     */
    private function controllerSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Http/Controllers/HubRestartController.php';
        self::assertFileExists($path, 'the restart controller must exist to be pinned');

        return (string) file_get_contents($path);
    }

    /**
     * The arms the controller actually answers with, read from ITS OWN source.
     *
     * @return list<string> sorted, de-duplicated
     */
    private function emittedFailureArms(): array
    {
        $source = $this->controllerSource();
        self::assertMatchesRegularExpression(
            "/'error'\\s+=> 'pid_file_not_found'/",
            $source,
            'the restart controller must still own its 500 arms — the extractor below is anchored on them',
        );

        $count = preg_match_all("/'error'\\s+=> '([a-z_]+)'/", $source, $matches);
        self::assertIsInt($count, 'the arm extraction must run');
        self::assertGreaterThanOrEqual(
            6,
            $count,
            'the restart endpoint answers with six failure arms; fewer extracted means the reader broke',
        );

        /** @var list<string> $arms */
        $arms = array_values(array_unique($matches[1]));
        sort($arms);

        return $arms;
    }

    /**
     * The lines of `paths."/api/v1/admin/restart".post.responses."500"`.
     *
     * Read the same deliberately narrow line-wise way as
     * {@see \Phlix\Hub\Tests\Unit\Contracts\ErrorCodesOpenApiEnumContractTest}:
     * this repo has no YAML library and CI installs no `yaml` extension, so
     * `yaml_parse()` would pass locally and fatal in CI. Every anchor fails LOUD
     * rather than returning fewer lines — a reader that quietly found nothing
     * would turn the arm bijection below into a vacuous pass, which is the exact
     * failure mode this gate exists to prevent.
     *
     * @return list<string> the 500 node's lines, its own key and terminator excluded
     */
    private function restartFiveHundredBlock(): array
    {
        $lines = preg_split('/\r\n|\n/', $this->specSource());
        self::assertIsArray($lines, 'openapi.yaml must be readable');

        $pathIndex = array_search('  "/api/v1/admin/restart":', $lines, true);
        self::assertIsInt($pathIndex, 'the spec must carry the /api/v1/admin/restart path');

        $start = null;
        $indent = null;
        $last = count($lines);
        for ($i = $pathIndex + 1; $i < $last; $i++) {
            if (str_starts_with($lines[$i], '  "')) {
                break; // the next path key: this operation has no 500 node at all
            }
            if (trim($lines[$i]) === '"500":') {
                $start = $i;
                $indent = strspn($lines[$i], ' ');
                break;
            }
        }
        self::assertNotNull(
            $start,
            'the restart operation documents no "500" response: the review-minor cure '
            . '(enumerate the free-form arms) was reverted or lost',
        );
        self::assertNotNull($indent);

        $block = [];
        for ($i = $start + 1; $i < $last; $i++) {
            if (trim($lines[$i]) === '') {
                $block[] = $lines[$i];
                continue;
            }
            if (strspn($lines[$i], ' ') <= $indent) {
                break; // dedented back to the response-code level: the 500 node is over
            }
            $block[] = $lines[$i];
        }
        self::assertNotEmpty($block, 'the restart "500" response has no body');

        return $block;
    }

    /**
     * Review minor (2026-11-03, cured): `POST /api/v1/admin/restart` now
     * documents its 500 surface, and this is the pin that keeps it true.
     *
     * The endpoint answers failure with six free-form arms in a flat
     * `{ success: false, error, message }` envelope. They are deliberately NOT
     * registry codes (the `@phlix/contracts` vocabulary is frozen and carries no
     * code for this operation), so no machine-checked gate existed for them at
     * all — the exact asymmetry the review named: callers could branch on 500
     * while no published contract listed why. The cure documented the arms; this
     * test makes the documentation undeletable, both directions:
     *
     *  - every arm the controller emits must be one of the spec's bullets;
     *  - every bullet the spec carries must be an arm the controller emits.
     *
     * Adding an arm without documenting it, or documenting a fossil, redden
     * here with the offender named.
     */
    public function testTheSpecDocumentsExactlyTheArmsTheRestartControllerEmits(): void
    {
        $emitted = $this->emittedFailureArms();

        /** @var list<string> $documented */
        $documented = [];
        foreach ($this->restartFiveHundredBlock() as $line) {
            $entry = trim($line);
            if ($entry === '' || !str_starts_with($entry, '- ')) {
                continue; // prose, not an arm bullet
            }
            if (preg_match('/^- `([a-z_]+)`/', $entry, $match) !== 1) {
                self::fail('Unparsable bullet in the restart "500" description: ' . $entry);
            }
            $documented[] = $match[1];
        }

        $documented = array_values(array_unique($documented));
        sort($documented);
        self::assertNotEmpty(
            $documented,
            'the restart "500" response enumerates no arms at all, so nothing was compared — '
            . 'the cure this test guards is the enumeration itself',
        );

        self::assertSame(
            [],
            array_values(array_diff($emitted, $documented)),
            'every 500 arm the controller answers with must be enumerated in openapi.yaml '
            . '(the review-minor asymmetry: callers branch on 500 with no published list of why)',
        );

        self::assertSame(
            [],
            array_values(array_diff($documented, $emitted)),
            'openapi.yaml must not enumerate an arm the restart controller never emits',
        );
    }

    /**
     * The 500 envelope itself: the three keys the controller writes, closed
     * against extras, and the deliberate ABSENCE of a `code` key — the
     * registry-frozen posture stated as a schema rather than left to prose.
     */
    public function testTheSpecPinsTheRestartFiveHundredEnvelopeWithoutAWireCode(): void
    {
        $block = implode("\n", $this->restartFiveHundredBlock());

        self::assertMatchesRegularExpression(
            '/^\s+required: \[success, error, message\]$/m',
            $block,
            'the 500 schema must require exactly the three keys restart() writes',
        );
        self::assertMatchesRegularExpression(
            '/^\s+additionalProperties: false$/m',
            $block,
            'the 500 envelope is closed: restart() writes no fourth key',
        );
        foreach (['success', 'error', 'message'] as $key) {
            self::assertMatchesRegularExpression(
                '/^ {18}' . $key . ':$/m',
                $block,
                $key . ' must be declared as a property of the 500 schema',
            );
        }

        self::assertDoesNotMatchRegularExpression(
            '/^ {18}code:$/m',
            $block,
            'the restart 500 envelope carries no machine code: the @phlix/contracts registry is '
            . 'frozen and no code was added for this operation (no-new-wire-codes law)',
        );
    }

    // ---------------------------------- SH-070: the production adapter's own laws
    //
    // The controller can no longer pin WHICH signal the deferred leg carries —
    // that limb moved behind the port (that move IS the review cure). These
    // tests keep the graceful + one-shot laws honest anyway: the /proc and
    // probe limbs run against REAL posix pids (loop-independent), and the
    // timer limb is pinned against the adapter's source text, the same
    // self-source extraction style this file already uses for the 500 arms —
    // there is no Workerman event loop under PHPUnit to fire a timer into.

    private function adapterSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/Common/Support/WorkermanProcessReloader.php';
        self::assertFileExists($path, 'the process-reload adapter must exist to be pinned');

        return (string) file_get_contents($path);
    }

    public function testTheAdapterCarriesTheGracefulSignalAndTheOneShotDeferral(): void
    {
        $source = $this->adapterSource();

        // The signal choice: graceful SIGUSR2, single constant, used by the
        // deferred leg (a bare posix_kill($pid, SIGUSR1) slipping in here
        // would hard-kill relay tunnels — Worker.php only arms its SIGKILL
        // timer when gracefulStop is false).
        self::assertMatchesRegularExpression(
            '/public const int RELOAD_SIGNAL = SIGUSR2;/',
            $source,
            'the reload signal must stay the GRACEFUL SIGUSR2 (hub carries long-lived relay tunnels)',
        );
        self::assertMatchesRegularExpression(
            '/posix_kill\(\$pid, self::RELOAD_SIGNAL\)/',
            $source,
            'the deferred leg must deliver through the named constant, never a bare signal literal',
        );

        // The deferral: a one-shot Timer::add (trailing false — Workerman
        // timers REPEAT by default, and a repeating reload signal would cycle
        // the workers forever).
        $oneShot = '/Timer::add\(\s*self::SIGNAL_DELAY_SECONDS,'
            . '\s*function \(\) use \(\$pid\): bool \{'
            . '\s*self::resetOpcacheIfEnabled\(\);'
            . '\s*return posix_kill\(\$pid, self::RELOAD_SIGNAL\);\s*\},'
            . '\s*\[\],\s*false,\s*\)/';
        self::assertMatchesRegularExpression(
            $oneShot,
            $source,
            'scheduleGracefulReload must stay a ONE-SHOT deferred Timer::add '
            . '(ack flushes first, no repeating reload)',
        );

        // HUB-67: opcache is reset only when the status says it is enabled.
        self::assertMatchesRegularExpression(
            '/opcache_get_status\(false\).*opcache_enabled.*opcache_reset\(\)/s',
            $source,
            'opcache_reset() must be gated on opcache_get_status() showing enabled',
        );

        // The probe stays a no-delivery signal-0 check.
        self::assertMatchesRegularExpression(
            '/posix_kill\(\$pid, 0\)/',
            $source,
            'the liveness limb must stay a signal-0 probe (existence + permission without delivering)',
        );
    }

    public function testTheAdapterProbeAndCmdlineWorkAgainstRealProcesses(): void
    {
        $adapter = new WorkermanProcessReloader();
        $ownPid  = getmypid();
        self::assertIsInt($ownPid, 'the CLI runner must have a pid to probe itself with');

        self::assertTrue($adapter->isSignallable($ownPid), 'this php process is alive and ours');
        self::assertFalse($adapter->isSignallable(2147483647), 'an impossible pid is not signallable');

        $own = $adapter->readCmdline($ownPid);
        self::assertIsString($own, 'our own /proc entry is readable');
        self::assertStringContainsString('phpunit', (string) $own);
        self::assertNull($adapter->readCmdline(2147483647), 'no /proc entry means the fail-closed null');
    }

    public function testTheControllerDefaultsToTheProductionAdapter(): void
    {
        // Wiring law: the DI provider builds the controller with two
        // arguments, so the NULLED third limb must self-default to the real
        // Workerman/POSIX adapter — a test fake can never leak into prod by
        // nobody remembering to pass it.
        $controller = new HubRestartController('/nonexistent/phlix-hub-test.pid', $this->makeLogger());

        $reflection = new \ReflectionProperty(HubRestartController::class, 'reloader');
        $reloader   = $reflection->getValue($controller);

        self::assertInstanceOf(WorkermanProcessReloader::class, $reloader);
    }

    // ------------------------------------------------ SH-070 seam closure (round 2)

    /**
     * Review hub__srphp #5 (SH-070): AGENTS.md demands `final` controllers —
     * this was the estate's one open class, held open only so a test subclass
     * could intercept the restart signal. The cure replaces the inheritance
     * seam with an injected process-reloader boundary; this pin keeps the
     * class closed forever.
     */
    public function testTheRestartControllerIsFinal(): void
    {
        $reflection = new \ReflectionClass(HubRestartController::class);

        self::assertTrue(
            $reflection->isFinal(),
            'AGENTS.md "Controllers: final" — the restart controller must stay closed to inheritance',
        );
    }

    /**
     * The retired seam must stay retired: a resurrected subclass would both
     * fatal against a final parent and silently re-open the test-only hole
     * the review named. The seam's whole justification was intercepting the
     * signal; with the injected reloader it has no remaining purpose.
     *
     * Checked by name/file (not ::class) so the pin itself survives the file's
     * deletion for phpstan and for future readers.
     */
    public function testTheTestSeamSubclassStaysRetired(): void
    {
        // autoload=false: the honest question is "is it on the class path at
        // all", not "can the composer PSR-4 fallback notice its absence with
        // an include warning" (PHPUnit would surface the miss as a run issue).
        self::assertFalse(
            class_exists('Phlix\\Hub\\Tests\\Unit\\Hub\\TestableRestartController', false),
            'TestableRestartController was the sole reason HubRestartController was non-final; '
            . 'with the injected ProcessReloaderInterface seam it must not come back',
        );

        $seamFile = dirname(__DIR__, 3) . '/tests/Unit/Hub/TestableRestartController.php';
        self::assertFileDoesNotExist($seamFile, 'the retired inheritance seam must stay deleted');
    }

    /**
     * @param mixed $body
     *
     * @return array<string, mixed>
     */
    private function decode($body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
