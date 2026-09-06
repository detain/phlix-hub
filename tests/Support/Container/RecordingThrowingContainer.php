<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Container;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Container double recording every get() id, then throwing — so if a set's
 * arms were not each independently guarded, the first throw aborts the rest.
 */
final class RecordingThrowingContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $seen = [];

    public function get(string $id): mixed
    {
        $this->seen[] = $id;
        throw new RuntimeException("unavailable: {$id}");
    }

    public function has(string $id): bool
    {
        return true;
    }
}
