<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Http;

use PHPUnit\Framework\Assert;
use Workerman\Connection\TcpConnection;

/**
 * Test double for a browser connection: records everything written and every
 * close() call, and lets a test force send() to fail (client gone).
 *
 * Skips the parent constructor so no live socket is needed, and overrides
 * close() rather than delegating to the real `TcpConnection::close()` so the
 * force-close assertions never entangle `TcpConnection`'s internal socket /
 * event-loop state, which is never initialised for this double.
 *
 * S306: extracted from the anonymous class in ConnectionResponseSinkTest —
 * a NAMED type keeps the recording properties visible to both analysers
 * without a `TcpConnection&object{...}` intersection return type, which
 * Psalm cannot parse.
 */
final class RecordingSinkConnection extends TcpConnection
{
    /** @var list<string> */
    public array $written = [];

    public bool $closeCalled = false;

    /** @psalm-suppress MissingParentConstructorCall Intentional: no live socket in tests. */
    public function __construct(private readonly bool $sendResult = true)
    {
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool
    {
        if (!is_string($sendBuffer)) {
            Assert::fail('the sink must only be sent string frames');
        }
        $this->written[] = $sendBuffer;
        return $this->sendResult;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closeCalled = true;
    }
}
