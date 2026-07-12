<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Timer;

use function array_key_last;
use function array_sum;
use function base64_decode;
use function base64_encode;
use function chr;
use function count;
use function json_decode;
use function microtime;
use function str_repeat;
use function strlen;
use function substr;

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
        $body = $this->published[0]['data']['body'];
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
        $body = $this->published[0]['data']['body'];
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

    /**
     * Gap (c): the chunked emission path (RelayProxyManager::onRequest for a
     * body too large for a single 65535-byte JSON envelope) had ZERO coverage.
     *
     * Drive onRequest with a ~140 KB BINARY body (contains NUL and 0xFF, spans
     * >2 body chunks) and assert the emitted HTTP_REQUEST frame sequence on the
     * one requestId is exactly: 1 HEAD (tag 0x01) → N BODY (tag 0x02) → 1 END
     * (tag 0x03). Every frame is decoded with the REAL vendored
     * RelayHttpRequestCodec (not a hand-rolled parser) so this end asserts
     * against the SAME contract the phlix-server reassembly half verifies.
     */
    public function test_large_binary_body_emits_head_body_end_chunks(): void
    {
        // Build a ~140 KB binary body cycling all 256 byte values so it
        // includes NUL (0x00) and 0xFF and is NOT valid UTF-8 / JSON. 140000 >
        // 2 * MAX_BODY_CHUNK (65534) so it must span exactly 3 BODY chunks.
        $pattern = '';
        for ($b = 0; $b < 256; $b++) {
            $pattern .= chr($b);
        }
        $body = substr(str_repeat($pattern, 548), 0, 140000);
        $this->assertSame(140000, strlen($body));
        $this->assertStringContainsString(chr(0), $body);
        $this->assertStringContainsString(chr(255), $body);

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
            'request_id' => 'req-big',
            'reply_event' => 'reply.big',
            'server_id' => 'srv-1',
            'method' => 'PUT',
            'path' => '/api/v1/library/upload',
            'query' => 'overwrite=1',
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body_b64' => base64_encode($body),
        ]);

        // Decode every emitted frame (HELLO_ACK first, then our request frames).
        // Each send() call carries exactly one complete encoded frame, so a
        // fresh decoder per entry mirrors the wire boundary.
        $chunks = [];
        foreach ($sent as $encoded) {
            // The HELLO_ACK is sent to the server as a raw JSON string (begins
            // with '{'), not a binary frame — skip it; binary frames begin with
            // a 4-byte seq whose high byte is never '{' (0x7B) here.
            if ($encoded === '' || $encoded[0] === '{') {
                continue;
            }
            $frame = (new FrameDecoder())->decode($encoded);
            $this->assertNotNull($frame);
            if ($frame->type !== RelayFrameType::HTTP_REQUEST) {
                continue;
            }
            $chunks[] = RelayHttpRequestCodec::decode($frame->payload);
        }

        // Sequence shape: exactly one HEAD, then N BODY, then exactly one END.
        $this->assertGreaterThanOrEqual(5, count($chunks)); // HEAD + 3 BODY + END
        $head = $chunks[0];
        $end = $chunks[count($chunks) - 1];
        $this->assertSame(RelayHttpRequestChunk::KIND_HEAD, $head->kind);
        $this->assertSame(RelayHttpRequestChunk::KIND_END, $end->kind);

        // HEAD decodes to a real RelayHttpRequestHead with method/path/query/
        // headers correct and bodySize (Content-Length) === strlen($body).
        $this->assertNotNull($head->head);
        $this->assertSame('PUT', $head->head->method);
        $this->assertSame('/api/v1/library/upload', $head->head->path);
        $this->assertSame('overwrite=1', $head->head->query);
        $this->assertSame('application/octet-stream', $head->head->headers['Content-Type'] ?? null);
        $this->assertSame((string) strlen($body), $head->head->headers['Content-Length'] ?? null);

        // Middle chunks are all BODY; there must be exactly one HEAD and one END
        // and no stray HEAD/END in between (strict ordering on the requestId).
        $bodyChunks = 0;
        $reassembled = '';
        for ($i = 1; $i < count($chunks) - 1; $i++) {
            $this->assertSame(RelayHttpRequestChunk::KIND_BODY, $chunks[$i]->kind);
            $this->assertLessThanOrEqual(RelayHttpRequestCodec::MAX_BODY_CHUNK, strlen($chunks[$i]->body));
            $reassembled .= $chunks[$i]->body;
            $bodyChunks++;
        }
        // ceil(140000 / 65534) = 3 BODY frames.
        $this->assertSame(3, $bodyChunks);

        // The concatenated BODY bytes equal the original body BYTE-FOR-BYTE —
        // NUL/0xFF preserved, no base64/UTF-8 corruption.
        $this->assertSame(strlen($body), strlen($reassembled));
        $this->assertTrue($body === $reassembled, 'reassembled body must be byte-identical to the source');

        // No reply yet — awaiting the server's HTTP_RESPONSE.
        $this->assertCount(0, $this->published);
    }

    /**
     * Back-compat boundary: a body whose base64 JSON envelope is <= 65535 bytes
     * still travels as ONE single-frame HTTP_REQUEST JSON envelope (legacy
     * shape, decodable via RelayHttpRequest::fromJson) — while a body one step
     * over the threshold flips to the chunked HEAD/BODY/END path. This pins the
     * 65535 decision boundary so the chunking never fires early (regressing the
     * back-compat envelope) nor late (413-capping a bodied request).
     */
    public function test_body_size_boundary_single_envelope_vs_chunked(): void
    {
        // Envelope shape used for the boundary probe (must match production's
        // method/path/query/headers so strlen(json) matches onRequest's check).
        $probe = static fn (string $b): int => strlen(
            (new RelayHttpRequest('POST', '/api/v1/upload', '', [], $b))->toJson(),
        );

        // Binary-search the smallest body whose JSON envelope exceeds 65535.
        $lo = 1;
        $hi = 60000;
        $this->assertGreaterThan(65535, $probe(str_repeat('x', $hi)));
        $this->assertLessThanOrEqual(65535, $probe(str_repeat('x', $lo)));
        while ($lo + 1 < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($probe(str_repeat('x', $mid)) <= 65535) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        $underBody = str_repeat('x', $lo);   // JSON <= 65535 → single envelope
        $overBody = str_repeat('x', $hi);    // JSON  > 65535 → chunked
        $this->assertLessThanOrEqual(65535, $probe($underBody));
        $this->assertGreaterThan(65535, $probe($overBody));

        // --- just under: exactly one HTTP_REQUEST JSON envelope frame ---
        $underFrames = $this->httpRequestFramesFor($underBody);
        $this->assertCount(1, $underFrames);
        $envelope = RelayHttpRequest::fromJson($underFrames[0]->payload);
        $this->assertSame('POST', $envelope->method);
        $this->assertTrue($underBody === $envelope->body, 'under-threshold body must round-trip via JSON envelope');

        // --- just over: chunked HEAD + BODY(s) + END, no JSON envelope ---
        $overFrames = $this->httpRequestFramesFor($overBody);
        $this->assertGreaterThanOrEqual(3, count($overFrames)); // HEAD + >=1 BODY + END
        $overChunks = [];
        foreach ($overFrames as $f) {
            $overChunks[] = RelayHttpRequestCodec::decode($f->payload);
        }
        $this->assertSame(RelayHttpRequestChunk::KIND_HEAD, $overChunks[0]->kind);
        $this->assertSame(RelayHttpRequestChunk::KIND_END, $overChunks[count($overChunks) - 1]->kind);
        $reassembled = '';
        for ($i = 1; $i < count($overChunks) - 1; $i++) {
            $this->assertSame(RelayHttpRequestChunk::KIND_BODY, $overChunks[$i]->kind);
            $reassembled .= $overChunks[$i]->body;
        }
        $this->assertTrue($overBody === $reassembled, 'over-threshold body must reassemble byte-identical');
    }

    /**
     * Drive onRequest for a POST with the given body and return the ordered
     * HTTP_REQUEST frames emitted down the tunnel (HELLO_ACK filtered out).
     *
     * @return list<RelayFrame>
     */
    private function httpRequestFramesFor(string $body): array
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
            'request_id' => 'req-boundary',
            'reply_event' => 'reply.boundary',
            'server_id' => 'srv-1',
            'method' => 'POST',
            'path' => '/api/v1/upload',
            'query' => '',
            'headers' => [],
            'body_b64' => base64_encode($body),
        ]);

        $frames = [];
        foreach ($sent as $encoded) {
            // Skip the raw-JSON HELLO_ACK (begins with '{'); decode binary frames.
            if ($encoded === '' || $encoded[0] === '{') {
                continue;
            }
            $frame = (new FrameDecoder())->decode($encoded);
            $this->assertNotNull($frame);
            if ($frame->type === RelayFrameType::HTTP_REQUEST) {
                $frames[] = $frame;
            }
        }

        return $frames;
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
        $this->assertSame('{"ok":true}!!', $reply['data']['body']);
    }

    /**
     * HB-0.3 anti-stall (buffered HEAD): the paired server's `withFile()` HEAD
     * route emits, over the tunnel, exactly a head frame (carrying
     * Content-Length) followed by a zero-body END — a body frame is NEVER sent.
     * The buffered path must assemble and publish its single reply the moment
     * END arrives, carrying the head's status + Content-Length with an empty
     * body, rather than waiting for a body frame that never comes (which would
     * stall the request until the reply timeout and surface downstream as a 504).
     */
    public function test_head_buffered_path_completes_on_end_with_zero_body(): void
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

        // Buffered request (no `stream` flag) — HEAD is always buffered.
        $manager->onRequest([
            'request_id' => 'req-head',
            'reply_event' => 'reply.head',
            'server_id' => 'srv-1',
            'method' => 'HEAD',
            'path' => '/media/item-123/stream',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        // The exact shape the server emits for a HEAD to a withFile() route:
        // a head frame carrying Content-Length, then a zero-body END. No body
        // frame is ever sent.
        $head = new RelayHttpResponseHead(
            200,
            ['Content-Type' => 'video/mp4', 'Content-Length' => '12345', 'Accept-Ranges' => 'bytes'],
            12345,
        );
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead($head),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // Completed on END — exactly ONE buffered reply, never a phased stream
        // and never a stall waiting on a body frame that never arrives.
        $this->assertCount(1, $this->published);
        $reply = $this->published[0];
        $this->assertSame('reply.head', $reply['event']);
        $this->assertArrayNotHasKey('phase', $reply['data'], 'buffered HEAD must publish one reply, not phases');
        $this->assertSame(200, $reply['data']['status']);
        // The head's Content-Length + range support are carried through verbatim.
        $this->assertSame('12345', $reply['data']['headers']['Content-Length'] ?? null);
        $this->assertSame('bytes', $reply['data']['headers']['Accept-Ranges'] ?? null);
        // No body frame was sent → the assembled body is empty.
        $this->assertSame('', $reply['data']['body']);

        // The pending entry is torn down: a further frame for the same id is
        // dropped as unknown rather than re-completing the request.
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));
        $this->assertCount(1, $this->published);
    }

    /**
     * HB-0.3 ordering: a client probes with HEAD (headers + Content-Length,
     * empty body) then follows with a ranged GET that must return the requested
     * bytes. Both flow through the same manager on distinct request ids: the
     * buffered HEAD reply carries the size/range headers and no body, and the
     * ranged GET streams back a 206 with the exact range bytes.
     */
    public function test_head_then_ranged_get_returns_headers_then_bytes(): void
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

        // 1) HEAD probe (buffered): headers + Content-Length, empty body.
        $manager->onRequest([
            'request_id' => 'req-head',
            'reply_event' => 'reply.head',
            'server_id' => 'srv-1',
            'method' => 'HEAD',
            'path' => '/media/item-123/stream',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);
        $headReqId = (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $headReqId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(
                200,
                ['Content-Length' => '10', 'Accept-Ranges' => 'bytes'],
                10,
            )),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $headReqId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        $this->assertCount(1, $this->published);
        $this->assertSame(200, $this->published[0]['data']['status']);
        $this->assertSame('10', $this->published[0]['data']['headers']['Content-Length'] ?? null);
        $this->assertSame('bytes', $this->published[0]['data']['headers']['Accept-Ranges'] ?? null);
        $this->assertSame('', $this->published[0]['data']['body'], 'a HEAD carries no body');

        // 2) Ranged GET (streamed): 206 + Content-Range + the requested bytes.
        $manager->onRequest([
            'request_id' => 'req-get',
            'reply_event' => 'reply.get',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/media/item-123/stream',
            'query' => '',
            'headers' => ['Range' => 'bytes=0-4'],
            'body_b64' => '',
            'stream' => true,
        ]);
        $getReqId = (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $getReqId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(
                206,
                ['Content-Range' => 'bytes 0-4/10', 'Content-Length' => '5'],
                5,
            )),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $getReqId,
            RelayHttpResponseCodec::encodeBody('Hello'),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $getReqId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // HEAD reply (1) + GET head/body/end phases (3) = 4 published entries.
        $this->assertCount(4, $this->published);
        $this->assertSame('head', $this->published[1]['data']['phase']);
        $this->assertSame(206, $this->published[1]['data']['status']);
        $this->assertSame('bytes 0-4/10', $this->published[1]['data']['headers']['Content-Range'] ?? null);
        $this->assertSame('body', $this->published[2]['data']['phase']);
        $this->assertSame('Hello', $this->published[2]['data']['body'], 'the ranged GET returns the requested bytes');
        $this->assertSame('end', $this->published[3]['data']['phase']);
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
        $this->assertSame('foo', $this->published[1]['data']['body']);
        $this->assertSame('body', $this->published[2]['data']['phase']);
        $this->assertSame('bar', $this->published[2]['data']['body']);

        $this->assertSame('end', $this->published[3]['data']['phase']);
        $this->assertArrayNotHasKey('body', $this->published[3]['data']);

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
     * Regression for the MINOR finding: a steadily-dripping response could
     * keep the entry alive forever via constant re-arming, so an absolute
     * duration ceiling is enforced independently of activity. The sweep timer
     * (HB-4.8) checks MAX_STREAM_DURATION_SECONDS on each pass. Rather than
     * actually sleeping past the (multi-minute) ceiling, the pending entry's
     * `stream_opened_at` is rewritten into the past via reflection to simulate
     * "this stream has been open a long time" — the sweep must then terminate
     * the stream.
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

        // Rewrite the entry's clock into the distant past — simulates a stream
        // that has been open far beyond the absolute ceiling without actually
        // sleeping the test.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $pending[$requestId]['stream_opened_at'] = microtime(true) - 3600.0;
        $pending[$requestId]['sent_at'] = microtime(true) - 3600.0;
        $pendingProp->setValue($manager, $pending);

        // Simulate the head arriving (stream_started = true).
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '1000000'], 1000000)),
        ));

        // Run the sweep — it must detect the exceeded absolute duration.
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

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

    /**
     * With the HB-4.8 sweep approach, streaming frames do NOT re-arm any
     * per-request timer — the sweep periodically checks all entries. This test
     * verifies that a stream that is still within both its inactivity timeout
     * and absolute duration ceiling remains pending after frames arrive, and
     * the sweep does NOT prematurely terminate it.
     */
    public function test_streaming_frames_preserve_pending_entry_until_sweep_timeout(): void
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
            'path' => '/media/item/stream',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $this->assertNotNull($reqFrame);
        $requestId = $reqFrame->seq;

        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);

        // Stream is well within both the inactivity timeout and absolute ceiling.
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $this->assertArrayHasKey($requestId, $pending, 'entry must be pending before frames');
        $this->assertArrayNotHasKey('timer', $pending[$requestId], 'HB-4.8: no per-request timer field');
        $this->assertArrayNotHasKey('timer_armed_at', $pending[$requestId], 'HB-4.8: no timer_armed_at field');

        // Send head frame.
        $publishedBefore = count($this->published);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '1000000'], 1000000)),
        ));

        // The head phase is published; no terminating end phase.
        $this->assertSame($publishedBefore + 1, count($this->published));
        $this->assertSame('head', $this->published[array_key_last($this->published)]['data']['phase']);

        // Send body frame.
        $publishedBefore = count($this->published);
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('x'),
        ));

        // The body phase is published; no terminating end phase.
        $this->assertSame($publishedBefore + 1, count($this->published));
        $this->assertSame('body', $this->published[array_key_last($this->published)]['data']['phase']);

        // Entry is still pending (no sweep timeout yet).
        /** @var array<int, array<string, mixed>> $after */
        $after = $pendingProp->getValue($manager);
        $this->assertArrayHasKey($requestId, $after, 'stream must remain pending after frames');

        // Run the sweep — it must NOT terminate this fresh entry.
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

        // No new publishes — entry is still alive.
        $this->assertCount($publishedBefore + 1, $this->published);
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
     * HB-4.8 sweep approach: onRequest() stores the COERCED timeout in the
     * pending entry; the sweep timer uses it to determine inactivity expiry.
     * This tests that the coerced timeout is stored correctly for the sweep.
     */
    public function test_on_request_stores_coerced_timeout_in_pending_entry(): void
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

        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);

        // A valid streaming timeout is stored verbatim.
        $manager->onRequest($this->proxyPayload(['timeout' => 60]));
        $pending = $pendingProp->getValue($manager);
        $this->assertSame(60.0, end($pending)['timeout'], 'a valid timeout is stored verbatim');

        // An absent field falls back to the injected default (30s).
        $manager->onRequest($this->proxyPayload());
        $pending = $pendingProp->getValue($manager);
        $this->assertSame(30.0, end($pending)['timeout'], 'an absent timeout falls back to the injected default');

        // A negative value falls back to the injected default (30s).
        $manager->onRequest($this->proxyPayload(['timeout' => -5]));
        $pending = $pendingProp->getValue($manager);
        $this->assertSame(30.0, end($pending)['timeout'], 'a negative timeout falls back to the injected default');

        // A non-numeric value falls back to the injected default (30s).
        $manager->onRequest($this->proxyPayload(['timeout' => 'bogus']));
        $pending = $pendingProp->getValue($manager);
        $this->assertSame(30.0, end($pending)['timeout'], 'a non-numeric timeout falls back to the injected default');
    }

    // ---------------------------------------------------------------------
    // HB-2.4: O(1) cancel index
    // ---------------------------------------------------------------------

    /**
     * Verifies that onCancel uses O(1) map lookup rather than a linear scan.
     * The manager is populated with many pending entries; cancelling one
     * specific clientRequestId must remove only that entry from pending,
     * leaving all others intact.
     */
    public function test_cancel_uses_o1_lookup(): void
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

        // Inject a large batch of pending entries via reflection so onCancel
        // must find ours among many — proving it uses the O(1) map, not a scan.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        $clientToRelayProp = new ReflectionProperty(RelayProxyManager::class, 'clientToRelayRequestId');
        $clientToRelayProp->setAccessible(true);

        // Build many unrelated pending entries (each with a distinct
        // clientRequestId so they don't clash in the map).
        $hubRequestId = 0x80000001;
        /** @var array<int, array<string, mixed>> $pending */
        $pending = [];
        /** @var array<string, int> $clientToRelay */
        $clientToRelay = [];
        for ($i = 0; $i < 100; $i++) {
            $cid = 'distractor-req-' . $i;
            $pending[$hubRequestId + $i] = [
                'reply_event' => 'reply.distract',
                'request_id' => $cid,
                'server_id' => 'srv-1',
                'head' => null,
                'body' => '',
                'stream' => false,
                'stream_started' => false,
                'timeout' => 30.0,
                'stream_opened_at' => microtime(true),
            ];
            $clientToRelay[$cid] = $hubRequestId + $i;
        }

        // Our actual target entry.
        $targetClientId = 'target-req';
        $targetRelayId = 0xFFFFFFFF;
        $pending[$targetRelayId] = [
            'reply_event' => 'reply.target',
            'request_id' => $targetClientId,
            'server_id' => 'srv-1',
            'head' => null,
            'body' => '',
            'stream' => false,
            'stream_started' => false,
            'timeout' => 30.0,
            'stream_opened_at' => microtime(true),
        ];
        $clientToRelay[$targetClientId] = $targetRelayId;

        $pendingProp->setValue($manager, $pending);
        $clientToRelayProp->setValue($manager, $clientToRelay);

        // Cancel the target entry via its clientRequestId — O(1) lookup.
        $manager->onCancel([
            'request_id' => $targetClientId,
            'server_id' => 'srv-1',
        ]);

        // The target must be gone from pending; all 100 distractors must remain.
        /** @var array<int, array<string, mixed>> $remaining */
        $remaining = $pendingProp->getValue($manager);
        $this->assertArrayNotHasKey($targetRelayId, $remaining);
        $this->assertCount(100, $remaining, 'all 100 distractor entries must remain after O(1) cancel');

        // The map must also be clean.
        /** @var array<string, int> $map */
        $map = $clientToRelayProp->getValue($manager);
        $this->assertArrayNotHasKey($targetClientId, $map);
        $this->assertCount(100, $map, 'map must have 100 distractor entries remaining');
    }

    /**
     * Verifies the clientRequestId → relayRequestId map is cleared when a
     * request completes via onResponseFrame END (buffered path).
     */
    public function test_client_to_relay_map_cleared_on_response_end(): void
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

        $clientToRelayProp = new ReflectionProperty(RelayProxyManager::class, 'clientToRelayRequestId');
        $clientToRelayProp->setAccessible(true);

        $manager->onRequest([
            'request_id' => 'req-test',
            'reply_event' => 'reply.test',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        /** @var array<string, int> $mapBefore */
        $mapBefore = $clientToRelayProp->getValue($manager);
        $this->assertArrayHasKey('req-test', $mapBefore, 'map must have entry before END');

        // Complete via onResponseFrame END (buffered path).
        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $requestId = $reqFrame->seq;
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        /** @var array<string, int> $mapAfter */
        $mapAfter = $clientToRelayProp->getValue($manager);
        $this->assertArrayNotHasKey('req-test', $mapAfter, 'map must be cleared after END');
    }

    /**
     * Verifies the clientRequestId → relayRequestId map is cleared on timeout.
     */
    public function test_client_to_relay_map_cleared_on_timeout(): void
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

        $clientToRelayProp = new ReflectionProperty(RelayProxyManager::class, 'clientToRelayRequestId');
        $clientToRelayProp->setAccessible(true);

        $manager->onRequest([
            'request_id' => 'req-timeout',
            'reply_event' => 'reply.timeout',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        /** @var array<string, int> $mapBefore */
        $mapBefore = $clientToRelayProp->getValue($manager);
        $this->assertArrayHasKey('req-timeout', $mapBefore, 'map must have entry before timeout');

        // Fire the timeout handler directly (no event loop in PHPUnit).
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $requestId = (int) (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;

        $onTimeout = new ReflectionMethod(RelayProxyManager::class, 'onTimeout');
        $onTimeout->setAccessible(true);
        $onTimeout->invoke($manager, $requestId);

        /** @var array<string, int> $mapAfter */
        $mapAfter = $clientToRelayProp->getValue($manager);
        $this->assertArrayNotHasKey('req-timeout', $mapAfter, 'map must be cleared after timeout');
    }

    /**
     * Verifies the clientRequestId → relayRequestId map is cleared when
     * failServer removes pending entries for a server.
     */
    public function test_client_to_relay_map_cleared_on_fail_server(): void
    {
        $sent = [];
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });
        $tunnel = $this->activeTunnel('srv-fail', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $clientToRelayProp = new ReflectionProperty(RelayProxyManager::class, 'clientToRelayRequestId');
        $clientToRelayProp->setAccessible(true);

        $manager->onRequest([
            'request_id' => 'req-fail',
            'reply_event' => 'reply.fail',
            'server_id' => 'srv-fail',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        /** @var array<string, int> $mapBefore */
        $mapBefore = $clientToRelayProp->getValue($manager);
        $this->assertArrayHasKey('req-fail', $mapBefore, 'map must have entry before failServer');

        $manager->failServer('srv-fail');

        /** @var array<string, int> $mapAfter */
        $mapAfter = $clientToRelayProp->getValue($manager);
        $this->assertArrayNotHasKey('req-fail', $mapAfter, 'map must be cleared after failServer');
    }

    /**
     * Verifies the clientRequestId → relayRequestId map is cleared when
     * onCancel successfully cancels an in-flight request.
     */
    public function test_client_to_relay_map_cleared_on_cancel(): void
    {
        $sent = [];
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });
        $tunnel = $this->activeTunnel('srv-cancel', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $manager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $clientToRelayProp = new ReflectionProperty(RelayProxyManager::class, 'clientToRelayRequestId');
        $clientToRelayProp->setAccessible(true);

        $manager->onRequest([
            'request_id' => 'req-cancel',
            'reply_event' => 'reply.cancel',
            'server_id' => 'srv-cancel',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        /** @var array<string, int> $mapBefore */
        $mapBefore = $clientToRelayProp->getValue($manager);
        $this->assertArrayHasKey('req-cancel', $mapBefore, 'map must have entry before onCancel');

        $manager->onCancel([
            'request_id' => 'req-cancel',
            'server_id' => 'srv-cancel',
        ]);

        /** @var array<string, int> $mapAfter */
        $mapAfter = $clientToRelayProp->getValue($manager);
        $this->assertArrayNotHasKey('req-cancel', $mapAfter, 'map must be cleared after onCancel');
    }

    // ---------------------------------------------------------------------
    // HB-4.8: Batch sweep timer (replaces per-request timer churn)
    // ---------------------------------------------------------------------

    /**
     * Verifies that sweepStreamTimers() times out a request that has exceeded
     * its inactivity timeout (sent_at + timeout).
     */
    public function test_sweep_times_out_inactive_request(): void
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
            'request_id' => 'req-timeout',
            'reply_event' => 'reply.timeout',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'timeout' => 30,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $requestId = $reqFrame->seq;

        // Rewind the sent_at clock to simulate a request that's been waiting
        // longer than its timeout.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $pending[$requestId]['sent_at'] = microtime(true) - 60.0; // 60s ago > 30s timeout
        $pendingProp->setValue($manager, $pending);

        // Run the sweep.
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

        // The entry must be timed out: a 504 reply is published.
        $this->assertCount(1, $this->published);
        $this->assertSame(504, $this->published[0]['data']['status']);

        // The pending entry is gone.
        /** @var array<int, array<string, mixed>> $remaining */
        $remaining = $pendingProp->getValue($manager);
        $this->assertArrayNotHasKey($requestId, $remaining);
    }

    /**
     * Verifies that sweepStreamTimers() terminates a streaming entry that has
     * exceeded MAX_STREAM_DURATION_SECONDS (absolute duration ceiling).
     */
    public function test_sweep_terminates_stream_exceeding_absolute_duration(): void
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
            'request_id' => 'req-stream',
            'reply_event' => 'reply.stream',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/hls/seg.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $requestId = $reqFrame->seq;

        // Send HEAD first so stream_started = true (END phase requires this).
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '1000000'], 1000000)),
        ));

        // Simulate a stream that has been open far beyond the absolute ceiling.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $pending[$requestId]['stream_opened_at'] = microtime(true) - 3600.0; // 1 hour ago > 900s ceiling
        $pending[$requestId]['sent_at'] = microtime(true) - 3600.0;
        $pendingProp->setValue($manager, $pending);

        // Run the sweep.
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

        // The entry is terminated with an END phase (stream already started).
        $this->assertCount(2, $this->published);
        $this->assertSame('head', $this->published[0]['data']['phase']);
        $this->assertSame('end', $this->published[1]['data']['phase']);

        // The pending entry is gone.
        /** @var array<int, array<string, mixed>> $remaining */
        $remaining = $pendingProp->getValue($manager);
        $this->assertArrayNotHasKey($requestId, $remaining);
    }

    /**
     * Verifies that sweepStreamTimers() does NOT affect a request that is
     * still within its timeout window.
     */
    public function test_sweep_does_not_affect_active_requests(): void
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
            'request_id' => 'req-active',
            'reply_event' => 'reply.active',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'timeout' => 30,
        ]);

        $reqFrame = (new FrameDecoder())->decode($sent[count($sent) - 1]);
        $requestId = $reqFrame->seq;

        // Run the sweep on a fresh request (well within timeout).
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

        // No reply published — the request is still alive.
        $this->assertCount(0, $this->published);

        // The pending entry is still present.
        $pendingProp = new ReflectionProperty(RelayProxyManager::class, 'pending');
        $pendingProp->setAccessible(true);
        /** @var array<int, array<string, mixed>> $pending */
        $pending = $pendingProp->getValue($manager);
        $this->assertArrayHasKey($requestId, $pending);
    }

    /**
     * Verifies that sweepStreamTimers() handles an empty pending array without
     * errors (early return).
     */
    public function test_sweep_handles_empty_pending(): void
    {
        $manager = new RelayProxyManager(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
        );

        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);

        // Must not throw.
        $sweep->invoke($manager);

        $this->assertCount(0, $this->published);
    }

    /**
     * Verifies that sweepStreamTimers() gracefully skips an entry that has
     * already been removed by another mechanism (e.g. failServer, cancelRequest).
     */
    public function test_sweep_skips_already_removed_entries(): void
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
            'request_id' => 'req-removed',
            'reply_event' => 'reply.removed',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        // Remove the entry via failServer before the sweep runs.
        $manager->failServer('srv-1');

        // Run the sweep — must not throw and must not publish anything for
        // the already-removed entry.
        $sweep = new ReflectionMethod(RelayProxyManager::class, 'sweepStreamTimers');
        $sweep->setAccessible(true);
        $sweep->invoke($manager);

        // failServer published 503; sweep must not have published anything else.
        $this->assertCount(1, $this->published);
        $this->assertSame(503, $this->published[0]['data']['status']);
    }

    // ---------------------------------------------------------------------
    // HB-4.1: relay observability metric EMISSION on the real paths.
    // A real MetricsCollector wrapping a real MetricsRegistry is injected so
    // these assert the recorded state end-to-end (not a mock's call count) —
    // guarding the exact regression the audit found (metrics recorded under
    // `$this->metrics?->…` but the collector was always null, so every record
    // was a no-op).
    // ---------------------------------------------------------------------

    /**
     * Build a manager whose metrics land in the given real registry.
     */
    private function meteredManager(TunnelManagerInterface $tunnelManager, MetricsRegistry $registry): RelayProxyManager
    {
        return new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            $this->publisher(),
            new MetricsCollector($registry, true),
        );
    }

    /**
     * Read a private int counter/gauge off the registry without draining it.
     */
    private function registryInt(MetricsRegistry $registry, string $property): int
    {
        $p = new ReflectionProperty(MetricsRegistry::class, $property);
        $p->setAccessible(true);
        /** @var int $v */
        $v = $p->getValue($registry);
        return $v;
    }

    /**
     * Total number of relay-latency observations recorded (sum of every
     * per-bucket histogram cell), read non-destructively.
     */
    private function relayLatencyObservationCount(MetricsRegistry $registry): int
    {
        $p = new ReflectionProperty(MetricsRegistry::class, 'relayLatencyHistogram');
        $p->setAccessible(true);
        /** @var array<int, array<int, int>> $hist */
        $hist = $p->getValue($registry);
        $total = 0;
        foreach ($hist as $bucket) {
            $total += array_sum($bucket);
        }
        return $total;
    }

    /**
     * A mocked TcpConnection whose send() always succeeds, capturing bytes.
     *
     * @param array<int, string> $sent
     */
    private function capturingServerWs(array &$sent): TcpConnection
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(static function (mixed $data) use (&$sent): bool {
            $sent[] = is_string($data) ? $data : '';
            return true;
        });
        return $serverWs;
    }

    public function test_pending_gauge_increments_on_request_and_decrements_on_completion(): void
    {
        $sent = [];
        $tunnel = $this->activeTunnel('srv-1', $this->capturingServerWs($sent));
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

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

        // The pending gauge tracks the one in-flight request.
        $this->assertSame(1, $this->registryInt($registry, 'relayPendingRequests'));

        $requestId = (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, [], 0)),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // Back to zero on completion.
        $this->assertSame(0, $this->registryInt($registry, 'relayPendingRequests'));
    }

    public function test_no_tunnel_branch_records_relay_error_503(): void
    {
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn(null);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-offline',
            'method' => 'GET',
            'path' => '/x',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
        ]);

        // The 503 body still carries server.no_tunnel AND the error is counted
        // (previously this fail-fast branch recorded no metric at all).
        $this->assertSame(503, $this->published[0]['data']['status']);
        $this->assertSame(1, $this->registryInt($registry, 'relayError503'));
        $this->assertSame(0, $this->registryInt($registry, 'relayError504'));
    }

    public function test_fail_server_records_relay_error_503(): void
    {
        $sent = [];
        $tunnel = $this->activeTunnel('srv-1', $this->capturingServerWs($sent));
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

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

        // The tunnel-dropped 503 path is counted too, and the gauge drains to 0.
        $this->assertSame(1, $this->registryInt($registry, 'relayError503'));
        $this->assertSame(0, $this->registryInt($registry, 'relayPendingRequests'));
    }

    public function test_timeout_records_relay_error_504(): void
    {
        $sent = [];
        $tunnel = $this->activeTunnel('srv-1', $this->capturingServerWs($sent));
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

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

        $requestId = (int) (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;

        $onTimeout = new ReflectionMethod(RelayProxyManager::class, 'onTimeout');
        $onTimeout->setAccessible(true);
        $onTimeout->invoke($manager, $requestId);

        $this->assertSame(504, $this->published[0]['data']['status']);
        $this->assertSame(1, $this->registryInt($registry, 'relayError504'));
        $this->assertSame(0, $this->registryInt($registry, 'relayError503'));
    }

    public function test_unknown_response_frame_records_reply_drop(): void
    {
        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager(
            $this->createMock(TunnelManagerInterface::class),
            $registry,
        );

        // No pending entry for id 999 → the HTTP_RESPONSE is dropped + counted.
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            999,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        $this->assertSame(1, $this->registryInt($registry, 'relayReplyDrops'));
        $this->assertCount(0, $this->published);
    }

    public function test_buffered_request_records_first_byte_and_total_latency(): void
    {
        $sent = [];
        $tunnel = $this->activeTunnel('srv-1', $this->capturingServerWs($sent));
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

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
        $requestId = (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;

        // No latency yet — nothing has come back.
        $this->assertSame(0, $this->relayLatencyObservationCount($registry));

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, [], 3)),
        ));
        // First-byte recorded on the HEAD frame.
        $this->assertSame(1, $this->relayLatencyObservationCount($registry));

        foreach (RelayHttpResponseCodec::chunkBody('abc') as $bodyChunk) {
            $manager->onResponseFrame(new RelayFrame(RelayFrameType::HTTP_RESPONSE, $requestId, $bodyChunk));
        }
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // Total recorded on END → exactly two observations (first-byte + total).
        $this->assertSame(2, $this->relayLatencyObservationCount($registry));
    }

    public function test_streaming_request_records_first_byte_and_total_latency(): void
    {
        $sent = [];
        $tunnel = $this->activeTunnel('srv-1', $this->capturingServerWs($sent));
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $registry = new MetricsRegistry(10);
        $manager  = $this->meteredManager($tunnelManager, $registry);

        $manager->onRequest([
            'request_id' => 'req-1',
            'reply_event' => 'reply.1',
            'server_id' => 'srv-1',
            'method' => 'GET',
            'path' => '/hls/job/seg-1.ts',
            'query' => '',
            'headers' => [],
            'body_b64' => '',
            'stream' => true,
        ]);
        $requestId = (new FrameDecoder())->decode($sent[count($sent) - 1])->seq;

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(200, ['Content-Length' => '6'], 6)),
        ));
        // First-byte recorded on the streamed HEAD (previously the streaming
        // path recorded NO latency at all).
        $this->assertSame(1, $this->relayLatencyObservationCount($registry));

        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeBody('foobar'),
        ));
        $manager->onResponseFrame(new RelayFrame(
            RelayFrameType::HTTP_RESPONSE,
            $requestId,
            RelayHttpResponseCodec::encodeEnd(),
        ));

        // Total recorded at stream completion (END) → two observations.
        $this->assertSame(2, $this->relayLatencyObservationCount($registry));
    }
}
