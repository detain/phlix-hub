<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Relay;

use Phlix\Hub\Relay\RelayResponseSink;
use RuntimeException;

/**
 * A {@see RelayResponseSink} whose `head()`/`body()` can be made to throw, for
 * the D3s re-review Finding B regression tests (a mid-stream exception must not
 * corrupt the connection with a second response).
 *
 * S306 — hoisted out of RelayProxyBridgeTest with the same rationale as
 * {@see RecordingResponseSink}.
 */
final class ThrowingResponseSink implements RelayResponseSink
{
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
}
