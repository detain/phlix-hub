<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Alexa\AlexaRejectionAuditorInterface;
use Phlix\Hub\Alexa\CurlCertChainFetcher;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * S90 — prove the Alexa gate is actually resolvable, with real collaborators.
 *
 * A unit test that constructs the middleware by hand says nothing about the
 * container. This repo has already been bitten by the gap: PHP-DI's
 * `autowire()` SKIPS optional constructor parameters, so a dependency with a
 * default silently stays at its default and only a container-resolution test
 * notices. The binding is written with an explicit `factory()` for that reason,
 * and this test is what holds it to it.
 *
 * It also pins the singleton-ness, which is not cosmetic: the middleware holds
 * the per-worker cache of verified certificate chains, so a per-request instance
 * would turn every Alexa request back into a blocking https fetch inside a
 * resident Workerman worker — the exact hazard the cache exists to avoid, and
 * one that no functional test would notice because the requests would all still
 * be accepted.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class AlexaSignatureMiddlewareWiringTest extends TestCase
{
    use LoggerFactoryIsolation;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-alexa-wiring-' . uniqid();
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

    public function testTheGateResolvesWithEveryDependencyPopulated(): void
    {
        $container = $this->buildContainer();

        $middleware = $container->get(AlexaSignatureMiddleware::class);
        self::assertInstanceOf(AlexaSignatureMiddleware::class, $middleware);

        self::assertInstanceOf(
            CurlCertChainFetcher::class,
            $this->readPrivate($middleware, 'fetcher'),
            'production must get the bounded cURL fetcher, not some autowired stand-in',
        );
        self::assertInstanceOf(
            StructuredLogger::class,
            $this->readPrivate($middleware, 'logger'),
            'a security gate with a null logger records none of its rejections',
        );
        self::assertSame(
            [],
            $this->readPrivate($middleware, 'caBundlePaths'),
            'production anchors chains in the SYSTEM trust store; a stray test CA here would '
            . 'let a self-signed chain pass the gate on a live hub',
        );

        // S91's two additions. Both are REQUIRED constructor parameters, so a
        // null here cannot come from PHP-DI skipping an optional — it would mean
        // the factory stopped passing them, which is the shape of defect this
        // whole suite exists for.
        self::assertInstanceOf(
            RateLimiterInterface::class,
            $this->readPrivate($middleware, 'rateLimiter'),
            'without a limiter the public Alexa endpoint has no flood ceiling at all',
        );
        self::assertInstanceOf(
            AlexaRejectionAuditorInterface::class,
            $this->readPrivate($middleware, 'auditor'),
            'without an auditor no Alexa rejection is ever recorded',
        );
    }

    public function testTheGateIsASingletonSoItsChainCacheSurvivesBetweenRequests(): void
    {
        $container = $this->buildContainer();

        self::assertSame(
            $container->get(AlexaSignatureMiddleware::class),
            $container->get(AlexaSignatureMiddleware::class),
        );
    }

    /**
     * The container this suite resolves the gate from.
     *
     * S91 gave the gate two more dependencies, and closing the graph needs two
     * things `HubServicesProvider` does not itself register:
     *
     *  - {@see CommonServicesProvider}, which is where every `rate_limiter.*`
     *    profile is bound. It is registered rather than stubbed on purpose: the
     *    production `rate_limiter.alexa` binding is exactly what must exist, and
     *    a hand-written stub here would resolve happily even if
     *    `RateLimitProfiles::ALEXA` were never added to `defaults()`.
     *  - a `Connection` double, because the auditor pulls in `AuditLogRepository`.
     *    That is a real database boundary this suite has no business owning.
     */
    private function buildContainer(): \DI\Container
    {
        $builder = new ContainerBuilder();
        (new CommonServicesProvider())->register($builder, []);
        (new HubServicesProvider())->register($builder, []);
        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
        ]);

        return $builder->build();
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
