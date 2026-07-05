<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Covers {@see HubServicesProvider::startMaintenanceTimers()} — the method that
 * arms the periodic reapers from within a worker's event loop (cid>=0) instead
 * of {@see HubServicesProvider::boot()} (the master's pcntl signal scheduler,
 * cid<0, which bypassed {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}'s
 * per-connection mutex and 500ed heartbeat/claim with "already active
 * transaction").
 *
 * The wiring's contract is that each of the four timers is resolved and armed
 * INDEPENDENTLY under its own try/catch, so one unavailable — or mis-typed —
 * service can never block the others. These tests assert exactly that: every
 * service is still requested when each resolution fails, and nothing escapes.
 * They stay hermetic (no event loop) by never letting a real reaper resolve, so
 * no {@see \Workerman\Timer::add()} is ever reached.
 */
#[CoversClass(HubServicesProvider::class)]
final class HubServicesProviderTest extends TestCase
{
    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // startMaintenanceTimers() resolves its logger through the static
        // LoggerFactory; point it at a memory stream so the guarded failures it
        // logs go nowhere (no real log files, no PHPUnit output).
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-hubservices-test-' . uniqid();
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

    /**
     * The four services the maintenance wiring resolves, in the exact order it
     * arms their timers.
     *
     * @return list<class-string>
     */
    private function expectedServices(): array
    {
        return [
            IdleReaper::class,
            ServerReaper::class,
            TunnelManager::class,
            FederationSessionManager::class,
        ];
    }

    public function testResolvesEveryServiceEvenWhenEachResolutionThrows(): void
    {
        // A container that throws for every get(): were the four arms not each
        // independently guarded, the first throw would abort the rest and the
        // later services would never be requested.
        $container = new class implements ContainerInterface {
            /** @var list<string> */
            public array $seen = [];

            public function get(string $id): mixed
            {
                $this->seen[] = $id;
                throw new \RuntimeException("unavailable: {$id}");
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        HubServicesProvider::startMaintenanceTimers($container);

        self::assertSame($this->expectedServices(), $container->seen);
    }

    public function testSkipsWiringWhenServicesResolveToUnexpectedTypes(): void
    {
        // A container returning the wrong type for every get(): the instanceof
        // guards must all be false, so no reaper is started and no Timer armed —
        // and every service is still attempted (proving independent handling).
        $container = new class implements ContainerInterface {
            /** @var list<string> */
            public array $seen = [];

            public function get(string $id): mixed
            {
                $this->seen[] = $id;
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        HubServicesProvider::startMaintenanceTimers($container);

        self::assertSame($this->expectedServices(), $container->seen);
    }
}
