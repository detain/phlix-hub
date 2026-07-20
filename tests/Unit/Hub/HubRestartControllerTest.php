<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Http\Controllers\HubRestartController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graceful restart endpoint (Phase 10).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests.
 * Here we assert the controller's restart-signal behaviour.
 *
 * @covers \Phlix\Hub\Http\Controllers\HubRestartController
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

    public function testRestartFailsWhenPidFileIsMissing(): void
    {
        $controller = new HubRestartController($this->pidFile);
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

        $controller = new HubRestartController($this->pidFile);
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

        $controller = new HubRestartController($this->pidFile);
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

        $controller = new TestableRestartController($this->pidFile, false);
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

        $controller = new TestableRestartController($this->pidFile, true);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);

        /** @var array{success: true, message: string} $body */
        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertSame('Restart signal sent.', $body['message']);
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

        $controller = new TestableRestartController($this->pidFile, true);
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

        $controller = new TestableRestartController($this->pidFile, true);
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

        $controller = new TestableRestartController($this->pidFile, false);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);
        self::assertSame([], $controller->scheduled);
        self::assertFalse($controller->fireScheduledSignal());
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
