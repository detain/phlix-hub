<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;

/**
 * Wiring guard for the relay tunnel's enrollment-JWT validation seam.
 *
 * {@see Tunnel::validateHelloJwt()} treats a NULL {@see EnrollmentJwtService}
 * as "skip validation" (documented test-only affordance — the constructor
 * parameter defaults to null for hand-built tunnels). The nullable seam is
 * exactly the shape a wiring regression loves: if the container ever started
 * threading null into the :8802 tunnel path, every HELLO would validate by
 * default and the guard would fail OPEN with zero test signal.
 *
 * This pins the two production facts:
 *   1. the {@see HubServicesProvider} TunnelManager factory resolves a REAL
 *      EnrollmentJwtService (a typed closure parameter fed by
 *      get(EnrollmentJwtService::class) — PHP rejects null there at the DI
 *      boundary, which is the "throws rather than injects null" law), and
 *   2. acceptServer() threads that same instance into every Tunnel it builds.
 *
 * Only the DATABASE bottom of the graph is stubbed (a mocked
 * {@see Connection}); the JWT service, key manager and settings repository
 * are the genuine container-built objects, so an identity assertion here
 * proves the real production wiring, not a fixture's self-consistency.
 */
final class TunnelManagerJwtWiringTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the
    // trait snapshots and restores them around each case (same venue law as
    // RelayProxyManagerWiringTest).
    use LoggerFactoryIsolation;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Point the logger factory at an in-memory stream (nothing written)
        // and the hub signing key at scratch space (Ed25519KeyManager is lazy
        // — nothing touches disk in this graph unless a case mints a token).
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-tunneljwt-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @rmdir($this->tmpDir);
    }

    private function buildContainer(): Container
    {
        $builder = new ContainerBuilder();
        (new HubServicesProvider())->register($builder, [
            'hub_base_url'            => 'http://127.0.0.1:8800',
            'hub_signing_key_path'    => $this->tmpDir . '/signing-key.pem',
        ]);

        // Stub ONLY the DB bottom. HubSettingsRepository/RelaySessionManager
        // constructors just retain the handle (queries run per call, none in
        // this graph's construction), so everything ABOVE stays real.
        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
        ]);

        return $builder->build();
    }

    public function testTheContainerBuiltTunnelManagerCarriesARealEnrollmentJwtService(): void
    {
        $manager = $this->buildContainer()->get(TunnelManager::class);
        self::assertInstanceOf(TunnelManager::class, $manager);

        $prop = new ReflectionProperty(TunnelManager::class, 'jwtService');
        $prop->setAccessible(true);
        $jwtService = $prop->getValue($manager);

        // The law: NOT null. A null here flips validateHelloJwt() into its
        // test-only skip branch for EVERY production tunnel.
        self::assertInstanceOf(
            EnrollmentJwtService::class,
            $jwtService,
            'the DI-constructed TunnelManager must be wired with a real EnrollmentJwtService'
        );
    }

    public function testAcceptServerThreadsTheSameJwtServiceIntoEveryTunnel(): void
    {
        $manager = $this->buildContainer()->get(TunnelManager::class);
        self::assertInstanceOf(TunnelManager::class, $manager);

        $managerProp = new ReflectionProperty(TunnelManager::class, 'jwtService');
        $managerProp->setAccessible(true);
        /** @var EnrollmentJwtService $managerJwt */
        $managerJwt = $managerProp->getValue($manager);
        self::assertInstanceOf(EnrollmentJwtService::class, $managerJwt);

        $tunnel = $manager->acceptServer('server-jwt-wiring', $this->createMock(TcpConnection::class));

        $tunnelProp = new ReflectionProperty(Tunnel::class, 'jwtService');
        $tunnelProp->setAccessible(true);

        self::assertSame(
            $managerJwt,
            $tunnelProp->getValue($tunnel),
            'acceptServer() must hand each Tunnel the manager\'s JWT validator — '
            . 'a dropped argument silently un-validates the :8802 HELLO path'
        );
    }
}
