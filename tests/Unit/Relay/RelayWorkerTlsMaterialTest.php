<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * TLS-lane laws for the :8802 bind material (successor seam to M21).
 *
 *   1. {@see RelayWorker::relayTlsContext()} is the ONE canonical hardened
 *      context builder — the shape the production listener binds with and the
 *      shape the real-TLS loopback E2E child (RelayWorkerTlsHandshakeE2ETest)
 *      exercises byte-for-byte;
 *   2. {@see RelayWorker::assertTlsMaterialReadable()} fails FAST, pre-bind,
 *      on cert/key paths that are not readable files — naming the env knob,
 *      the resolved path, and both ways out (red on the parent: it happily
 *      built an ssl context around nonexistent paths and left the hub to die
 *      cryptically inside stream_socket_server, half-booted);
 *   3. start() consults the guard before any Worker exists.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class RelayWorkerTlsMaterialTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private ContainerInterface $container;

    /** @var array<int, string> Scratch paths removed in tearDown(). */
    private array $scratchFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        RelayWorker::reset();
        $this->container = $this->createMock(ContainerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RelayWorker::reset();
        foreach ($this->scratchFiles as $path) {
            @unlink($path);
        }
        $this->scratchFiles = [];
    }

    private function scratchPem(string $role): string
    {
        $path = sys_get_temp_dir() . '/phlix-relay-tls-' . $role . '-' . bin2hex(random_bytes(6)) . '.pem';
        if (file_put_contents($path, "-----BEGIN SCRATCH $role-----\n") === false) {
            self::fail("could not write scratch $role pem to $path");
        }
        $this->scratchFiles[] = $path;

        return $path;
    }

    // ---- 1. canonical context shape ------------------------------------------

    public function testRelayTlsContextCarriesTheHardenedServerShape(): void
    {
        $cert = $this->scratchPem('cert');
        $key = $this->scratchPem('key');

        $context = RelayWorker::relayTlsContext($cert, $key);

        self::assertSame(['ssl'], array_keys($context), 'exactly one top-level section: ssl');

        $ssl = $context['ssl'];
        self::assertSame(
            [
                'ciphers',
                'ecdh_curve',
                'disable_compression',
                'security_level',
                'local_cert',
                'local_pk',
                'allow_self_signed',
                'verify_peer',
            ],
            array_keys($ssl),
        );
        self::assertSame($cert, $ssl['local_cert']);
        self::assertSame($key, $ssl['local_pk']);
        self::assertSame(
            'ECDHE+AESGCM:ECDHE+CHACHA20:DHE+AESGCM:DHE+CHACHA20:!aNULL:!MD5:!DSS',
            $ssl['ciphers'],
            'AEAD-only suites — the audited hardening list, not an OpenSSL default drift',
        );
        self::assertSame('secp384r1', $ssl['ecdh_curve']);
        self::assertTrue($ssl['disable_compression'], 'compression off (CRIME)');
        self::assertSame(2, $ssl['security_level'], 'OpenSSL security level 2: >=TLS1.2-grade material');
        self::assertTrue($ssl['allow_self_signed'], 'server-side ctx: it validates NO client certs');
        self::assertFalse($ssl['verify_peer'], 'tunnel auth is the HELLO enrollment JWT, not mTLS');
    }

    // ---- 2. pre-bind readability guard ---------------------------------------

    public function testGuardAcceptsReadableMaterial(): void
    {
        RelayWorker::assertTlsMaterialReadable($this->scratchPem('cert'), $this->scratchPem('key'));

        $this->expectNotToPerformAssertions();
    }

    public function testGuardNamesTheMissingCertPathAndBothExits(): void
    {
        $missing = '/tmp/phlix-relay-tls-no-such-cert-' . bin2hex(random_bytes(6)) . '.pem';

        try {
            RelayWorker::assertTlsMaterialReadable($missing, $this->scratchPem('key'));
            self::fail('unreadable TLS material must throw before any bind');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('HUB_RELAY_TLS_CERT', $e->getMessage());
            self::assertStringContainsString($missing, $e->getMessage());
            self::assertStringContainsString('does not exist', $e->getMessage());
            self::assertStringContainsString('HUB_RELAY_TLS=false', $e->getMessage());
        }
    }

    public function testGuardNamesTheMissingKeyPathWhenTheCertIsFine(): void
    {
        $missing = '/tmp/phlix-relay-tls-no-such-key-' . bin2hex(random_bytes(6)) . '.pem';

        try {
            RelayWorker::assertTlsMaterialReadable($this->scratchPem('cert'), $missing);
            self::fail('unreadable TLS material must throw before any bind');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('HUB_RELAY_TLS_KEY', $e->getMessage());
            self::assertStringContainsString($missing, $e->getMessage());
        }
    }

    // ---- 3. start() consults the guard before constructing anything ----------

    public function testStartWithAbsentCertFileThrowsInsteadOfHandingBackAWorker(): void
    {
        // Red on the parent: this exact construction returned a fully
        // configured cleartext-blind Worker whose bind would only explode
        // inside runAll() — after ChannelServer and any earlier listeners.
        $worker = new RelayWorker(
            $this->container,
            0,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            '/tmp/phlix-relay-tls-absent-cert-' . bin2hex(random_bytes(6)) . '.pem',
            $this->scratchPem('key'),
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/HUB_RELAY_TLS_CERT/');

        $worker->start();
    }

    public function testStartWithReadableMaterialStillBuildsTheSslWorker(): void
    {
        $worker = new RelayWorker(
            $this->container,
            0,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
            true,
            $this->scratchPem('cert'),
            $this->scratchPem('key'),
            false,
        );

        self::assertSame('ssl', $worker->start()->transport);
    }
}
