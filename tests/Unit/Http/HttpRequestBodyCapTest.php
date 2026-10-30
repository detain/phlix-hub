<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * M-HUB-30 — the hub's global HTTP body cap, both declared halves.
 *
 * The review found that nothing in the hub bounded inbound POST bodies: every
 * `/api/v1` payload was materialized per the vendor's package cap — and this
 * Workerman's `$defaultMaxPackageSize` is 10 MB, which for a hub that ships
 * PUBLIC claim endpoints is a paid-by-the-hub heap DoS window. The cure is
 * two-pinned: the per-connection `maxPackageSize` bound set in the HTTP
 * worker's `onConnect` (stops over-cap bytes before parse), and a hub-owned
 * `bodyCapViolation()` check at the dispatch boundary answering a clean 413
 * with the registered `invalid_payload` code (zero new wire codes).
 *
 * Pinned here: the constant's value (a silent raise is the bug), the pure
 * violation predicate at boundary values, and the wiring of both halves into
 * the worker source — source-scan pins, the estate's idiom for closure bodies
 * that cannot be invoked without a live worker.
 */
final class HttpRequestBodyCapTest extends TestCase
{
    public function testTheDeclaredCapIsOneMebibyte(): void
    {
        self::assertSame(
            1048576,
            Application::MAX_REQUEST_BODY_BYTES,
            'the global body cap is 1 MiB — every real payload (claim, share, '
            . 'settings, heartbeat, Alexa directive) is kilobytes; megabytes are probes',
        );
    }

    public function testABodyExactlyAtTheCapPasses(): void
    {
        self::assertNull(self::violation(self::rawWithBodyBytes(Application::MAX_REQUEST_BODY_BYTES)));
    }

    public function testABodyOneByteOverTheCapIsRefusedWithTheRegisteredCode(): void
    {
        $response = self::violation(self::rawWithBodyBytes(Application::MAX_REQUEST_BODY_BYTES + 1));

        self::assertNotNull($response, 'over-cap materialized bytes must be refused at the boundary');
        self::assertSame(413, $response->statusCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_payload', $decoded['code'] ?? null, 'registered code — no new wire codes');
        self::assertSame('Request body too large', $decoded['error'] ?? null);
    }

    public function testAnEmptyBodyNeverViolates(): void
    {
        $request = new WorkermanRequest("POST /api/v1/ping HTTP/1.1\r\nHost: hub\r\n\r\n");

        self::assertNull(self::violation($request));
    }

    /**
     * Wiring pin #1: the HTTP worker binds the vendor package cap to the SAME
     * declared constant, so the protocol-level refusal and the hub-level law
     * cannot drift apart (and a vendor default change cannot silently raise
     * the materialization bound).
     */
    public function testTheWorkerPinsMaxPackageSizeToTheDeclaredCap(): void
    {
        $source = self::applicationSource();

        self::assertStringContainsString(
            '$connection->maxPackageSize = self::MAX_REQUEST_BODY_BYTES;',
            $source,
            'the accepted connection must carry the declared cap, not the vendor default',
        );
    }

    /**
     * Wiring pin #2: the dispatch guard runs BEFORE the maintenance gate — an
     * over-cap body must not cost a store read, a router resolve, or a
     * handler call.
     */
    public function testTheDispatchGuardRunsBeforeTheMaintenanceGate(): void
    {
        $source = self::applicationSource();

        $onMessageAt = strpos($source, '$worker->onMessage = static function (');
        $guardAt = strpos($source, 'self::bodyCapViolation($request)');
        $gateAt = strpos($source, '$maintenanceGate->shouldBlock($path)');

        self::assertNotFalse($onMessageAt);
        self::assertNotFalse($guardAt);
        self::assertNotFalse($gateAt);
        self::assertGreaterThan($onMessageAt, $guardAt, 'the guard must live inside the onMessage dispatch');
        self::assertLessThan($gateAt, $guardAt, 'over-cap bytes are refused before any gate or handler runs');
    }

    /**
     * Behavioral pin for the `onConnect` closure shape: assigning the declared
     * cap onto a live TcpConnection object lands on the vendor property the
     * protocol consults (guards against the closure being retargeted to a
     * dead property if the vendor renames — the static default must also NOT
     * be what the hub relies on).
     */
    public function testTheVendorDefaultIsNotWhatTheHubReliesOn(): void
    {
        /** @var array{0: resource, 1: resource} $pair */
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $connection = new TcpConnection(new Select(), $pair[0]);

        try {
            self::assertSame(
                10485760,
                $connection->maxPackageSize,
                'a constructed connection carries the vendor 10 MB default — exactly the finding',
            );

            $connection->maxPackageSize = Application::MAX_REQUEST_BODY_BYTES;

            self::assertSame(1048576, $connection->maxPackageSize);
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    private static function rawWithBodyBytes(int $bytes): WorkermanRequest
    {
        $head = "POST /api/v1/payload HTTP/1.1\r\nHost: hub\r\nContent-Type: application/json\r\n\r\n";

        return new WorkermanRequest($head . str_repeat('a', $bytes));
    }

    /**
     * Drive the private boundary predicate exactly as the worker does.
     */
    private static function violation(WorkermanRequest $request): ?\Phlix\Hub\Http\Response
    {
        $invoke = new ReflectionMethod(Application::class, 'bodyCapViolation');

        /** @var \Phlix\Hub\Http\Response|null $violation */
        $violation = $invoke->invoke(null, $request);

        return $violation;
    }

    private static function applicationSource(): string
    {
        $path = (string) (new \ReflectionClass(Application::class))->getFileName();

        return (string) file_get_contents($path);
    }
}
