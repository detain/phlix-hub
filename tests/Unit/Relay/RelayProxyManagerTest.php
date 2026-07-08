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
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Timer;

use function array_key_last;
use function base64_decode;
use function count;
use function json_decode;
use function microtime;

use const JSON_THROW_ON_ERROR;

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

        // Authoritative registry verdict: the body carries the distinct
        // `server.no_tunnel` code so a stale `relay_active=1` cannot be mistaken
        // for a real forward — it fails fast here instead of timing out (504).
        $body = base64_decode((string) $this->published[0]['data']['body_b64'], true);
        $this->assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.no_tunnel', $decoded['code'] ?? null);
    }

    public function test_request_for_inactive_tunnel_publishes_503_no_tunnel(): void
    {
        // A tunnel object exists but is not in the ACTIVE status (e.g. closing).
        // The registry cross-check still treats this as "no live tunnel" and
        // fails fast with 503 server.no_tunnel rather than forwarding.
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnel->close('test_closing');

        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $manager->onRequest([
            'request_id' => 'req-2',
            'reply_event' => 'reply.2',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/api/v1/libraries',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        $this->assertCount(1, $this->published);
        $this->assertSame(503, $this->published[0]['data']['status']);
        $body = base64_decode((string) $this->published[0]['data']['body_b64'], true);
        $this->assertIsString($body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.no_tunnel', $decoded['code'] ?? null);
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

    public function test_streaming_response_publishes_phased_frames_without_buffering(): void
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
            'path' => '/hls/job-abc/seg-00007.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        $head = new RelayHttpResponseHead(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '6'], 6);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead($head),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('foo'),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('bar'),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // Each frame is published as its own phase — nothing is buffered into a
        // single blob (unlike the buffered path, which publishes exactly once).
        $this->assertCount(4, $this->published);
        $this->assertSame('head', $this->published[0]['data']['phase']);
        $this->assertSame(200, $this->published[0]['data']['status']);
        $this->assertSame('video/mp2t', $this->published[0]['data']['headers']['Content-Type'] ?? null);
        $this->assertSame('6', $this->published[0]['data']['headers']['Content-Length'] ?? null);

        $this->assertSame('body', $this->published[1]['data']['phase']);
        $this->assertSame('foo', base64_decode((string) $this->published[1]['data']['body_b64'], true));
        $this->assertSame('body', $this->published[2]['data']['phase']);
        $this->assertSame('bar', base64_decode((string) $this->published[2]['data']['body_b64'], true));

        $this->assertSame('end', $this->published[3]['data']['phase']);
        $this->assertArrayNotHasKey('body_b64', $this->published[3]['data']);

        // Every phase carries the HTTP worker's request id for routing.
        foreach ($this->published as $entry) {
            $this->assertSame('req-1', $entry['data']['request_id']);
            $this->assertSame('reply.1', $entry['event']);
        }
    }

    public function test_fail_server_ends_a_started_stream_instead_of_publishing_503(): void
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
            'path' => '/hls/job-abc/seg-00007.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        // Head already streamed to the browser.
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '10'], 10)),
        ));

        $manager->failServer('srv-1');

        // The head phase + a terminating end phase — NOT a fresh 503 body (which
        // could not be substituted after the head was already sent).
        $this->assertCount(2, $this->published);
        $this->assertSame('head', $this->published[0]['data']['phase']);
        $this->assertSame('end', $this->published[1]['data']['phase']);
        $this->assertArrayNotHasKey('status', $this->published[1]['data']);
    }

    /**
     * Regression for the MINOR finding: a steadily-dripping response keeps
     * re-arming the pure per-frame inactivity timer forever, so an absolute
     * duration ceiling is enforced independently of activity. Rather than
     * actually sleeping past the (multi-minute) ceiling, the pending entry's
     * `stream_opened_at` is rewritten into the past via reflection to simulate
     * "this stream has been open a long time" — the next arriving frame must
     * then terminate the stream instead of re-arming the inactivity timer.
     */
    public function test_absolute_stream_duration_ceiling_terminates_a_steadily_dripping_response(): void
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
            'path' => '/hls/job-abc/seg-00007.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        // Rewrite the entry's clock fields into the distant past — simulates a
        // stream that has been open far beyond the absolute ceiling without
        // actually sleeping the test.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $pending[$requestId]['stream_opened_at'] = microtime(true) - 3600.0;
        $pending[$requestId]['timer_armed_at'] = microtime(true) - 3600.0;
        $pendingProp->setValue($manager, $pending);

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '1000000'], 1000000)),
        ));
        // Activity arrives — which would normally re-arm the pure inactivity
        // timer — but the absolute ceiling must terminate instead.
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('x'),
        ));

        $this->assertSame('end', $this->published[array_key_last($this->published)]['data']['phase'] ?? null);

        // The pending entry is gone: a further frame for the same request id is
        // now dropped as unknown/closed rather than continuing to stream.
        $countAfterTermination = count($this->published);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('y'),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));
        $this->assertCount($countAfterTermination, $this->published);
    }

    public function test_inactivity_timeout_ends_a_started_stream_instead_of_publishing_504(): void
    {
        // Covers the onTimeout() streaming branch: once the head has been
        // streamed to the browser, a per-frame inactivity cutoff must terminate
        // the stream with an END phase — NOT a fresh 504 body (which can no
        // longer be substituted after the head is on the wire). This is the
        // pure-inactivity sibling of the absolute-duration ceiling test above,
        // and of failServer()'s started-stream branch.
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
            'path' => '/hls/job-abc/seg-00007.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        // Head streamed to the browser → stream_started = true.
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '10'], 10)),
        ));

        // Fire the inactivity timer directly (no real event loop in PHPUnit).
        $onTimeout = new ReflectionMethod(RelayProxyManager::class, 'onTimeout');
        $onTimeout->setAccessible(true);
        $onTimeout->invoke($manager, $requestId);

        // Exactly the real head phase (status 200) + a terminating end phase —
        // NOT a fresh 504 body substituted after the head was already on the wire.
        $this->assertCount(2, $this->published);
        $this->assertSame('head', $this->published[0]['data']['phase']);
        $this->assertSame(200, $this->published[0]['data']['status']);
        $this->assertSame('end', $this->published[1]['data']['phase']);
        $this->assertArrayNotHasKey('status', $this->published[1]['data'], 'the terminating end phase must carry no status body');

        // The pending entry is torn down: further frames for it are dropped.
        $countAfter = count($this->published);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('late'),
        ));
        $this->assertCount($countAfter, $this->published);
    }

    public function test_streaming_activity_rearms_the_inactivity_timer_within_the_limits(): void
    {
        // Covers touchStreamTimer()'s re-arm branch (the feature that lets a
        // large, steadily-flowing body stream past the old fixed timeout): once
        // the per-frame throttle window has elapsed and the absolute ceiling is
        // NOT yet reached, a new response frame must re-arm the completion timer
        // and stamp a fresh timer_armed_at — rather than either terminating
        // (the absolute-ceiling test) or short-circuiting on the throttle guard
        // (every other fast test, where frames arrive < 1s apart).
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
            'path' => '/media/item/stream',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '1000000'], 1000000)),
        ));

        // Push the last re-arm past the throttle window (so the next frame
        // actually re-arms) while keeping the stream comfortably inside the
        // absolute-duration ceiling (so it is NOT terminated).
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $staleArmedAt = microtime(true) - 5.0; // > 1s throttle window
        $pending[$requestId]['timer_armed_at'] = $staleArmedAt;
        $pending[$requestId]['stream_opened_at'] = microtime(true) - 5.0; // well under the 900s ceiling
        $pendingProp->setValue($manager, $pending);

        $publishedBefore = count($this->published);

        // A body frame arrives → touchStreamTimer must re-arm (not terminate).
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('x'),
        ));

        // The stream is still alive: the only new publish is the body phase,
        // and no terminating end phase was emitted.
        $this->assertSame($publishedBefore + 1, count($this->published));
        $this->assertSame('body', $this->published[array_key_last($this->published)]['data']['phase']);

        // The re-arm branch ran: the entry survives and timer_armed_at advanced
        // past the stale value we injected (proving it was NOT the throttle
        // early-return and NOT the terminate-and-unset ceiling branch).
        /** @var array<int, array<string, mixed>> $after */
        $after = $pendingProp->getValue($manager);
        $this->assertArrayHasKey($requestId, $after, 'a re-armed stream must remain pending');
        $this->assertGreaterThan($staleArmedAt, $after[$requestId]['timer_armed_at']);
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

    // ---------------------------------------------------------------------
    // D3: per-request completion-timer timeout. The HTTP worker threads its
    // browser-facing reply timeout into the published payload's `timeout`
    // field; `onRequest()` coerces it via `asTimeout()` and arms the completion
    // `Timer` with it, so a playback-read segment (wider streaming ceiling) is
    // not 504'd by this worker before the browser-facing wait elapses. A legacy
    // worker that omits the field (or sends garbage) keeps the injected default.
    // ---------------------------------------------------------------------

    /**
     * Base proxy-request payload; `$overrides` (union-first) tweak individual
     * fields — e.g. add/replace `timeout`, or omit it entirely for the default.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function proxyPayload(array $overrides = []): array
    {
        return $overrides + [
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/hls/job-abc/seg-00007.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ];
    }

    /**
     * `asTimeout()` coercion matrix. A valid, positive numeric (float / int /
     * numeric string) is used verbatim; anything absent, null, non-positive or
     * non-numeric falls back to the injected default (30s here).
     *
     * @return iterable<string, array{0: mixed, 1: float}>
     */
    public static function timeoutCoercionProvider(): iterable
    {
        // Valid, positive numerics → used verbatim.
        yield 'positive float' => [60.0, 60.0];
        yield 'positive int' => [45, 45.0];
        yield 'numeric string (int)' => ['60', 60.0];
        yield 'numeric string (float)' => ['42.5', 42.5];

        // Absent / null / non-positive / non-numeric → injected default (30s).
        yield 'null' => [null, 30.0];
        yield 'zero int' => [0, 30.0];
        yield 'zero float' => [0.0, 30.0];
        yield 'negative int' => [-5, 30.0];
        yield 'negative float' => [-0.5, 30.0];
        yield 'non-numeric string' => ['soon', 30.0];
        yield 'empty string' => ['', 30.0];
        yield 'bool true' => [true, 30.0];
        yield 'array' => [[60], 30.0];
    }

    /**
     * @dataProvider timeoutCoercionProvider
     */
    public function test_as_timeout_coerces_the_payload_field(mixed $value, float $expected): void
    {
        $manager = new RelayProxyManager(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $reflected = new ReflectionMethod(RelayProxyManager::class, 'asTimeout');
        $reflected->setAccessible(true);
        /** @var float $timeout */
        $timeout = $reflected->invoke($manager, $value);

        $this->assertSame($expected, $timeout);
    }

    /**
     * The fallback reads the INJECTED default, not a hardcoded 30 — a manager
     * built with a different default returns that on every non-positive /
     * non-numeric / absent value, while a valid value still wins.
     */
    public function test_as_timeout_falls_back_to_the_injected_default(): void
    {
        $manager = new RelayProxyManager(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            45,
            $this->publisher(),
        );

        $reflected = new ReflectionMethod(RelayProxyManager::class, 'asTimeout');
        $reflected->setAccessible(true);

        $this->assertSame(45.0, $reflected->invoke($manager, null));
        $this->assertSame(45.0, $reflected->invoke($manager, 0));
        $this->assertSame(45.0, $reflected->invoke($manager, -10));
        $this->assertSame(45.0, $reflected->invoke($manager, 'nope'));
        $this->assertSame(60.0, $reflected->invoke($manager, 60));
    }

    /**
     * End-to-end wiring: `onRequest()` must arm its completion `Timer` with the
     * COERCED timeout. Workerman's `Timer::add(..., persistent:false)` delegates
     * to the installed event loop's `delay($interval, ...)`, so installing a
     * stub `EventInterface` lets us observe the exact interval `onRequest()`
     * passes. (Outside a running loop `Timer::add()` throws and `onRequest()`
     * swallows it, so this seam is required to see the value at all.) `Tunnel`
     * arms no timers, so the sole `delay()` per `onRequest()` is the completion
     * timer. The stub is restored in `finally` so no global state leaks.
     */
    public function test_on_request_arms_completion_timer_with_the_coerced_timeout(): void
    {
        /** @var list<float> $captured */
        $captured = [];
        $event = $this->createMock(EventInterface::class);
        $event->method('delay')->willReturnCallback(
            static function (float $interval, callable $func, array $args = []) use (&$captured): int {
                $captured[] = $interval;
                return 4242;
            },
        );

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $eventProp = new ReflectionProperty(Timer::class, 'event');
        $eventProp->setAccessible(true);
        /** @var EventInterface|null $original */
        $original = $eventProp->getValue();
        $eventProp->setValue(null, $event);

        try {
            // A valid streaming timeout arms the completion timer verbatim.
            $captured = [];
            $manager->onRequest($this->proxyPayload(['timeout' => 60]));
            $this->assertSame([60.0], $captured, 'a valid timeout arms the completion timer verbatim');

            // An absent field falls back to the injected default (30s).
            $captured = [];
            $manager->onRequest($this->proxyPayload());
            $this->assertSame([30.0], $captured, 'an absent timeout falls back to the injected default');

            // A negative value falls back to the injected default (30s).
            $captured = [];
            $manager->onRequest($this->proxyPayload(['timeout' => -5]));
            $this->assertSame([30.0], $captured, 'a negative timeout falls back to the injected default');

            // A non-numeric value falls back to the injected default (30s).
            $captured = [];
            $manager->onRequest($this->proxyPayload(['timeout' => 'bogus']));
            $this->assertSame([30.0], $captured, 'a non-numeric timeout falls back to the injected default');
        } finally {
            $eventProp->setValue(null, $original);
        }
    }
}
