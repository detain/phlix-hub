<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Hub;

use Phlix\Hub\Hub\RelaySessionManager;

/**
 * RelaySessionManager double that captures every closeSession() call.
 *
 * The capture list is bound by reference from the constructing test, so the
 * caller reads what the handler passed without a getter hop.
 */
final class CapturingCloseSessionManager extends RelaySessionManager
{
    /** @var list<array{sessionId: string, reason: string}> Every closeSession() call, in order. */
    public array $closeCalls = [];

    /**
     * @psalm-suppress MissingParentConstructorCall Intentional: no DB handle is ever touched.
     */
    public function __construct()
    {
    }

    public function closeSession(string $sessionId, string $reason): void
    {
        $this->closeCalls[] = ['sessionId' => $sessionId, 'reason' => $reason];
    }
}
