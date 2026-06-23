<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

use function base64_decode;

/**
 * @covers \Phlix\Hub\Relay\RelayProxyManager
 */
final class RelayProxyManagerTest extends TestCase
{
    private FrameDecoder $codec;

    /** @var list<array{event: string, data: array<string, mixed>}> */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new FrameDecoder();
        $this->published = [];
    }

    /**
     * @return callable(string, array<string, mixed>): void
     */
    private function publisher(): callable
    {
        return function (string $event, array $data): void {
            $this->published[] = ['event' => $event, 'data' => $data];
        };
    }

    private function activeTunnel(string $serverId, TcpConnection $serverWs): Tunnel
    {
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('registerServer')->willReturn('sess-1');

        $tunnel = new Tunnel(
            $serverId,
            $serverWs,
            $sessionManager,
            $this->codec,
            $this->createMock(StructuredLogger::class),
        );
        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'a.b.c',
            'server_id' => $serverId,
        ]));

        return $tunnel;
    }

    public function test_request_for_offline_server_publishes_503(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn(null);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/api/v1/libraries',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        $this->assertCount(1, $this->published);
        $this->assertSame('reply.1', $this->published[0]['event']);
        $this->assertSame(503, $this->published[0]['data']['status']);
        $this->assertSame('req-1', $this->published[0]['data']['request_id']);
    }

    public function test_request_sends_http_request_frame_down_the_tunnel(): void
    {
        $sent = [];
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });

        $tunnel = $this->activeTunnel('srv-1', $serverWs);

        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/api/v1/libraries',
            'query' => 'a=1',
            'headers' => ['Accept' => 'application/json'],
            'body_b64' => '',
        ]);

        // First send is the HELLO_ACK; the last is our HTTP_REQUEST frame.
        $this->assertNotEmpty($sent);
        $frame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($frame);
        $this->assertSame(RelayFrameType::HTTP_REQUEST, $frame->type);

        $envelope = RelayHttpRequest::fromJson($frame->payload);
        $this->assertSame('GET', $envelope->method);
        $this->assertSame('/api/v1/libraries', $envelope->path);
        $this->assertSame('a=1', $envelope->query);
        $this->assertSame('application/json', $envelope->headers['Accept'] ?? null);

        // No reply published yet — awaiting the server's HTTP_RESPONSE.
        $this->assertCount(0, $this->published);
    }

    public function test_response_chunks_assemble_and_publish_reply(): void
    {
        $sent = [];
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });

        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/api/v1/libraries',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        // Recover the request id the manager allocated (the HTTP_REQUEST frame seq).
        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        $head = new RelayHttpResponseHead(200, ['Content-Type' => 'application/json'], 13);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead($head),
        ));
        foreach (RelayHttpResponseCodec::chunkBody('{"ok":true}!!') as $bodyChunk) {
            $manager->onResponseFrame(new RelayFrame(RelayFrameType::HTTP_RESPONSE, $requestId, $bodyChunk));
        }
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        $this->assertCount(1, $this->published);
        $reply = $this->published[0];
        $this->assertSame('reply.1', $reply['event']);
        $this->assertSame(200, $reply['data']['status']);
        $this->assertSame(['Content-Type' => 'application/json'], $reply['data']['headers']);
        $this->assertSame('{"ok":true}!!', base64_decode((string) $reply['data']['body_b64'], true));
    }

    public function test_response_for_unknown_request_is_dropped(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $manager = new RelayProxyManager(
            $this->createMock(TunnelManagerInterface::class),
            $logger,
            30,
            $this->publisher(),
        );

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            999,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        $this->assertCount(0, $this->published);
    }

    public function test_fail_server_publishes_503_for_pending_requests(): void
    {
        $sent = [];
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });
        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );
        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        $manager->failServer('srv-1');

        $this->assertCount(1, $this->published);
        $this->assertSame(503, $this->published[0]['data']['status']);
    }
}
