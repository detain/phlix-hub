<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\WsTlsContext;
use PHPUnit\Framework\TestCase;

/**
 * SH-064: the shared listen-side TLS kit for the client-facing surfaces
 * (:8803/:8804). The posture suites exercise it THROUGH their workers; this
 * file pins the kit's own laws: the hardened context shape (AEAD-only,
 * secp384r1, security level 2 — byte-committed so a cipher-policy edit is a
 * deliberate, review-visible move) and the listen-string rule (default bind
 * stays the historical literal; IPv6 hosts get brackets or the knob is a
 * silent-bind trap).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class WsTlsContextTest extends TestCase
{
    public function testForListenerCarriesTheHardenedShape(): void
    {
        $context = WsTlsContext::forListener('/tmp/a.pem', '/tmp/b.pem');

        self::assertSame([
            'ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
            'ecdh_curve' => 'secp384r1',
            'disable_compression' => true,
            'security_level' => 2,
            'local_cert' => '/tmp/a.pem',
            'local_pk' => '/tmp/b.pem',
            'allow_self_signed' => true,
            'verify_peer' => false,
        ], $context['ssl']);
    }

    public function testListenStringKeepsTheHistoricalDefaultLiteral(): void
    {
        // Byte-for-byte the shipped bind: the DEFAULT is not flipped here.
        self::assertSame('websocket://0.0.0.0:8803', WsTlsContext::listenString('0.0.0.0', 8803));
        self::assertSame('websocket://127.0.0.1:8804', WsTlsContext::listenString('127.0.0.1', 8804));
        self::assertSame('websocket://hub.example:8803', WsTlsContext::listenString('hub.example', 8803));
    }

    public function testListenStringBracketsIpv6Hosts(): void
    {
        // Without brackets the listen string is ambiguous and the bind fails
        // at runAll() — far from the knob that caused it.
        self::assertSame('websocket://[::1]:8803', WsTlsContext::listenString('::1', 8803));
        self::assertSame(
            'websocket://[2001:db8::5]:8804',
            WsTlsContext::listenString('2001:db8::5', 8804),
        );
        // Already-bracketed input is not double-wrapped.
        self::assertSame('websocket://[::1]:8803', WsTlsContext::listenString('[::1]', 8803));
    }
}
