<?php

declare(strict_types=1);

namespace Phlix\Hub\Health;

use Phlix\Hub\Version;
use Phlix\Shared\Version as SharedVersion;

/**
 * Liveness/readiness endpoint for `phlix-hub`.
 *
 * Returns a small JSON-serializable array describing service identity,
 * package versions, and the current Unix timestamp. The controller has
 * no DB or filesystem dependency on purpose so the endpoint is safe
 * to hit from monitors while the rest of the stack is starting up.
 *
 * @package Phlix\Hub\Health
 */
final class HealthController
{
    /**
     * Build the health payload.
     *
     * @return array{
     *     status: string,
     *     service: string,
     *     version: string,
     *     phlixShared: string,
     *     timestamp: int
     * }
     *
     */
    public function __invoke(): array
    {
        return [
            'status' => 'ok',
            'service' => 'phlix-hub',
            'version' => Version::VERSION,
            'phlixShared' => SharedVersion::VERSION,
            'timestamp' => time(),
        ];
    }
}
