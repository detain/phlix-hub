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
