<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelayServerHandler;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Tests\Support\Hub\CapturingCloseSessionManager;

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
            self::assertIsArray($files);
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testOnFramePingReturnsPongResponse(): void
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

    public function testOnFramePongReturnsNull(): void
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

    public function testOnFrameHttpRequestReturnsNull(): void
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

    public function testConstructorAcceptsWorkerNode(): void
    {
        $handler = new RelayServerHandler(
            $this->createStubSessionManager(),
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'node-42',
        );
        $this->assertInstanceOf(RelayServerHandler::class, $handler);
    }

    public function testOnCloseDelegatesToSessionManager(): void
    {
        $sessionManager = new CapturingCloseSessionManager();

        $handler = new RelayServerHandler(
            $sessionManager,
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $handler->onClose('session-abc', 'server_disconnect');

        $this->assertCount(1, $sessionManager->closeCalls);
        $this->assertSame('session-abc', $sessionManager->closeCalls[0]['sessionId']);
        $this->assertSame('server_disconnect', $sessionManager->closeCalls[0]['reason']);
    }

    public function testOnCloseWithCustomReason(): void
    {
        $sessionManager = new CapturingCloseSessionManager();

        $handler = new RelayServerHandler(
            $sessionManager,
            $this->createStubJwtService(),
            new StructuredLogger('relay', []),
            'worker-1',
        );

        $handler->onClose('session-xyz', 'network_error');

        $this->assertCount(1, $sessionManager->closeCalls);
        $this->assertSame('session-xyz', $sessionManager->closeCalls[0]['sessionId']);
        $this->assertSame('network_error', $sessionManager->closeCalls[0]['reason']);
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
