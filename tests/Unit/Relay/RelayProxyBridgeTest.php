<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\RelayResponseSink;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Workerman\Coroutine\Channel;

use function array_filter;
use function array_key_last;
use function array_values;
use function base64_decode;
use function base64_encode;
use function count;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * @covers \Phlix\Hub\Relay\RelayProxyBridge
 */
final class RelayProxyBridgeTest extends TestCase
{
    public function test_reply_event_is_unique_per_instance(): void
    {
        $a = new RelayProxyBridge($this->createMock(StructuredLogger::class));
        $b = new RelayProxyBridge($this->createMock(StructuredLogger::class));

        $this->assertStringStartsWith('phlix.relay.proxy.reply.', $a->replyEvent());
        $this->assertNotSame($a->replyEvent(), $b->replyEvent());
    }

    public function test_request_publishes_envelope_and_returns_reply(): void
    {
        /** @var array<string, mixed>|null $publishedData */
        $publishedData = null;
        $publishedEvent = null;

        // The publisher stands in for the relay worker: echo a reply straight
        // back through onReply so the blocked request resolves synchronously.
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$publishedData, &$publishedEvent): void {
            $publishedEvent = $event;
            $publishedData = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body_b64' => base64_encode('{"ok":true}'),
            ]);
        };

        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $result = $bridge->request(
            'srv-1',
            'GET',
            '/api/v1/libraries',
            'a=1',
            ['Accept' => 'application/json'],
            '',
            5.0,
        );

        $this->assertSame(RelayProxyProtocol::REQUEST_EVENT, $publishedEvent);
        $this->assertIsArray($publishedData);
        $this->assertSame('srv-1', $publishedData['server_id']);
        $this->assertSame('GET', $publishedData['method']);
        $this->assertSame('/api/v1/libraries', $publishedData['path']);
        $this->assertSame('a=1', $publishedData['query']);
        $this->assertSame($bridge->replyEvent(), $publishedData['reply_event']);

        $this->assertIsArray($result);
        $this->assertSame(200, $result['status']);
        $this->assertSame('{"ok":true}', base64_decode((string) $result['body_b64'], true));
    }

    public function test_request_returns_null_when_no_reply_arrives(): void
    {
        // Publisher does nothing → the channel stays empty → pop returns false.
        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data): void {
                // no reply
            },
        );

        $result = $bridge->request('srv-1', 'GET', '/x', '', [], '', 0.01);
        $this->assertNull($result);
    }

    public function test_on_reply_for_unknown_request_is_noop(): void
    {
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class));
        // Should not throw.
        $bridge->onReply(['request_id' => 'nope', 'status' => 200]);
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{0: float}>
     */
    public static function forwardedTimeoutProvider(): iterable
    {
        yield 'default 30s' => [30.0];
        yield 'streaming ceiling 60s' => [60.0];
        yield 'fractional' => [42.5];
    }

    /**
     * D3: the caller's per-request reply timeout must round-trip into the
     * published envelope so the relay worker (`RelayProxyManager`) can arm an
     * identical completion timer — otherwise the worker's own timer would 504 a
     * slow playback-read segment at the default before the browser-facing wait
     * elapsed. The value is threaded through the exact channel/publish seam the
     * existing envelope test uses.
     *
     * @dataProvider forwardedTimeoutProvider
     */
    public function test_request_forwards_the_timeout_in_the_published_envelope(float $timeout): void
    {
        /** @var array<string, mixed>|null $publishedData */
        $publishedData = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$publishedData): void {
            $publishedData = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => [],
                'body_b64' => base64_encode(''),
            ]);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $bridge->request('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', $timeout);

        $this->assertIsArray($publishedData);
        $this->assertArrayHasKey('timeout', $publishedData);
        $this->assertSame($timeout, $publishedData['timeout']);
    }

    /**
     * A recording {@see RelayResponseSink}. `$bodyReturn` lets a test simulate
     * the browser going away mid-stream (body() returning false).
     */
    private function recordingSink(bool $bodyReturn = true): RelayResponseSink
    {
        return new class ($bodyReturn) implements RelayResponseSink {
            /** @var list<array{0: string, 1?: mixed, 2?: mixed}> */
            public array $events = [];
            public string $body = '';

            public function __construct(private readonly bool $bodyReturn)
            {
            }

            public function head(int $status, array $headers): void
            {
                $this->events[] = ['head', $status, $headers];
            }

            public function body(string $bytes): bool
            {
                $this->events[] = ['body', $bytes];
                $this->body .= $bytes;
                return $this->bodyReturn;
            }

            public function end(): void
            {
                $this->events[] = ['end'];
            }

            public function abort(): void
            {
                $this->events[] = ['abort'];
            }
        };
    }

    /**
     * A {@see RelayResponseSink} whose `head()`/`body()` can be made to throw,
     * for the D3s re-review Finding B regression tests below (mid-stream
     * exception must not corrupt the connection with a second response).
     */
    private function throwingSink(bool $throwOnHead, bool $throwOnBody): RelayResponseSink
    {
        return new class ($throwOnHead, $throwOnBody) implements RelayResponseSink {
            public bool $headCalled = false;
            public bool $bodyCalled = false;
            public bool $endCalled = false;
            public bool $abortCalled = false;

            public function __construct(
                private readonly bool $throwOnHead,
                private readonly bool $throwOnBody,
            ) {
            }

            public function head(int $status, array $headers): void
            {
                $this->headCalled = true;
                if ($this->throwOnHead) {
                    throw new RuntimeException('boom-in-head');
                }
            }

            public function body(string $bytes): bool
            {
                $this->bodyCalled = true;
                if ($this->throwOnBody) {
                    throw new RuntimeException('boom-in-body');
                }
                return true;
            }

            public function end(): void
            {
                $this->endCalled = true;
            }

            public function abort(): void
            {
                $this->abortCalled = true;
            }
        };
    }

    public function test_stream_forwards_phased_body_to_the_sink_and_flags_the_envelope(): void
    {
        /** @var array<string, mixed>|null $publishedData */
        $publishedData = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$publishedData): void {
            $publishedData = $data;
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => ['Content-Length' => '6']]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('foo')]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('bar')]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $sink = $this->recordingSink();
        $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 5.0, $sink);

        // The envelope opts into streaming and carries the reply timeout.
        $this->assertIsArray($publishedData);
        $this->assertTrue($publishedData['stream']);
        $this->assertSame(5.0, $publishedData['timeout']);

        // Body arrives fragment-by-fragment (two body() calls, not one blob).
        $this->assertSame(
            [['head', 200, ['Content-Length' => '6']], ['body', 'foo'], ['body', 'bar'], ['end']],
            $sink->events,
        );
        $this->assertSame('foobar', $sink->body);
    }

    public function test_stream_emits_a_buffered_reply_as_head_body_end(): void
    {
        // A relay-worker error reply (or a legacy/non-streaming server) arrives as
        // a single buffered message with no `phase`; stream() must still drive the
        // sink head → body → end so the browser gets a well-formed response.
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 503,
                'headers' => ['Content-Type' => 'application/json'],
                'body_b64' => base64_encode('{"code":"server.no_tunnel"}'),
            ]);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $sink = $this->recordingSink();
        $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 5.0, $sink);

        $this->assertSame(['head', 503, ['Content-Type' => 'application/json']], $sink->events[0]);
        $this->assertSame('{"code":"server.no_tunnel"}', $sink->body);
        $this->assertSame(['end'], $sink->events[array_key_last($sink->events)]);
    }

    public function test_stream_synthesizes_a_504_when_no_reply_arrives(): void
    {
        // Publisher never replies → the first-phase wait elapses → 504 + end.
        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data): void {
                // no reply
            },
        );

        $sink = $this->recordingSink();
        $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 0.01, $sink);

        $this->assertSame('head', $sink->events[0][0]);
        $this->assertSame(504, $sink->events[0][1]);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($sink->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('gateway.timeout', $decoded['code'] ?? null);
        $this->assertSame(['end'], $sink->events[array_key_last($sink->events)]);
    }

    public function test_stream_stops_consuming_when_the_browser_is_gone(): void
    {
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => []]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('first')]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('second')]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        // Sink reports the browser gone on the first body() → streaming stops.
        $sink = $this->recordingSink(bodyReturn: false);
        $bridge->stream('srv-1', 'GET', '/media/item/stream', '', [], '', 5.0, $sink);

        // Head + exactly one body; the second body and the end are never delivered.
        $this->assertSame([['head', 200, []], ['body', 'first']], $sink->events);
    }

    /**
     * Regression for the `close()`-on-cleanup half of the MAJOR finding's fix:
     * flooding a request's per-request channel far beyond its bounded
     * capacity — modelling an abandoned/stuck consumer that stops draining
     * while replies keep arriving — must (a) drop the overflow rather than
     * queue it without bound, and (b) leave the channel CLOSED once
     * {@see RelayProxyBridge::stream()} stops consuming it, so any later
     * arrival fails fast instead of being silently accepted into a channel
     * nobody will ever read from again.
     *
     * **What this test does NOT prove** (documented precisely per the D3s
     * re-review's Finding A): under plain PHPUnit CLI, `Worker::$eventLoopClass`
     * is empty, so `Workerman\Coroutine\Channel` selects its non-blocking
     * `Memory` driver, whose `push()`/`pop()` never actually block regardless of
     * the `$timeout` argument — an "unbounded" push and a
     * `REPLY_PUSH_TIMEOUT_SECONDS`-bounded push behave IDENTICALLY here (both
     * return `false` instantly on overflow). So this test does not exercise
     * real timeout-bounded blocking; it exercises the drop-on-overflow and
     * close-on-cleanup behavior only. The push's actual timeout-bounding is
     * covered by
     * {@see self::test_on_reply_bounds_its_push_with_the_documented_timeout_and_drops_on_failure()}
     * (which asserts the exact timeout argument passed to `push()` directly,
     * independent of which channel driver Workerman selects) and otherwise
     * relies on Swoole's documented `Channel::push(mixed, float $timeout)`
     * semantics in production (see
     * {@see RelayProxyBridge::REPLY_PUSH_TIMEOUT_SECONDS}). All flooding
     * happens synchronously inside the publisher callback, i.e. BEFORE
     * `stream()`'s pop-loop ever runs (there is no real coroutine scheduler in
     * this process).
     */
    public function test_stream_drops_replies_beyond_channel_capacity_without_hanging_and_closes_the_channel(): void
    {
        /** @var Channel|null $capturedChannel */
        $capturedChannel = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$capturedChannel): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];

            // Capture the channel stream() is about to consume, before flooding
            // it, so we can inspect its post-stream() state directly.
            $reflected = new ReflectionProperty(RelayProxyBridge::class, 'pending');
            $reflected->setAccessible(true);
            /** @var array<string, Channel> $pendingMap */
            $pendingMap = $reflected->getValue($bridge);
            $capturedChannel = $pendingMap[$id] ?? null;

            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => []]);
            // Flood far beyond the channel's bounded capacity, all before
            // stream() ever starts popping.
            for ($i = 0; $i < 50; $i++) {
                $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('x')]);
            }
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $sink = $this->recordingSink();
        // Must return promptly — proves no producer stays blocked on this
        // channel (the assertion is that this call returns at all).
        $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 5.0, $sink);

        $this->assertNotNull($capturedChannel);

        // Every reply beyond capacity was dropped by the bounded push rather
        // than queued/hung — far fewer than the 50 pushed actually arrive.
        $bodyEvents = array_values(array_filter($sink->events, static fn (array $e): bool => $e[0] === 'body'));
        $this->assertGreaterThan(0, count($bodyEvents));
        $this->assertLessThan(50, count($bodyEvents));

        // The consumer still terminates cleanly (head, then a bounded run of
        // bodies, then a terminating end — even though the real 'end' phase
        // was itself dropped by the overflow, stream()'s own drained-channel
        // fallback synthesizes the end once no further messages arrive).
        $this->assertSame('head', $sink->events[0][0]);
        $this->assertSame('end', $sink->events[array_key_last($sink->events)][0]);

        // Finding-1 fix: the channel is closed once stream() stops consuming
        // it, so any further push fails immediately instead of hanging.
        $this->assertFalse($capturedChannel->push('late-arrival-after-stream-finished'));
    }

    /**
     * Regression for D3s re-review Finding A: directly proves `onReply()`
     * (a) passes the documented bounded timeout to `Channel::push()`, and
     * (b) drops and untracks the request when that push fails — independent
     * of which channel driver Workerman happens to select (unlike
     * {@see self::test_stream_drops_replies_beyond_channel_capacity_without_hanging_and_closes_the_channel()},
     * which relies on the non-blocking `Memory` driver's overflow behavior).
     * Uses a hand-rolled `Channel` subclass that overrides `push()` to record
     * every call and return a controlled result, injected directly into the
     * bridge's private `$pending` map via reflection — this isolates
     * `onReply()`'s own logic from `stream()`/`request()`'s channel creation
     * entirely, so it does not matter whether a real Swoole coroutine scheduler
     * is actually blocking that call in this process.
     */
    public function test_on_reply_bounds_its_push_with_the_documented_timeout_and_drops_on_failure(): void
    {
        $fakeChannel = new class (1) extends Channel {
            /** @var list<float> */
            public array $pushTimeouts = [];
            public bool $pushResult = false;

            public function push(mixed $data, float $timeout = -1): bool
            {
                $this->pushTimeouts[] = $timeout;
                return $this->pushResult;
            }
        };

        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class));

        $pendingProp = new ReflectionProperty(RelayProxyBridge::class, 'pending');
        $pendingProp->setAccessible(true);
        $pendingProp->setValue($bridge, ['req-1' => $fakeChannel]);

        // The push "fails" (models: channel closed, or genuinely stuck beyond
        // the bound) — onReply() must drop the reply and stop tracking it.
        $fakeChannel->pushResult = false;
        $bridge->onReply(['request_id' => 'req-1', 'phase' => 'body', 'body_b64' => base64_encode('x')]);

        $this->assertCount(1, $fakeChannel->pushTimeouts);
        // 45.0 == RelayProxyBridge::REPLY_PUSH_TIMEOUT_SECONDS (private; kept
        // as a literal here, matching this test suite's existing convention
        // of asserting against production constants by value, e.g.
        // `forwardedTimeoutProvider()` above).
        $this->assertSame(45.0, $fakeChannel->pushTimeouts[0]);

        // The entry must now be untracked: a second reply for the same id is
        // dropped BEFORE it ever reaches the channel — no further push call.
        $bridge->onReply(['request_id' => 'req-1', 'phase' => 'end']);
        $this->assertCount(1, $fakeChannel->pushTimeouts, 'a dropped request must stop being tracked');
    }

    /**
     * Regression for D3s re-review Finding B: if the sink throws BEFORE
     * `head()` has ever succeeded, nothing has been written to the connection
     * yet — it is safe (and correct) for the exception to propagate out of
     * `stream()` so the caller's normal buffered-error fallback can run
     * exactly once. `abort()` must NOT be called in this case (there is
     * nothing to abort; a clean single response is still possible).
     */
    public function test_stream_rethrows_when_head_itself_throws_before_any_bytes_are_written(): void
    {
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => []]);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $sink = $this->throwingSink(throwOnHead: true, throwOnBody: false);

        try {
            $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 5.0, $sink);
            $this->fail('Expected the head()-time exception to propagate out of stream().');
        } catch (RuntimeException $e) {
            $this->assertSame('boom-in-head', $e->getMessage());
        }

        $this->assertTrue($sink->headCalled);
        $this->assertFalse($sink->abortCalled, 'nothing was written yet — abort() must not be called');
        $this->assertFalse($sink->endCalled);
    }

    /**
     * Regression for D3s re-review Finding B (the substantive fix): once
     * `head()` has succeeded, real bytes (status line + headers) are already
     * on the wire. A LATER exception (here, from `body()`) must be caught
     * internally and turned into `abort()` — it must NOT propagate out of
     * `stream()`, because a caller catching it and sending its own fresh
     * buffered response would write a second, unrelated response onto a
     * connection that already carries partial HTTP framing.
     */
    public function test_stream_aborts_instead_of_rethrowing_when_body_throws_after_head_succeeded(): void
    {
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => []]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body_b64' => base64_encode('x')]);
        };
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $sink = $this->throwingSink(throwOnHead: false, throwOnBody: true);

        // Must return normally — no exception may escape.
        $bridge->stream('srv-1', 'GET', '/hls/job/seg-00007.ts', '', [], '', 5.0, $sink);

        $this->assertTrue($sink->headCalled);
        $this->assertTrue($sink->bodyCalled);
        $this->assertTrue($sink->abortCalled, 'bytes were already written — the sink must be aborted');
        $this->assertFalse($sink->endCalled, 'end() must not run after an abort()');
    }
}
