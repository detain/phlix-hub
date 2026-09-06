<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Assert;
use Workerman\Connection\TcpConnection;

/**
 * A {@see TcpConnection} double that skips the parent constructor (no live
 * socket needed) and records every `send()` payload on the wire.
 *
 * S306 — hoisted out of ServerProxyControllerTest: PSR-12 allows one class per
 * file. The Assert::assertIsString() inside send() is deliberate: a non-string
 * write would be a proxy bug, and the double must fail loudly, not collect
 * garbage.
 */
final class RecordingBrowserConnection extends TcpConnection
{
    /** @var list<string> */
    public array $written = [];

    public function __construct()
    {
        // Intentionally skips the parent constructor: no live socket is
        // needed — send() just records what would go on the wire.
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool
    {
        Assert::assertIsString($sendBuffer, 'the proxy must write strings to the browser connection');
        $this->written[] = $sendBuffer;

        return true;
    }
}
