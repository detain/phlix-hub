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
use Phlix\Hub\Http\Request;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpSseStream;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function dirname;
use function file_put_contents;
use function json_decode;
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
     * ⚠ THE FLAG GATE. With no `mcp_playback_control_enabled` in the config, the
     * catalogue a real PAT holder sees is exactly the five READ-ONLY tools.
     *
     * `playback_control` is absent — not present-and-refusing. That is the
     * stronger shape: an unregistered tool appears in no `tools/list`, so a model
     * never sees a capability it cannot use, and `tools/call` for it is
     * `mcp.unknown_tool`.
     *
     * This is the DEFAULT-OFF assertion, and it is written against an
     * unconfigured container on purpose — the state a fresh deployment is in.
     */
    public function test_the_container_publishes_only_read_tools_when_the_flag_is_absent(): void
    {
        $registry = $this->container()->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);

        self::assertSame(
            ['get_media', 'get_playback_info', 'list_libraries', 'list_servers', 'search_media'],
            $registry->names(),
            'the tool catalogue the CONTAINER publishes has changed. Every other MCP suite builds its '
            . 'own registry, so this is the only place a tool added to (or removed from) '
            . 'HubServicesProvider is visible. Widening what a personal access token can reach must be '
            . 'a deliberate edit here too. In particular playback_control — the only tool that WRITES '
            . 'to a media server — must not appear here without the operator flag.',
        );
    }

    /**
     * ...and the config value must be a real `true`.
     *
     * These are the values an operator mistypes, or that an env var arrives as
     * when the resolution in `config/server.php` is bypassed. `=== true` is the
     * comparison, so every one of them leaves the write tool unregistered. A
     * truthiness test would publish it for the first four.
     *
     * @dataProvider notTrueProvider
     */
    public function test_a_flag_value_that_is_not_exactly_true_leaves_the_tool_unregistered(mixed $value): void
    {
        $registry = $this->container(playbackControl: $value)->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);

        self::assertNotContains(
            'playback_control',
            $registry->names(),
            'a non-boolean-true flag value published the write tool.',
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notTrueProvider(): array
    {
        return [
            'the string "true"' => ['true'],
            'the string "1"' => ['1'],
            'the integer 1' => [1],
            'a non-empty string' => ['yes please'],
            'the string "false"' => ['false'],
            'boolean false' => [false],
            'null' => [null],
            'the integer 0' => [0],
        ];
    }

    /**
     * THE SUCCEEDING CONTROL. With the flag explicitly `true` the tool IS
     * registered, with its own scope.
     *
     * Without this row, a provider that had simply DELETED the
     * `playback_control` registration would satisfy every assertion above
     * perfectly — the "off by default" tests would all be green and the feature
     * would not exist. This is what makes them mean "gated" rather than "absent".
     */
    public function test_the_flag_publishes_the_playback_control_tool(): void
    {
        $registry = $this->container(playbackControl: true)->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);

        self::assertSame(
            [
                'get_media',
                'get_playback_info',
                'list_libraries',
                'list_servers',
                'playback_control',
                'search_media',
            ],
            $registry->names(),
        );
    }

    /**
     * ...and the published tool declares the WRITE scope, not a read one.
     *
     * A registration that published `playback_control` under, say,
     * `mcp:playback:read` would let every existing read-scoped token drive a
     * device. The flag and the scope are independent gates and neither
     * substitutes for the other.
     */
    public function test_the_published_playback_tool_requires_the_control_scope(): void
    {
        $registry = $this->container(playbackControl: true)->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);

        $scopes = [];
        foreach ($registry->describe() as $descriptor) {
            if (($descriptor['name'] ?? null) === 'playback_control') {
                $scopes[] = $descriptor['x-phlix-scope'] ?? null;
            }
        }

        self::assertSame([McpScopes::PLAYBACK_CONTROL], $scopes);
    }

    /**
     * The SSE transport resolves from the container and carries the configured
     * timings — the same silent-default trap `mcp_token_ttl` has.
     */
    public function test_the_sse_stream_resolves_with_its_configured_timings(): void
    {
        $stream = $this->container(sseKeepalive: 7, sseMax: 77)->get(McpSseStream::class);
        self::assertInstanceOf(McpSseStream::class, $stream);

        self::assertSame(7, self::privateValue($stream, 'keepaliveSeconds'));
        self::assertSame(77, self::privateValue($stream, 'maxSeconds'));
    }

    /**
     * Control for the test above: unconfigured, the documented defaults arrive.
     */
    public function test_an_unconfigured_sse_stream_uses_the_documented_defaults(): void
    {
        $stream = $this->container()->get(McpSseStream::class);
        self::assertInstanceOf(McpSseStream::class, $stream);

        self::assertSame(McpSseStream::DEFAULT_KEEPALIVE_SECONDS, self::privateValue($stream, 'keepaliveSeconds'));
        self::assertSame(McpSseStream::DEFAULT_MAX_SECONDS, self::privateValue($stream, 'maxSeconds'));
        self::assertNotSame(
            7,
            McpSseStream::DEFAULT_KEEPALIVE_SECONDS,
            'the fixture value equals the default, so the test above proves nothing.',
        );
    }

    /**
     * `config/server.php` really does resolve the flag to a bool, and really
     * does default it OFF.
     *
     * The provider compares with `=== true`, so the whole gate rests on that
     * resolution happening. Asserting the provider alone would leave a config
     * file that handed it the string `"false"` (which `=== true` rejects, so the
     * tool would be off) or the string `"true"` (which `=== true` ALSO rejects,
     * so the tool would be off even when the operator asked for it) equally
     * invisible. This reads the real file.
     */
    public function test_the_shipped_config_defaults_the_flag_to_boolean_false(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/server.php';

        self::assertArrayHasKey('mcp_playback_control_enabled', $config);
        self::assertFalse(
            $config['mcp_playback_control_enabled'],
            'the shipped default must be boolean false: playback_control writes to a media server '
            . 'through casting backends that are not production-functional.',
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

    /**
     * S261 — the `playback_control` flag reaches {@see McpTokenController}, so
     * `available_scopes` narrows with an UNCONFIGURED container.
     *
     * This is the `mcp_token_ttl` trap pointed at a security-visible value: the
     * flag is read inside the factory closure, and if that plumbing broke the
     * controller would fall back to whatever its constructor said and the hub
     * would go on advertising a scope it cannot honour. Resolving the REAL
     * definition is the only thing that can see it — `McpTokenControllerTest`
     * hands the flag in by hand, so it proves the controller obeys the flag and
     * says nothing about the flag arriving.
     */
    public function test_the_token_controller_hides_the_write_scope_when_the_flag_is_absent(): void
    {
        $controller = $this->container()->get(McpTokenController::class);
        self::assertInstanceOf(McpTokenController::class, $controller);

        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            self::advertisedScopes($controller),
            'the container advertised a scope set that does not match its own tool catalogue.',
        );
    }

    /**
     * THE SUCCEEDING CONTROL: with the flag `true` the container's controller
     * advertises the whole vocabulary, exactly as it registers the tool.
     *
     * The two assertions below are the pair that matters — the SAME container
     * both publishes `playback_control` and advertises `mcp:playback:control`.
     * One reader of the flag, one answer.
     */
    public function test_the_token_controller_advertises_the_write_scope_when_the_flag_is_true(): void
    {
        $container = $this->container(playbackControl: true);

        $controller = $container->get(McpTokenController::class);
        self::assertInstanceOf(McpTokenController::class, $controller);

        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read', 'mcp:playback:control'],
            self::advertisedScopes($controller),
        );

        $registry = $container->get(McpToolRegistry::class);
        self::assertInstanceOf(McpToolRegistry::class, $registry);
        self::assertContains('playback_control', $registry->names());
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * `available_scopes` as the resolved controller actually renders it, read
     * off a real `GET /api/v1/me/mcp-tokens` response rather than off a private
     * property — the property is a bool, and a bool being present says nothing
     * about it being consulted.
     *
     * @return mixed The decoded `available_scopes` value (asserted to be a list
     *         of strings by the callers' `assertSame`).
     */
    private static function advertisedScopes(McpTokenController $controller): mixed
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/me/mcp-tokens';
        $request->userId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $response = $controller->index($request);
        self::assertSame(200, $response->statusCode);

        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        return $decoded['available_scopes'] ?? null;
    }

    /**
     * A container holding the REAL {@see HubServicesProvider} definitions, with
     * only the leaves the MCP graph does not own replaced.
     *
     * @param int|null $ttl             `mcp_token_ttl`, or `null` to leave it
     *        unconfigured.
     * @param mixed    $playbackControl `mcp_playback_control_enabled`. `null`
     *        leaves the key ABSENT (the fresh-deployment state), which is not the
     *        same as setting it to `false` — both must leave the tool off, and
     *        both are covered.
     * @param int|null $sseKeepalive    `mcp_sse_keepalive_seconds`, or null.
     * @param int|null $sseMax          `mcp_sse_max_seconds`, or null.
     */
    private function container(
        int|null $ttl = self::CONFIGURED_TTL,
        mixed $playbackControl = null,
        ?int $sseKeepalive = null,
        ?int $sseMax = null,
    ): Container {
        /** @var array<string, mixed> $appConfig */
        $appConfig = ['hub_base_url' => 'http://localhost:8800'];
        if ($ttl !== null) {
            $appConfig['mcp_token_ttl'] = $ttl;
        }
        // Absent by default — the state of a fresh deployment, and the state the
        // default-off assertions must be made against.
        if ($playbackControl !== null) {
            $appConfig['mcp_playback_control_enabled'] = $playbackControl;
        }
        if ($sseKeepalive !== null) {
            $appConfig['mcp_sse_keepalive_seconds'] = $sseKeepalive;
        }
        if ($sseMax !== null) {
            $appConfig['mcp_sse_max_seconds'] = $sseMax;
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
