<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Shared\Arr\Transport\ArrTransportInterface;

/**
 * Scripted in-memory *arr transport for unit tests (W5 auto-approve).
 *
 * Answers the three calls {@see \Phlix\Hub\Requests\RequestManager}'s movie
 * approval makes — qualityprofile, movie listing, addMovie — with canned
 * 200s so the approve path is exercised end-to-end without any network I/O.
 * Every call is recorded for assertions.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class StubArrTransport implements ArrTransportInterface
{
    /** @var list<array{method: string, url: string, body: string|null}> */
    public array $calls = [];

    public bool $failAddMovie = false;

    /**
     * @param string                       $method HTTP verb.
     * @param string                       $url    Absolute URL.
     * @param array<string>                $headers Raw headers (ignored).
     * @param string|null                  $body   Request body.
     *
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];

        if (str_contains($url, '/qualityprofile')) {
            return ['status' => 200, 'body' => '[{"id":7,"name":"Any"}]'];
        }

        if (str_contains($url, '/api/v3/movie') && $method === 'POST') {
            if ($this->failAddMovie) {
                return ['status' => 500, 'body' => 'boom'];
            }

            return ['status' => 201, 'body' => '{"id":42,"title":"Added"}'];
        }

        if (str_contains($url, '/api/v3/movie')) {
            return ['status' => 200, 'body' => '[]'];
        }

        return ['status' => 200, 'body' => '{}'];
    }

    public function addMovieCalled(): bool
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === 'POST' && str_contains($call['url'], '/api/v3/movie')) {
                return true;
            }
        }

        return false;
    }
}
