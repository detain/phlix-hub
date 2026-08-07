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
 * Time-bounded cURL fetch of an Alexa signing-certificate chain.
 *
 * ## Why every bound here is load-bearing
 *
 * This runs inside a resident Workerman worker, and under the Swoole event loop
 * an https request falls back to **blocking cURL** — a blocking syscall freezes
 * every coroutine on the process, not just the one Alexa request. phlix-server's
 * S44-b documents the same hazard. So:
 *
 * - {@see CURLOPT_CONNECTTIMEOUT} bounds the connection phase (DNS + TCP + TLS
 *   handshake) at {@see DEFAULT_CONNECT_TIMEOUT_SECONDS} seconds.
 * - {@see CURLOPT_TIMEOUT} bounds the **whole** transfer at
 *   {@see DEFAULT_TIMEOUT_SECONDS} seconds. This is NOT redundant with the
 *   connect timeout, and the difference is not academic: measured on this box
 *   against a TLS peer that completes the handshake and then never sends a byte,
 *   `CURLOPT_CONNECTTIMEOUT => 1` did **not** end the call — the request was
 *   still open 25 s later. Only `CURLOPT_TIMEOUT` ended it, at 3.00 s, with
 *   "Operation timed out … with 0 bytes received". A stalled-after-handshake
 *   peer is the exact shape of a hostile or overloaded S3 edge.
 *   {@see \Phlix\Hub\Tests\Unit\Alexa\CurlCertChainFetcherTimeoutTest} pins that
 *   with a real local TLS server; deleting the `CURLOPT_TIMEOUT` line makes it
 *   red rather than slow-but-green.
 *
 * The middleware caches a verified chain, so on the common path this class is
 * not called at all — the bounds above cover the cold path and the attacker-
 * driven path (a caller that varies the cert URL to force a miss).
 *
 * ## Why redirects are refused
 *
 * The caller validates the URL against Amazon's documented cert host BEFORE
 * calling here. A followed redirect would move the fetch to a host that never
 * passed that check, turning the validated allowlist into decoration — the
 * textbook SSRF bypass. {@see CURLOPT_FOLLOWLOCATION} is therefore `false` and
 * the protocol is pinned to https, so neither a 3xx nor a `file://`/`gopher://`
 * URL can redirect the worker anywhere.
 *
 * @package Phlix\Hub\Alexa
 */
final class CurlCertChainFetcher implements CertChainFetcherInterface
{
    /** Seconds allowed for DNS + TCP + TLS handshake. */
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 2;

    /** Seconds allowed for the ENTIRE transfer, handshake included. */
    public const DEFAULT_TIMEOUT_SECONDS = 5;

    /**
     * Largest chain body accepted. Amazon's real chains measured 3.8–6.9 KiB
     * (the recorded `echo-api-cert-12.pem` fixture is 6,909 bytes), so 64 KiB is
     * two decimal orders of headroom and still bounds worker memory against a
     * peer that streams forever inside the transfer timeout.
     */
    public const MAX_CHAIN_BYTES = 65536;

    /**
     * @param int         $connectTimeoutSeconds Connection-phase bound.
     * @param int         $timeoutSeconds        Whole-transfer bound.
     * @param int         $maxBytes              Largest accepted body.
     * @param string|null $caInfoPath            Optional CA bundle pinned for
     *        peer verification. `null` (production) uses the system trust store.
     *        Setting it NEVER relaxes verification —
     *        {@see CURLOPT_SSL_VERIFYPEER} stays `true` and
     *        {@see CURLOPT_SSL_VERIFYHOST} stays `2` either way; it only changes
     *        WHICH roots are trusted, which is what lets the timeout test point
     *        the production option set at a local TLS server.
     */
    public function __construct(
        private readonly int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxBytes = self::MAX_CHAIN_BYTES,
        private readonly ?string $caInfoPath = null,
    ) {
    }

    /**
     * The cURL option set. Kept as one readable array so a reviewer can see
     * every security-relevant option in a single glance, and so a mutation that
     * removes one of them is a one-line diff.
     *
     * @return array<int, mixed>
     */
    private function curlOptions(string $url): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            // SSRF: never leave the host the caller validated.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Resident-worker bounds. See the class docblock.
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_MAXFILESIZE => $this->maxBytes,
            CURLOPT_USERAGENT => 'phlix-hub-alexa-cert-fetch/1',
        ];

        if ($this->caInfoPath !== null) {
            $options[CURLOPT_CAINFO] = $this->caInfoPath;
        }

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch(string $url): ?string
    {
        $handle = curl_init();
        if (!$handle instanceof \CurlHandle) {
            // Documented as impossible on PHP 8 but typed as possible; a
            // handle we could not create is a fetch we did not make.
            return null;
        }

        try {
            curl_setopt_array($handle, $this->curlOptions($url));

            $body = curl_exec($handle);
            if (!is_string($body)) {
                return null;
            }

            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status !== 200) {
                return null;
            }

            // ⚠ The length half of this condition is a PORTABILITY BACKSTOP and
            // no test in this repo can make it fire. Measured on libcurl 8.5.0:
            // CURLOPT_MAXFILESIZE aborts an over-cap download even when the peer
            // declares no Content-Length (chunked), failing with CURLE_FILESIZE_
            // EXCEEDED "with 3000 bytes" — so control never reaches here with an
            // oversized body. Deleting the `strlen()` clause was mutation-tested
            // and SURVIVED for exactly that reason; it is kept deliberately,
            // because "libcurl aborts mid-transfer for an unknown-length body"
            // is a property of the linked library version, not of this code, and
            // the consequence of being wrong about it is unbounded memory growth
            // in a resident worker. Do not delete it because coverage says it is
            // unreached.
            if ($body === '' || strlen($body) > $this->maxBytes) {
                return null;
            }

            return $body;
        } catch (\Throwable) {
            // Fail closed: the caller reads null as "reject this request".
            return null;
        } finally {
            curl_close($handle);
        }
    }
}
