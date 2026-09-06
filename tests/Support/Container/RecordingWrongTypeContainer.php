<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Container;

use Psr\Container\ContainerInterface;
use stdClass;

/**
 * Container double recording every get() id, then returning the WRONG type —
 * so instanceof guards in provider wiring evaluate false, nothing is actually
 * started, and every service is still attempted.
 */
final class RecordingWrongTypeContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function get(string $id): mixed
    {
        $this->seen[] = $id;

        return new stdClass();
    }

    public function has(string $id): bool
    {
        return true;
    }
}
