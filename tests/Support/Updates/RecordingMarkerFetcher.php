<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Updates;

use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;

/**
 * Fetcher double recording every fetch() and completing synchronously with the
 * canned body/error — or not completing at all when `$responds` is false,
 * which models the deadline elapsing with the marker never answering.
 */
final class RecordingMarkerFetcher implements VersionMarkerFetcherInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $urls = [];

    public function __construct(
        private readonly ?string $body,
        private readonly ?string $error,
        private readonly bool $responds = true,
    ) {
    }

    public function fetch(string $url, callable $onDone): void
    {
        $this->calls++;
        $this->urls[] = $url;

        if ($this->responds) {
            $onDone($this->body, $this->error);
        }
    }
}
