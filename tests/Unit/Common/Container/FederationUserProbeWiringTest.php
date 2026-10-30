<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container;

use Closure;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * R17-07 wiring guard: the container-resolved FederationPeerManager MUST carry
 * a wired localUserProbe (the users-table resolution seam that gates admin
 * delegation writes).
 *
 * The consumer FAILS CLOSED when the probe is unwired — every delegation is
 * refused and audited — which is exactly why a forgotten wiring would never
 * crash anything: the reserved envelope would just silently never persist,
 * reproducing the write-only-feature dead end this lane is hardening. A
 * slot-ordering mistake in the provider factory (the probe riding in as the
 * 8th argument, or being dropped entirely) is invisible to the unit tests
 * because they construct the manager directly; only the production definition
 * proves the seam is threaded through. This is the venue that does it.
 *
 * Deliberately narrow: no DB, no key material — the Connection entry arrives
 * via the estate's FixedConnectionProvider stand-in and Ed25519KeyManager is
 * passive at construction, so resolving the graph side-effect-free is the
 * whole assertion surface (plus arity: the probe must accept the user id,
 * pinning it is NOT the zero-arg tlsExtensionProbe shape).
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container
 */
final class FederationUserProbeWiringTest extends TestCase
{
    use LoggerFactoryIsolation;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-hub-r1707-wire-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        file_put_contents(
            $this->dir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->dir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        // AuthServicesProvider resolves the HS256 secret EAGERLY at register()
        // time (MissingJwtSecretException without one) — same shape as
        // MaintenanceHealthWiringTest / AuditLoggerContainerBindingTest.
        // Nothing here reads it.
        $authPath = $this->dir . '/auth.php';
        file_put_contents(
            $authPath,
            "<?php return ['secret' => 'r1707-container-wiring-test-secret-0123456789'];",
        );

        return [
            'auth_config_path' => $authPath,
            'logger_config_path' => $this->dir . '/logger.php',
            'hub_signing_key_path' => $this->dir . '/fed-ed25519.pem',
            'public_root' => dirname(__DIR__, 3) . '/public',
        ];
    }

    public function testTheContainerWiresTheLocalUserProbeOnThePeerManager(): void
    {
        // FixedConnectionProvider substitutes the container's Connection entry
        // (the estate venue precedent: AuditLoggerContainerBindingTest) so the
        // federation graph resolves without a live schema. The vendor
        // Connection constructor connects eagerly, so the stand-in is built
        // WITHOUT a constructor — legal here because this guard never issues a
        // query; it only inspects what the container threaded through.
        $db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        $container = ContainerFactory::create(
            $this->config(),
            [...ContainerFactory::defaultProviders(), new FixedConnectionProvider($db)],
        );

        $manager = $container->get(FederationPeerManager::class);
        self::assertInstanceOf(FederationPeerManager::class, $manager);

        $property = new ReflectionProperty($manager, 'localUserProbe');
        $property->setAccessible(true);

        /** @var mixed $probe */
        $probe = $property->getValue($manager);

        // instanceof guard, not PHPUnit::assertInstanceOf: psalm (tests at L5)
        // does not carry the assertion's narrowing into ReflectionFunction's
        // Closure parameter, and the guard states the same law with an
        // early-exit (code-philosophy Law 1) while typing $probe for analysis.
        if (!$probe instanceof Closure) {
            self::fail(
                'R17-07: production MUST wire the users-table probe — the consumer'
                . ' fails closed when null, so a missing wiring degrades silently',
            );
        }

        $reflection = new ReflectionFunction($probe);
        self::assertGreaterThanOrEqual(
            1,
            $reflection->getNumberOfParameters(),
            'The probe must accept the candidate user id — arity 0 would mean it'
            . ' rode in through the zero-arg tlsExtensionProbe slot instead',
        );
    }
}
