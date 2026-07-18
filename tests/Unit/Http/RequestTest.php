<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Unit tests for {@see Request} — focusing on the Workerman-path remote-IP
 * population (Step 0 of the hub trusted-proxy fix) and the trusted-proxy-aware
 * {@see Request::getTrustedClientIp()} used to key the login/jwks rate limiters.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 *
 * @covers \Phlix\Hub\Http\Request
 */
final class RequestTest extends TestCase
{
    /**
     * Build a real Workerman HTTP request from a raw buffer, optionally with
     * extra headers.
     *
     * @param array<string, string> $headers
     */
    private function workermanRequest(string $path = '/api/v1/auth/login', array $headers = []): WorkermanRequest
    {
        $lines = ["GET {$path} HTTP/1.1", 'Host: hub.example.com'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }

    /**
     * Step 0: passing the TcpConnection populates remoteIp/remotePort from the
     * direct TCP peer — without it every IP-keyed limiter collapses into one
     * `0.0.0.0` bucket.
     */
    public function testFromWorkermanWithConnectionPopulatesRemoteIpAndPort(): void
    {
        $conn = $this->createMock(TcpConnection::class);
        $conn->method('getRemoteIp')->willReturn('127.0.0.1');
        $conn->method('getRemotePort')->willReturn(54321);

        $request = Request::fromWorkerman($this->workermanRequest(), $conn);

        self::assertSame('127.0.0.1', $request->remoteIp);
        self::assertSame(54321, $request->remotePort);
    }

    /**
     * Step 0: with NO connection (tests / detached parsing) remoteIp keeps its
     * `'0.0.0.0'` default and remotePort stays 0.
     */
    public function testFromWorkermanWithoutConnectionKeepsDefaults(): void
    {
        $request = Request::fromWorkerman($this->workermanRequest());

        self::assertSame('0.0.0.0', $request->remoteIp);
        self::assertSame(0, $request->remotePort);
    }

    /**
     * getTrustedClientIp(): a loopback peer + forged leftmost XFF (real client
     * appended rightmost by HAProxy) resolves to the REAL client — NOT `0.0.0.0`
     * and NOT the forged leftmost value.
     */
    public function testGetTrustedClientIpDerivesRealClientBehindLoopbackProxy(): void
    {
        $conn = $this->createMock(TcpConnection::class);
        $conn->method('getRemoteIp')->willReturn('127.0.0.1');
        $conn->method('getRemotePort')->willReturn(1);

        $request = Request::fromWorkerman(
            $this->workermanRequest('/api/v1/auth/login', [
                'X-Forwarded-For' => '198.51.100.66, 203.0.113.50',
            ]),
            $conn,
        );

        $ip = $request->getTrustedClientIp();
        self::assertSame('203.0.113.50', $ip);
        self::assertNotSame('0.0.0.0', $ip);
        self::assertNotSame('198.51.100.66', $ip);
    }

    /**
     * getTrustedClientIp(): a direct (untrusted) peer ignores the forwarding
     * headers entirely — the peer address wins.
     */
    public function testGetTrustedClientIpIgnoresXffFromUntrustedPeer(): void
    {
        $conn = $this->createMock(TcpConnection::class);
        $conn->method('getRemoteIp')->willReturn('198.51.100.10');
        $conn->method('getRemotePort')->willReturn(1);

        $request = Request::fromWorkerman(
            $this->workermanRequest('/api/v1/auth/login', [
                'X-Forwarded-For' => '1.2.3.4',
            ]),
            $conn,
        );

        self::assertSame('198.51.100.10', $request->getTrustedClientIp());
    }
}
