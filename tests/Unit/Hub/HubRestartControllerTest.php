<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Controllers\HubRestartController;
use Phlix\Hub\Http\Request;
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

        $controller = new TestableRestartController($this->pidFile, false, $this->makeLogger());
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

        $controller = new TestableRestartController($this->pidFile, true, $this->makeLogger());
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
     * The only signal delivered while building the response must be the
     * no-op liveness probe (signal 0); the reload itself must still be
     * pending when the Response is handed back.
     */
    public function testAckIsBuiltBeforeTheReloadSignalIsDelivered(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new TestableRestartController($this->pidFile, true, $this->makeLogger());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);
        self::assertSame([12345], $controller->scheduled, 'reload must be deferred, not sent inline');
        self::assertSame(
            [[12345, 0]],
            $controller->signals,
            'only the signal-0 liveness probe may run before the ack',
        );

        // Now let the deferred one-shot fire, as the Workerman timer would.
        self::assertTrue($controller->fireScheduledSignal());
        self::assertSame([[12345, 0], [12345, SIGUSR2]], $controller->signals);
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

        $controller = new TestableRestartController($this->pidFile, true, $this->makeLogger());
        $controller->restart($this->makeRequest(), []);
        $controller->fireScheduledSignal();

        $delivered = array_values(array_filter(
            $controller->signals,
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

        $controller = new TestableRestartController($this->pidFile, false, $this->makeLogger());
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        self::assertSame([], $controller->scheduled);
        self::assertFalse($controller->fireScheduledSignal());
    }

    public function testRecycledPidIdentityMismatchRefusesToSignal(): void
    {
        // Minor 55: a live, signallable pid that is NOT the hub master
        // (crashed master, PID recycled by another tenant) must be refused
        // — no SIGUSR2 scheduled, no cheerful ack.
        file_put_contents($this->pidFile, '99999');

        $controller = new TestableRestartController($this->pidFile, true, $this->makeLogger());
        $controller->cmdline = "/usr/sbin/nginx\0-g\0daemon\0off\0";
        $response = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertSame('pid_identity_mismatch', $body['error'] ?? null);
        self::assertSame([], $controller->scheduled, 'a foreign process must never receive the reload signal');
    }

    public function testUnreadableCmdlineRefusesFailClosed(): void
    {
        file_put_contents($this->pidFile, '99999');

        $controller = new TestableRestartController($this->pidFile, true, $this->makeLogger());
        $controller->cmdline = null;
        $response = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertSame('pid_identity_mismatch', $body['error'] ?? null);
        self::assertSame([], $controller->scheduled);
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
