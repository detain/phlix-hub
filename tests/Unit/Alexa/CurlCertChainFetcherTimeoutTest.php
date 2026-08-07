<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use Phlix\Hub\Alexa\CurlCertChainFetcher;
use Phlix\Hub\Tests\Support\Alexa\TlsProbeServer;
use PHPUnit\Framework\TestCase;

/**
 * S90 — prove the cert-chain fetch is bounded, against a peer that stalls where
 * it actually hurts.
 *
 * ## Why this test is shaped the way it is
 *
 * The obvious timeout test — point the fetcher at a black-holed address and
 * assert it gives up quickly — is a **false green**. libcurl's *connection*
 * phase for an `https://` URL includes the TLS handshake, so a silent peer is
 * ended by `CURLOPT_CONNECTTIMEOUT`; a test written that way stays fully green
 * with `CURLOPT_TIMEOUT` deleted from the production option set, because the
 * line it is really pinning is a different one. phlix-server hit exactly that
 * yesterday.
 *
 * So the peer here is a real TLS server that **completes the handshake** and
 * then sends nothing. Measured on this box (PHP 8.3.6, libcurl 8.5.0):
 *
 * | option set                            | outcome                              |
 * | ------------------------------------- | ------------------------------------ |
 * | `CONNECTTIMEOUT 1` + `TIMEOUT 3`      | fails at **3.00 s**, "Operation timed out … with 0 bytes received" |
 * | `CONNECTTIMEOUT 1`, no `TIMEOUT`      | still open **25 s** later — the connect timeout never fires |
 *
 * The assertions below are chosen to fail in BOTH directions:
 *
 *  - `elapsed < 8 s` fails if `CURLOPT_TIMEOUT` is removed (the call then runs
 *    until the server hangs up at 20 s);
 *  - `elapsed >= 2 s` fails if the bound that fired was the 1-second connect
 *    timeout, i.e. if someone "fixed" the first assertion by shortening
 *    `CURLOPT_CONNECTTIMEOUT` instead.
 *
 * ## The control
 *
 * {@see testTheSameFetcherRetrievesAChainFromAServerThatAnswers()} runs the same
 * fetcher, with the same options, against a server that replies. Without it,
 * "returns null" would be satisfied by a fetcher that cannot fetch anything at
 * all — a second failure is not a control, only a success is.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class CurlCertChainFetcherTimeoutTest extends TestCase
{
    /** How long the stalling peer stays silent. Must exceed TRANSFER_TIMEOUT. */
    private const STALL_SECONDS = 20;

    private const CONNECT_TIMEOUT = 1;

    private const TRANSFER_TIMEOUT = 3;

    public function testAPeerThatStallsAfterTheHandshakeIsEndedByTheTransferTimeout(): void
    {
        $server = TlsProbeServer::stalling(self::STALL_SECONDS);

        try {
            $fetcher = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
                caInfoPath: $server->caFile(),
            );

            $startedAt = microtime(true);
            $result = $fetcher->fetch($server->url());
            $elapsed = microtime(true) - $startedAt;

            self::assertNull($result, 'a stalled fetch must fail closed, not return a partial body');

            self::assertLessThan(
                8.0,
                $elapsed,
                sprintf(
                    'the transfer was not bounded: %0.2fs elapsed against a peer that stalls for %ds. '
                    . 'CURLOPT_TIMEOUT is what ends this call — CURLOPT_CONNECTTIMEOUT cannot, '
                    . 'because the connection phase already succeeded.',
                    $elapsed,
                    self::STALL_SECONDS,
                ),
            );

            self::assertGreaterThanOrEqual(
                2.0,
                $elapsed,
                sprintf(
                    'the call ended after only %0.2fs, which is the connect-timeout bound, not the '
                    . 'transfer bound — this test must exercise CURLOPT_TIMEOUT specifically.',
                    $elapsed,
                ),
            );
        } finally {
            $server->stop();
        }
    }

    public function testTheSameFetcherRetrievesAChainFromAServerThatAnswers(): void
    {
        $body = "-----BEGIN CERTIFICATE-----\nZmFrZQ==\n-----END CERTIFICATE-----\n";
        $server = TlsProbeServer::serving($body);

        try {
            $fetcher = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
                caInfoPath: $server->caFile(),
            );

            self::assertSame(
                $body,
                $fetcher->fetch($server->url()),
                'control: the bounded fetcher must still be able to fetch',
            );
        } finally {
            $server->stop();
        }
    }

    public function testAnOversizedBodyIsRefused(): void
    {
        $body = str_repeat('A', 4096);
        $server = TlsProbeServer::serving($body);

        try {
            $bounded = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
                maxBytes: 1024,
                caInfoPath: $server->caFile(),
            );

            self::assertNull($bounded->fetch($server->url()), 'a body over the cap must be refused');
        } finally {
            $server->stop();
        }

        // Control: the identical body under a cap that accommodates it.
        $server = TlsProbeServer::serving($body);
        try {
            $generous = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
                maxBytes: 65536,
                caInfoPath: $server->caFile(),
            );

            self::assertSame($body, $generous->fetch($server->url()));
        } finally {
            $server->stop();
        }
    }

    public function testAnUntrustedPeerIsRefused(): void
    {
        $body = "-----BEGIN CERTIFICATE-----\nZmFrZQ==\n-----END CERTIFICATE-----\n";
        $server = TlsProbeServer::serving($body);

        try {
            // Same server, same options, but the system trust store instead of
            // the probe server's own certificate: peer verification must reject
            // it. This is what shows CURLOPT_SSL_VERIFYPEER is live rather than
            // being carried along as decoration.
            $fetcher = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
            );

            self::assertNull($fetcher->fetch($server->url()));
        } finally {
            $server->stop();
        }
    }

    public function testANon200StatusIsRefusedEvenThoughABodyArrived(): void
    {
        // S3 answers 403 with an XML error document for a key that does not
        // exist — measured while recording this step's fixture. Returning that
        // body as "the chain" would hand the middleware something to parse
        // instead of a clean rejection.
        $error = '<Error><Code>AccessDenied</Code></Error>';
        $server = TlsProbeServer::servingRaw(
            "HTTP/1.1 403 Forbidden\r\nContent-Length: " . strlen($error)
            . "\r\nConnection: close\r\n\r\n" . $error,
        );

        try {
            self::assertNull($this->fetcher($server->caFile())->fetch($server->url()));
        } finally {
            $server->stop();
        }
    }

    public function testAnEmpty200IsRefused(): void
    {
        $server = TlsProbeServer::serving('');

        try {
            self::assertNull($this->fetcher($server->caFile())->fetch($server->url()));
        } finally {
            $server->stop();
        }
    }

    public function testAnOversizedChunkedBodyIsRefusedByTheLengthCheck(): void
    {
        // No Content-Length, so CURLOPT_MAXFILESIZE cannot fire: this is the
        // case the fetcher's own strlen() guard exists for. Without it a peer
        // could stream unbounded data into a resident worker's memory for as
        // long as the transfer timeout allows.
        $chunk = str_repeat('B', 2048);
        $server = TlsProbeServer::servingRaw(
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
            . dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n"
            . dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n0\r\n\r\n",
        );

        try {
            $bounded = new CurlCertChainFetcher(
                connectTimeoutSeconds: self::CONNECT_TIMEOUT,
                timeoutSeconds: self::TRANSFER_TIMEOUT,
                maxBytes: 3000,
                caInfoPath: $server->caFile(),
            );

            self::assertNull($bounded->fetch($server->url()), '4 KiB of chunked body must not pass a 3 KB cap');
        } finally {
            $server->stop();
        }
    }

    public function testAPlainHttpUrlIsRefusedEvenThoughAServerIsAnswering(): void
    {
        $body = "-----BEGIN CERTIFICATE-----\nZmFrZQ==\n-----END CERTIFICATE-----\n";
        $server = TlsProbeServer::servingRawWithoutTls(
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
        );

        try {
            // A cheerful 200 on plain http. `CURLOPT_PROTOCOLS_STR => 'https'` is
            // the only thing refusing it — every other guard in the fetcher is
            // satisfied. Deleting that option makes this test return the body.
            self::assertNull(
                $this->fetcher($server->caFile())->fetch($server->plainUrl()),
                'the fetch must be pinned to https even when http would succeed',
            );
        } finally {
            $server->stop();
        }
    }

    public function testARedirectIsNotFollowedEvenWhenItWouldSucceed(): void
    {
        // The 302 points back at the SAME https host, and the server answers
        // that second request with a real 200. So nothing except
        // CURLOPT_FOLLOWLOCATION => false stops the redirect being followed —
        // the protocol pin allows it and the name resolves. Flipping that option
        // to true makes this test receive the body instead of null.
        //
        // Why it matters: the middleware validates the cert URL against Amazon's
        // host BEFORE handing it here. A followed redirect moves the fetch to a
        // host that never passed that check, which turns the allowlist into
        // decoration — the textbook SSRF bypass.
        $body = "-----BEGIN CERTIFICATE-----\nZmFrZQ==\n-----END CERTIFICATE-----\n";
        $server = TlsProbeServer::redirectingTo($body);

        try {
            self::assertNull(
                $this->fetcher($server->caFile())->fetch($server->url()),
                'a 3xx must end the fetch, not relocate it',
            );
        } finally {
            $server->stop();
        }
    }

    public function testANonHttpsSchemeIsRefusedWithoutABlockingAttempt(): void
    {
        $fetcher = new CurlCertChainFetcher(
            connectTimeoutSeconds: self::CONNECT_TIMEOUT,
            timeoutSeconds: self::TRANSFER_TIMEOUT,
        );

        // file:// would otherwise read a local file and hand it back as a
        // "certificate chain".
        self::assertNull($fetcher->fetch('file://' . __FILE__));
    }

    private function fetcher(string $caFile): CurlCertChainFetcher
    {
        return new CurlCertChainFetcher(
            connectTimeoutSeconds: self::CONNECT_TIMEOUT,
            timeoutSeconds: self::TRANSFER_TIMEOUT,
            caInfoPath: $caFile,
        );
    }
}
