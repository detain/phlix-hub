<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

/**
 * Fetches an Alexa signing-certificate chain over the network.
 *
 * Split out of {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware} for
 * one reason only: the fetch is the single piece of the verification algorithm
 * that touches the network, and a resident Workerman/Swoole worker cannot
 * afford an unbounded one. Isolating it here means the middleware's tests never
 * open a socket, and the one test that DOES open a socket
 * ({@see \Phlix\Hub\Tests\Unit\Alexa\CurlCertChainFetcherTimeoutTest}) tests
 * only this class.
 *
 * ## Contract
 *
 * `fetch()` NEVER throws and NEVER blocks indefinitely. It returns the response
 * body on an unambiguous HTTP 200, and `null` for every other outcome —
 * transport error, timeout, non-200 status, oversized body. The caller treats
 * `null` as "reject the request", so there is no failure mode in which a fetch
 * problem becomes an allow.
 *
 * The URL handed to an implementation is expected to have ALREADY been validated
 * against Amazon's documented cert host by the caller. An implementation must
 * still refuse to follow redirects and must still pin the scheme, because
 * "already validated" is a caller-side promise and this is an SSRF sink.
 *
 * @package Phlix\Hub\Alexa
 */
interface CertChainFetcherInterface
{
    /**
     * Fetch the PEM certificate chain at `$url`.
     *
     * @param string $url Absolute `https://` URL, pre-validated by the caller.
     *
     * @return string|null The response body, or null on ANY failure.
     */
    public function fetch(string $url): ?string;
}
