<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelayServerHandler;
use Phlix\Hub\Common\Logger\StructuredLogger;

class RelayServerHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-relay-handler-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_onFrame_ping_returns_pong_response(): void
    {
        $handler = new RelayServerHandler(
            $this->createStubSessionManager(),
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $frame = ['type' => 3, 'seq' => 42, 'payload' => ['seq' => 42]];
        $result = $handler->onFrame('session-abc', $frame);

        $this->assertNotNull($result);
        $this->assertSame(4, $result['type']);
        $this->assertSame(42, $result['seq']);
    }

    public function test_onFrame_pong_returns_null(): void
    {
        $handler = new RelayServerHandler(
            $this->createStubSessionManager(),
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $frame = ['type' => 4, 'seq' => 99, 'payload' => []];
        $result = $handler->onFrame('session-abc', $frame);

        $this->assertNull($result);
    }

    public function test_onFrame_http_request_returns_null(): void
    {
        $handler = new RelayServerHandler(
            $this->createStubSessionManager(),
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $frame = [
            'type' => 1,
            'seq' => 7,
            'payload' => [
                'seq' => 7,
                'method' => 'GET',
                'path' => '/api/v1/libraries',
                'headers' => ['Authorization' => 'Bearer token'],
                'body' => '',
            ],
        ];

        $result = $handler->onFrame('session-abc', $frame);
        $this->assertNull($result);
    }

    public function test_constructor_accepts_worker_node(): void
    {
        $handler = new RelayServerHandler(
            $this->createStubSessionManager(),
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'node-42',
        );
        $this->assertInstanceOf(RelayServerHandler::class, $handler);
    }

    public function test_onClose_delegates_to_session_manager(): void
    {
        $closeCallArgs = [];

        $sessionManager = new class ($closeCallArgs) extends \Phlix\Hub\Hub\RelaySessionManager {
            /** @var array<string, string> */
            private array $closeArgsCapture;
            public function __construct(array &$capture) {
                $this->closeArgsCapture = &$capture;
            }
            public function closeSession(string $sessionId, string $reason): void {
                $this->closeArgsCapture[] = ['sessionId' => $sessionId, 'reason' => $reason];
            }
        };

        $handler = new RelayServerHandler(
            $sessionManager,
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $handler->onClose('session-abc', 'server_disconnect');

        $this->assertCount(1, $closeCallArgs);
        $this->assertSame('session-abc', $closeCallArgs[0]['sessionId']);
        $this->assertSame('server_disconnect', $closeCallArgs[0]['reason']);
    }

    public function test_onClose_with_custom_reason(): void
    {
        $closeCallArgs = [];

        $sessionManager = new class ($closeCallArgs) extends \Phlix\Hub\Hub\RelaySessionManager {
            /** @var array<string, string> */
            private array $closeArgsCapture;
            public function __construct(array &$capture) {
                $this->closeArgsCapture = &$capture;
            }
            public function closeSession(string $sessionId, string $reason): void {
                $this->closeArgsCapture[] = ['sessionId' => $sessionId, 'reason' => $reason];
            }
        };

        $handler = new RelayServerHandler(
            $sessionManager,
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $handler->onClose('session-xyz', 'network_error');

        $this->assertCount(1, $closeCallArgs);
        $this->assertSame('session-xyz', $closeCallArgs[0]['sessionId']);
        $this->assertSame('network_error', $closeCallArgs[0]['reason']);
    }

    private function createStubSessionManager(): \Phlix\Hub\Hub\RelaySessionManager
    {
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        return new \Phlix\Hub\Hub\RelaySessionManager($db, new StructuredLogger('relay', []));
    }

    private function createStubJwtService(): EnrollmentJwtService
    {
        $keyPath = $this->tmpDir . '/key.pem';
        $keyManager = new \Phlix\Hub\Hub\Ed25519KeyManager($keyPath);
        return new EnrollmentJwtService($keyManager, 'https://hub.example.com');
    }
}
