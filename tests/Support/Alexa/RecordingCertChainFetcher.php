<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Alexa;

use Phlix\Hub\Alexa\CertChainFetcherInterface;

/**
 * A fetcher that opens no socket and records every URL it was asked for.
 *
 * The call log is not decoration: the middleware's most important single
 * property is that a bad `SignatureCertChainUrl` is rejected **before** the
 * fetch, and the only way to observe "before" is to observe that the fetch never
 * happened. An assertion on the response code alone cannot distinguish
 * "rejected the URL" from "fetched the attacker's URL, then rejected what came
 * back" — and those differ by an SSRF.
 *
 * It also makes the chain cache observable: two requests, one fetch.
 */
final class RecordingCertChainFetcher implements CertChainFetcherInterface
{
    /** @var list<string> Every URL passed to {@see fetch()}, in order. */
    public array $calls = [];

    /**
     * @param string|null $pem What to return — null simulates a fetch failure.
     */
    public function __construct(public ?string $pem = null)
    {
    }

    public function fetch(string $url): ?string
    {
        $this->calls[] = $url;

        return $this->pem;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
