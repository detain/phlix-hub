<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Updates;

use Phlix\Hub\Hub\Updates\AsyncVersionMarkerFetcher;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Tests\Support\Updates\RecordingVersionHttpClient;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\Http\Client;
use Workerman\Timer;

/**
 * {@see AsyncVersionMarkerFetcher} — the only class in S75 that touches the
 * network.
 *
 * The {@see Client} is injected as a subclass double, so every assertion is
 * about what THIS class does with the client's callbacks; nothing here opens a
 * socket. The point of the suite is the failure taxonomy: a fetcher used from
 * a Workerman timer must convert EVERY outcome (transport error, non-response,
 * oversized body, a synchronous throw from the client itself) into exactly one
 * `$onDone` invocation, because a throw escaping here lands in the maintenance
 * worker's tick.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Updates
 */
final class AsyncVersionMarkerFetcherTest extends TestCase
{
    // Workerman's Timer statics and Worker registry are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use WorkermanTimerRuntimeControl;

    private const URL = 'https://example.invalid/VERSION';

    /**
     * Client double: records the URL and hands the registered callbacks back to
     * the test so it can drive success/error paths deterministically.
     */
    private function client(): RecordingVersionHttpClient
    {
        return new RecordingVersionHttpClient();
    }

    /** A minimal PSR-7-ish response carrying `$body`. */
    private function response(string $body): object
    {
        return new class ($body) {
            public function __construct(private readonly string $body)
            {
            }

            public function getBody(): string
            {
                return $this->body;
            }
        };
    }

    public function testItImplementsTheFetcherInterface(): void
    {
        self::assertInstanceOf(VersionMarkerFetcherInterface::class, new AsyncVersionMarkerFetcher(5));
    }

    public function testASuccessfulResponseYieldsTheBodyAndNoError(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertSame(self::URL, $client->url);
        self::assertSame(['GET'], $client->methods, 'the marker must be fetched with GET');
        self::assertSame([], $seen, 'the callback must not fire before the client answers');

        // Supplying BOTH callbacks is what keeps Client::request() out of its
        // coroutine-suspending branch — i.e. what makes this non-blocking with
        // no `inCoroutine()` fork.
        self::assertIsCallable($client->error, 'an error callback must always be supplied');

        self::assertIsCallable($client->success);
        ($client->success)($this->response("0.9.9\n"));

        self::assertSame([["0.9.9\n", null]], $seen);
    }

    public function testATransportErrorYieldsTheMessageAndNoBody(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->error);
        ($client->error)(new \RuntimeException('connection refused'));

        self::assertSame([[null, 'connection refused']], $seen);
    }

    public function testANonThrowableErrorArgumentStillProducesAMessage(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->error);
        ($client->error)(null);

        self::assertCount(1, $seen);
        self::assertNull($seen[0][0]);
        self::assertIsString($seen[0][1]);
        self::assertNotSame('', $seen[0][1]);
    }

    /**
     * An error page / captive portal must be rejected at the transport edge,
     * before the service ever tries to parse it as a version.
     */
    public function testAnOversizedBodyIsRejected(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->success);
        ($client->success)($this->response(str_repeat('x', AsyncVersionMarkerFetcher::MAX_BODY_BYTES + 1)));

        self::assertCount(1, $seen);
        self::assertNull($seen[0][0]);
        self::assertIsString($seen[0][1]);
        self::assertStringContainsString('exceeds', $seen[0][1]);
    }

    public function testABodyExactlyAtTheLimitIsAccepted(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->success);
        $exact = str_repeat('x', AsyncVersionMarkerFetcher::MAX_BODY_BYTES);
        ($client->success)($this->response($exact));

        self::assertSame([[$exact, null]], $seen);
    }

    public function testAResponseWithoutABodyAccessorIsAnError(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->success);
        ($client->success)('not a response');

        self::assertCount(1, $seen);
        self::assertNull($seen[0][0]);
        self::assertIsString($seen[0][1]);
    }

    /**
     * `Client::request()` can throw synchronously (e.g. an unparseable URL).
     * That must arrive as an error, not as an exception in the timer tick.
     */
    public function testASynchronousClientThrowBecomesAnError(): void
    {
        $client             = $this->client();
        $client->throwOnGet = new \RuntimeException('bad address');
        $fetcher            = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch('not-a-url', static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertSame([[null, 'bad address']], $seen);
    }

    /**
     * Exactly one completion, even if the client (or a retry layer) fires both
     * callbacks — a double completion would double-write the settings rows.
     */
    public function testTheCompletionCallbackFiresAtMostOnce(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertIsCallable($client->success);
        self::assertIsCallable($client->error);
        ($client->success)($this->response('0.9.9'));
        ($client->error)(new \RuntimeException('late failure'));
        ($client->success)($this->response('1.0.0'));

        self::assertSame([['0.9.9', null]], $seen);
    }

    /**
     * The client is created LAZILY and then REUSED: a fresh connection pool per
     * poll would leak sockets in a resident-memory process.
     *
     * A host-less URL makes `Client::parseAddress()` throw before any socket is
     * attempted, so the client is constructed but nothing is dialled. This test
     * deliberately asserts NOTHING about when the error callback arrives — see
     * {@see testTheVendorDefersASynchronousFailureThroughATimer} for why that
     * timing is environment-dependent.
     */
    public function testTheClientIsCreatedLazilyAndReused(): void
    {
        $fetcher  = new AsyncVersionMarkerFetcher(5);
        $property = (new ReflectionClass(AsyncVersionMarkerFetcher::class))->getProperty('client');
        $property->setAccessible(true);

        self::assertNull($property->getValue($fetcher), 'no client may exist before the first fetch');

        $noop = static function (?string $body, ?string $error): void {
        };

        $fetcher->fetch('not-a-url', $noop);
        $first = $property->getValue($fetcher);
        self::assertInstanceOf(Client::class, $first);

        $fetcher->fetch('not-a-url', $noop);
        self::assertSame($first, $property->getValue($fetcher), 'the client must be reused, not rebuilt');
    }

    /**
     * The REAL vendor error path, pinned against the real {@see Client}.
     *
     * MEASURED, not assumed: `Client::request()` catches a synchronous failure
     * (e.g. an unparseable URL) and, in callback mode, does NOT rethrow — it
     * DEFERS the error callback onto a one-shot
     * `Timer::add(0.000001, $error, [$exception], false)`
     * (vendor `Client.php:393-405`) and returns null. So in production, where an
     * event loop exists, even an instantly-invalid URL reports back on a later
     * tick, never inside `fetch()`.
     *
     * This is exactly the axis that made an earlier version of this suite
     * order-dependent: with no `Worker::$workers` seeded, `Timer::add()` itself
     * throws (`Timer.php:155`) and the failure surfaces synchronously through
     * `fetch()`'s own catch instead — so the same call reports at two different
     * times depending on what ran before it. The registry is therefore seeded
     * here so the PRODUCTION (deferred) arm is what gets exercised, and the
     * deferred task is invoked explicitly rather than waited for.
     */
    public function testTheVendorDefersASynchronousFailureThroughATimer(): void
    {
        // Seeds Worker::$workers so Timer::add() reaches its task-table path
        // instead of throwing. The trait restores the previous value afterwards,
        // so the `finally` that used to do it by hand is no longer needed.
        $this->forceWorkermanRuntime();

        $fetcher = new AsyncVersionMarkerFetcher(5);

        $seen = [];
        $fetcher->fetch('not-a-url', static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertCount(0, $seen, 'the vendor defers the error — it must not arrive inside fetch()');

        $tasks = $this->pendingTimerTasks();
        self::assertNotSame([], $tasks, 'the vendor must have deferred an error callback onto a timer');

        foreach ($tasks as $task) {
            ($task[0])(...$task[1]);
        }

        self::assertCount(1, $seen, 'exactly one completion, on the deferred tick');
        self::assertNull($seen[0][0]);
        self::assertIsString($seen[0][1]);
        self::assertStringContainsString('invalid url', $seen[0][1]);
    }

    // ===== Fastly → api.github.com fallback leg ==============================

    private const GITHUB_URL = 'https://raw.githubusercontent.com/detain/phlix-hub/master/VERSION';

    private const GITHUB_FALLBACK_URL =
        'https://api.github.com/repos/detain/phlix-hub/contents/VERSION?ref=master';

    /**
     * (a) A transport error on a raw-github marker re-issues against
     * api.github.com with the derived contents URL and the raw-accept header; a
     * success there is a CLEAN status with no fallback trace.
     */
    public function testAGithubRawErrorFallsBackToApiContentsAndSucceeds(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::GITHUB_URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        self::assertSame(1, $client->requestCount());
        self::assertSame(self::GITHUB_URL, $client->urlOf(0), 'the primary leg is the raw marker');

        // The primary leg fails — this is the Fastly read-timeout symptom.
        ($client->errorOf(0))(new \RuntimeException('read timeout'));

        self::assertSame([], $seen, 'the fallback is in flight; nothing completes yet');
        self::assertSame(2, $client->requestCount(), 'exactly one fallback leg');
        self::assertSame(self::GITHUB_FALLBACK_URL, $client->urlOf(1));

        $headers = $client->optionsOf(1)['headers'] ?? null;
        self::assertIsArray($headers, 'the fallback must carry an Accept header');
        self::assertSame('application/vnd.github.raw', $headers['Accept'] ?? null);

        ($client->successOf(1))($this->response("0.9.9\n"));

        self::assertSame([["0.9.9\n", null]], $seen, 'a successful fallback is a clean status');
    }

    /**
     * (b) When BOTH legs fail, the error combines the primary message with the
     * fallback's so the operator sees the original timeout and why the backup
     * also failed — exactly one completion.
     */
    public function testWhenBothLegsFailTheMessagesCombine(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::GITHUB_URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        $primary = 'read 185.199.111.133:443 timeout after 10 seconds';
        ($client->errorOf(0))(new \RuntimeException($primary));
        ($client->errorOf(1))(new \RuntimeException('connection refused'));

        self::assertSame([[null, $primary . ' (fallback: connection refused)']], $seen);
    }

    /**
     * (c) A non-github marker URL (operator mirror) must NOT trigger a second
     * request — the fallback is scoped to the raw-github shape only.
     */
    public function testANonGithubErrorIssuesNoFallback(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        ($client->errorOf(0))(new \RuntimeException('connection refused'));

        self::assertSame(1, $client->requestCount(), 'a non-github URL gets no fallback leg');
        self::assertSame([[null, 'connection refused']], $seen);
    }

    /**
     * (d) A successful primary leg is exactly ONE request — the fallback must
     * never fire on success (zero extra traffic on the happy path).
     */
    public function testAGithubSuccessIssuesNoFallback(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::GITHUB_URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        ($client->successOf(0))($this->response('1.2.3'));

        self::assertSame(1, $client->requestCount(), 'a healthy marker costs one request');
        self::assertSame([['1.2.3', null]], $seen);
    }

    /**
     * (e) The byte cap re-applies to the FALLBACK body identically: an oversized
     * fallback response is rejected, not parsed.
     */
    public function testTheByteCapAppliesToTheFallbackBody(): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $seen = [];
        $fetcher->fetch(self::GITHUB_URL, static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        });

        ($client->errorOf(0))(new \RuntimeException('read timeout'));
        $oversized = str_repeat('x', AsyncVersionMarkerFetcher::MAX_BODY_BYTES + 1);
        ($client->successOf(1))($this->response($oversized));

        self::assertCount(1, $seen);
        self::assertNull($seen[0][0]);
        self::assertStringContainsString('exceeds', (string) $seen[0][1]);
    }

    /**
     * (f) The ref is carried verbatim from the primary URL, so a version-tag
     * (or master) marker stays the same marker on the fallback host.
     *
     * @return array<string, array{string, string}>
     */
    public static function refProvider(): array
    {
        return [
            'master' => [
                'https://raw.githubusercontent.com/detain/phlix-hub/master/VERSION',
                'https://api.github.com/repos/detain/phlix-hub/contents/VERSION?ref=master',
            ],
            'version tag' => [
                'https://raw.githubusercontent.com/detain/phlix-hub/v0.4.0/VERSION',
                'https://api.github.com/repos/detain/phlix-hub/contents/VERSION?ref=v0.4.0',
            ],
            'nested path' => [
                'https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh',
                'https://api.github.com/repos/detain/phlix-hub/contents/scripts/install.sh?ref=master',
            ],
        ];
    }

    /**
     * @dataProvider refProvider
     */
    public function testTheFallbackPreservesRefAndPath(string $primary, string $expectedFallback): void
    {
        $client  = $this->client();
        $fetcher = new AsyncVersionMarkerFetcher(5, $client);

        $fetcher->fetch($primary, static function (?string $body, ?string $error): void {
        });

        ($client->errorOf(0))(new \RuntimeException('read timeout'));

        self::assertSame(2, $client->requestCount());
        self::assertSame($expectedFallback, $client->urlOf(1));
    }

    /**
     * Every `[callable, args, persistent, interval]` tuple currently queued in
     * `Timer::$tasks`.
     *
     * @return list<array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}>
     */
    private function pendingTimerTasks(): array
    {
        $property = (new ReflectionClass(Timer::class))->getProperty('tasks');
        $property->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}>> $tasks */
        $tasks = $property->getValue();

        $out = [];
        foreach ($tasks as $bucket) {
            foreach ($bucket as $task) {
                $out[] = $task;
            }
        }

        return $out;
    }
}
