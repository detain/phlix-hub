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
 * Shared listen-side TLS kit for the hub's inbound WebSocket surfaces.
 *
 * History (SH-064, review hub__secnet#1): the later-arriving :8803/:8804
 * surfaces adopted this home while the shipped pair — `RelayWorker`'s
 * `relayTlsContext()` (:8802, M21) and `FederationWorker`'s inline literal
 * (:8805, M22) — stayed byte-pinned duplicates, because refactoring them
 * out from under their own suites was not the arriving surfaces' remit.
 * F-11/F-12 (dupe-scan-1119) executed that deliberate migration: both
 * duplicates were proven byte-identical to {@see self::forListener()} on
 * the pristine base tree, their pins were re-pointed (WsTlsBootTest carries
 * the full hardened-shape law now), and all four listeners build their
 * bind context HERE — the cipher floor finally has one place to touch.
 *
 * Pure value builders: no I/O, no state, no statics. The boot-time material
 * decision around this value (null/missing/unreadable policy + watch
 * construction) lives in {@see WsTlsBoot}, not here, precisely so this
 * purity law holds.
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
