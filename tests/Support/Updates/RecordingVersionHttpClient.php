<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Updates;

use Throwable;
use Workerman\Http\Client;

/**
 * Client double for {@see \Phlix\Hub\Hub\Updates\AsyncVersionMarkerFetcher} tests.
 *
 * Records the URL and HTTP method of the single `request()` the fetcher issues
 * and hands the registered success/error callbacks back to the test, which
 * drives every outcome (success, transport error, oversized body, synchronous
 * throw) deterministically. No socket is ever opened: the parent constructor is
 * intentionally not called, so no connection pool exists.
 */
final class RecordingVersionHttpClient extends Client
{
    public string $url = '';

    /** @var list<string> Every HTTP method the fetcher asked for. */
    public array $methods = [];

    /** @var callable|null */
    public $success = null;

    /** @var callable|null */
    public $error = null;

    /** @var Throwable|null Thrown synchronously from request() when set. */
    public ?Throwable $throwOnGet = null;

    /** @psalm-suppress MissingParentConstructorCall Intentional: no connection pool in tests. */
    public function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function request(string $url, array $options = []): mixed
    {
        $this->url = $url;
        $this->methods[] = is_string($options['method'] ?? null) ? (string) $options['method'] : '';
        $this->success = is_callable($options['success'] ?? null) ? $options['success'] : null;
        $this->error = is_callable($options['error'] ?? null) ? $options['error'] : null;

        if ($this->throwOnGet !== null) {
            throw $this->throwOnGet;
        }

        return null;
    }
}
