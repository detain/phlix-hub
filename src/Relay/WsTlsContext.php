<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use function str_contains;
use function str_starts_with;

/**
 * Shared listen-side TLS kit for the client-facing hub WebSocket surfaces
 * that arrived after the shipped pair (SH-064, review hub__secnet#1).
 *
 * Why a new home rather than reuse: `RelayWorker::relayTlsContext()` (:8802,
 * M21) and `FederationWorker`'s inline context (:8805, M22) are byte-pinned
 * by their own suites and must not be refactored out from under those pins.
 * This class carries the SAME hardened list forward for the surfaces joining
 * them (:8803, :8804), so a future cipher-policy change has one place to
 * touch for the new half while the pinned halves migrate deliberately.
 *
 * Pure value builders: no I/O, no state, no statics.
 *
 * @package Phlix\Hub\Relay
 */
final class WsTlsContext
{
    /**
     * Hardened server-side socket context (AEAD-only ciphers, secp384r1,
     * compression off, OpenSSL security level 2). `allow_self_signed` /
     * `verify_peer` describe whether THIS listener validates client
     * certificates — it does not; authentication on these surfaces is the
     * bearer token inside the WebSocket handshake, not mTLS.
     *
     * @param string $certPath local_cert PEM bundle (already passed the boot material decision).
     * @param string $keyPath  local_pk PEM path.
     *
     * @return array{ssl: array<string, mixed>} Workerman socket context.
     */
    public static function forListener(string $certPath, string $keyPath): array
    {
        return [
            'ssl' => [
                'ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
                'ecdh_curve' => 'secp384r1',
                'disable_compression' => true,
                'security_level' => 2,
                'local_cert' => $certPath,
                'local_pk' => $keyPath,
                'allow_self_signed' => true,
                'verify_peer' => false,
            ],
        ];
    }

    /**
     * Build the Workerman `websocket://` listen string for a host knob value.
     *
     * A bare IPv6 literal would make the listen string unparseable
     * (`websocket://::1:8803` is ambiguous), so bracketed forms are
     * normalized here — the HUB_WS_BIND knob must never degrade into a
     * silent-bind failure at runAll() time. Hostnames and IPv4 pass through
     * untouched; the default bind (0.0.0.0) is byte-for-byte the historical
     * literal (no default flip — estate owner territory).
     */
    public static function listenString(string $bindHost, int $port): string
    {
        $host = $bindHost;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return "websocket://{$host}:{$port}";
    }
}
