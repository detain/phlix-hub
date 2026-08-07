<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\McpController;
use Phlix\Hub\Http\Controllers\McpTokenController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The S62 MCP services are wired by {@see HubServicesProvider}, and until this
 * file nothing ever RESOLVED them.
 *
 * ## Why this is not coverage theatre
 *
 * Every other MCP suite hand-builds its own {@see McpToolRegistry} and its own
 * {@see McpController}. That is right for those suites — they are testing
 * behaviour, not wiring — but it means the container's OWN definitions were
 * never executed by anything, and two specific defects could therefore ship
 * unnoticed:
 *
 *  1. **The catalogue could drift.** The provider lists the five tools
 *     explicitly. A sixth added there would be published to every PAT holder,
 *     and no test would notice, because every test builds its own five-tool
 *     registry. {@see test_the_container_publishes_exactly_the_five_shipped_tools()}
 *     is the one place the SHIPPED catalogue is asserted.
 *  2. **A config value could silently stop arriving.** `mcp_token_ttl` is read
 *     inside the factory closure; if that plumbing broke, every token would
 *     quietly get the 90-day default and the operator's setting would be inert.
 *     PHP-DI's `autowire()` skipping optional parameters has produced exactly
 *     this class of silent `null`/default in this repository before, and only a
 *     container-RESOLUTION test catches it.
 *
 * ## What is doubled, and why that is honest
 *
 * Only the leaves the MCP graph does not own: the MySQL {@see Connection}, the
 * two production controllers the MCP endpoint borrows, and the shared
 * DB-backed limiter profile. Everything between them — the token service, the
 * token controller, the registry and the endpoint — is resolved from the REAL
 * provider definitions, which is the thing under test.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
#[CoversClass(HubServicesProvider::class)]
final class McpContainerWiringTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown(). The
    // McpController factory resolves its audit-channel logger through it.
    use LoggerFactoryIsolation;

    /** A TTL that is nobody's default, so "the config arrived" is unambiguous. */
    private const int CONFIGURED_TTL = 4243;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-mcp-wiring-' . uniqid();
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
     * The catalogue a real PAT holder sees is exactly the five S62 read-only
     * tools — no more (a sixth would widen what a token can reach) and no fewer.
     */
    public function test_the_container_publishes_exactly_the_five_shipped_tools(): void
    {
        $registry = $this->container()->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);

        self::assertSame(
            ['get_media', 'get_playback_info', 'list_libraries', 'list_servers', 'search_media'],
            $registry->names(),
            'the tool catalogue the CONTAINER publishes has changed. Every other MCP suite builds its '
            . 'own registry, so this is the only place a tool added to (or removed from) '
            . 'HubServicesProvider is visible. Widening what a personal access token can reach must be '
            . 'a deliberate edit here too.',
        );
    }

    /**
     * `mcp_token_ttl` reaches {@see McpTokenService}, rather than the factory
     * quietly falling back to the 90-day default.
     */
    public function test_the_configured_token_ttl_reaches_the_token_service(): void
    {
        $service = $this->container()->get(McpTokenService::class);
        self::assertInstanceOf(McpTokenService::class, $service);

        self::assertSame(self::CONFIGURED_TTL, self::ttlOf($service));
    }

    /**
     * Control for the test above: with no `mcp_token_ttl` configured the service
     * gets the documented default. Without this, a factory that hard-coded
     * {@see self::CONFIGURED_TTL} would pass the test above, and a factory that
     * ignored the config entirely would be indistinguishable from one that
     * honoured it if the default happened to match.
     */
    public function test_an_absent_token_ttl_falls_back_to_the_documented_default(): void
    {
        $service = $this->container(ttl: null)->get(McpTokenService::class);
        self::assertInstanceOf(McpTokenService::class, $service);

        self::assertSame(McpTokenService::DEFAULT_TTL_SECONDS, self::ttlOf($service));
        self::assertNotSame(
            self::CONFIGURED_TTL,
            McpTokenService::DEFAULT_TTL_SECONDS,
            'the fixture TTL equals the default, so the test above proves nothing.',
        );
    }

    /**
     * `POST /mcp`'s controller resolves, and it is handed the CONTAINER's
     * registry and token service — not a second set built inside the factory.
     *
     * A factory that constructed its own registry would publish a different tool
     * list from the one asserted above, and nothing else would notice.
     */
    public function test_the_endpoint_resolves_and_shares_the_containers_registry(): void
    {
        $container = $this->container();

        $controller = $container->get(McpController::class);
        self::assertInstanceOf(McpController::class, $controller);

        self::assertSame(
            $container->get(McpToolRegistry::class),
            self::privateValue($controller, 'registry'),
        );
        self::assertSame(
            $container->get(McpTokenService::class),
            self::privateValue($controller, 'tokens'),
        );
    }

    /**
     * The token-management controller resolves, over the same token service the
     * endpoint validates against — a mint on one and a validate on the other
     * have to agree about TTL and hashing.
     */
    public function test_the_token_controller_resolves_over_the_same_token_service(): void
    {
        $container = $this->container();

        $controller = $container->get(McpTokenController::class);
        self::assertInstanceOf(McpTokenController::class, $controller);

        self::assertSame(
            $container->get(McpTokenService::class),
            self::privateValue($controller, 'tokens'),
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * A container holding the REAL {@see HubServicesProvider} definitions, with
     * only the leaves the MCP graph does not own replaced.
     *
     * @param int|null $ttl `mcp_token_ttl`, or `null` to leave it unconfigured.
     */
    private function container(int|null $ttl = self::CONFIGURED_TTL): Container
    {
        /** @var array<string, mixed> $appConfig */
        $appConfig = ['hub_base_url' => 'http://localhost:8800'];
        if ($ttl !== null) {
            $appConfig['mcp_token_ttl'] = $ttl;
        }

        $builder = new ContainerBuilder();
        (new HubServicesProvider())->register($builder, $appConfig);

        // Overrides come AFTER register() so they win. Each one is a boundary
        // this test is not exercising: the DB, and the two production
        // controllers whose gates McpToolContextTest / McpCrossUserIsolationTest
        // already drive for real.
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $sessions = $this->createMock(RelaySessionManager::class);

        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
            // Registered by CoreServicesProvider, not this one; supplied here so
            // the graph closes without pulling in a second provider's config.
            AuditLogger::class => new AuditLogger($this->createMock(StructuredLogger::class)),
            ServerListController::class => new ServerListController($serverInfo),
            ServerProxyController::class => new ServerProxyController(
                $serverInfo,
                (new ReflectionClass(RelayProxyBridge::class))->newInstanceWithoutConstructor(),
                $this->createMock(StructuredLogger::class),
                $sessions,
                new RateLimiter(60, 600, 1000),
            ),
            RateLimitProfiles::MCP => new RateLimiter(900, 10, 1000),
        ]);

        return $builder->build();
    }

    private static function ttlOf(McpTokenService $service): int
    {
        $value = self::privateValue($service, 'ttlSeconds');
        self::assertIsInt($value);

        return $value;
    }

    private static function privateValue(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object::class, $property))->getValue($object);
    }
}
