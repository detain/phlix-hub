<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Relay;

use Phlix\Hub\Relay\RelayResponseSink;

/**
 * A recording {@see RelayResponseSink}. `$bodyReturn` lets a test simulate
 * the browser going away mid-stream (body() returning false).
 *
 * S306 — named and hoisted out of RelayProxyBridgeTest (it used to be an
 * anonymous class returned through the interface type, which hid the
 * `events`/`body` fields from PHPStan; PSR-12 allows one class per file, so
 * the double lives in its own file under tests/Support).
 */
final class RecordingResponseSink implements RelayResponseSink
{
    /** @var list<array{0: string, 1?: mixed, 2?: mixed}> */
    public array $events = [];

    public string $body = '';

    public function __construct(private readonly bool $bodyReturn = true)
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
}
