<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Updates;

use Throwable;
use Workerman\Http\Client;

/**
 * Client double for {@see \Phlix\Hub\Hub\Updates\AsyncVersionMarkerFetcher} tests.
 *
 * Records EVERY `request()` the fetcher issues — the primary marker leg and,
 * when the Fastly fallback fires, the `api.github.com` leg — and hands each
 * request's success/error callbacks back to the test, which drives every
 * outcome (success, transport error, oversized body, synchronous throw)
 * deterministically. No socket is ever opened: the parent constructor is
 * intentionally not called, so no connection pool exists.
 *
 * The scalar `$url`/`$success`/`$error` fields always mirror the LAST request,
 * so the many single-leg tests read them unchanged; multi-leg (fallback) tests
 * index into {@see $requests} to drive or inspect a specific leg.
 */
final class RecordingVersionHttpClient extends Client
{
    /** URL of the most recent `request()`. */
    public string $url = '';

    /** @var list<string> Every HTTP method the fetcher asked for. */
    public array $methods = [];

    /**
     * Success callback of the most recent `request()`.
     *
     * @var callable|null
     */
    public $success = null;

    /**
     * Error callback of the most recent `request()`.
     *
     * @var callable|null
     */
    public $error = null;

    /**
     * Every request in issue order.
     *
     * @var list<array{url: string, options: array<array-key, mixed>, success: callable|null, error: callable|null}>
     */
    public array $requests = [];

    /** @var Throwable|null Thrown synchronously from request() when set. */
    public ?Throwable $throwOnGet = null;

    /** @psalm-suppress MissingParentConstructorCall Intentional: no connection pool in tests. */
    public function __construct()
    {
    }

    /**
     * Number of `request()` calls the fetcher has issued so far.
     */
    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * URL the fetcher passed to request `$index` (fail-loud if absent).
     */
    public function urlOf(int $index): string
    {
        if (!isset($this->requests[$index])) {
            throw new \LogicException("no request recorded at index $index");
        }

        return $this->requests[$index]['url'];
    }

    /**
     * The request `$index`'s success callback (fail-loud if not callable).
     */
    public function successOf(int $index): callable
    {
        $callback = $this->requests[$index]['success'] ?? null;
        if (!is_callable($callback)) {
            throw new \LogicException("request $index has no success callback");
        }

        return $callback;
    }

    /**
     * The request `$index`'s error callback (fail-loud if not callable).
     */
    public function errorOf(int $index): callable
    {
        $callback = $this->requests[$index]['error'] ?? null;
        if (!is_callable($callback)) {
            throw new \LogicException("request $index has no error callback");
        }

        return $callback;
    }

    /**
     * The full options array the fetcher passed to request `$index`.
     *
     * @return array<array-key, mixed>
     */
    public function optionsOf(int $index): array
    {
        if (!isset($this->requests[$index])) {
            throw new \LogicException("no request recorded at index $index");
        }

        return $this->requests[$index]['options'];
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function request(string $url, array $options = []): mixed
    {
        $success = is_callable($options['success'] ?? null) ? $options['success'] : null;
        $error   = is_callable($options['error'] ?? null) ? $options['error'] : null;

        $this->url       = $url;
        $this->success   = $success;
        $this->error     = $error;
        $this->methods[] = is_string($options['method'] ?? null) ? (string) $options['method'] : '';
        $this->requests[] = ['url' => $url, 'options' => $options, 'success' => $success, 'error' => $error];

        if ($this->throwOnGet !== null) {
            throw $this->throwOnGet;
        }

        return null;
    }
}
