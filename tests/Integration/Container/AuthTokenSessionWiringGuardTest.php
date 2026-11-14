<?php

/**
 * CP-029 / D-1a hub twin — the family store must be WIRING-CERTAIN.
 *
 * Hub mirror of the server's AuthTokenSessionWiringGuardTest (413765b6),
 * adapted where the hub's container layering differs (deviations recited in
 * .reviews/2026-11-10/FIX-hub-CP029-d1a-twin.md):
 *
 *  - the hub's AuthServicesProvider builds AuthManager in an EXPLICIT factory
 *    closure that opens the 'txn' pool handle inside it (registration
 *    election + carrier revocation), so this guard rides the real-DB
 *    integration venue (RegistrationTransactionWiringTest harness shape)
 *    rather than a unit container — pre-existing hub venue law, not a twin
 *    choice. Forgetting the store in the call line would be an
 *    ArgumentCountError at resolve (loud); the null-assert leg below still
 *    stands as the inversion guard: if a future edit restores an explicit
 *    `null` in the call line, the seam degrades SILENTLY into the legacy
 *    mint and only this pin notices;
 *  - the store BINDING rides the container's `Connection::class` node (the
 *    server twin's own pattern, shared with the UserRepository node beside
 *    it) — a pool-gated 'txn' factory for the store would put
 *    ConnectionPool::init() on the graph of every DB-free container that
 *    resolves the middleware or Alexa seam; leg 3 pins the store onto that
 *    shared node.
 *
 * Beyond the object graph, one leg EXECUTES the wiring: a container-resolved
 * register() must land a real family row and a real grant row keyed by the
 * jti the minted token actually carries. An instance identity that never
 * wrote a row would be a green lie.
 *
 * Phlix hub test: Container.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Container;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\TokenSessionRepository;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Tests\Support\ConnectionPoolTestControl;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use PHPUnit\Framework\Attributes\Group;
use Phlix\Hub\Tests\Support\Narrow;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * @package Phlix\Hub\Tests\Integration\Container
 *
 */
#[Group('integration')]
final class AuthTokenSessionWiringGuardTest extends RealDatabaseTestCase
{
    use ConnectionPoolTestControl;

    private const JWT_SECRET = 'CP-029-session-family-wiring-guard-secret-hub';

    private string $tmpDir = '';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pointConnectionPoolAtTestDatabase();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-cp029-guard-' . bin2hex(random_bytes(6));
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0700, true);
        }

        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        file_put_contents(
            $this->tmpDir . '/auth.php',
            "<?php\n\nreturn ['secret' => '" . self::JWT_SECRET . "'];\n",
        );

        $this->container = ContainerFactory::create(
            [
                'auth_config_path' => $this->tmpDir . '/auth.php',
                'logger_config_path' => $this->tmpDir . '/logger.php',
                'public_root' => dirname(__DIR__, 3) . '/public',
            ],
            [...ContainerFactory::defaultProviders(), new FixedConnectionProvider($this->db)],
        );
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testTheContainerBuiltAuthManagerCarriesTheFamilyStore(): void
    {
        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);

        /** @var mixed $store */
        $store = (new ReflectionProperty(AuthManager::class, 'tokenSessions'))->getValue($manager);
        self::assertInstanceOf(
            TokenSessionRepository::class,
            $store,
            'the provider factory line must PASS the family store — a null here '
            . 'degrades every mint to the legacy claim-less shape SILENTLY (the '
            . 'server twin\'s PHP-DI trap, inverted for the hub: loud today, '
            . 'quiet the moment an optional-null creeps back into the call)',
        );
    }

    public function testEveryFamilyStoreSeamSharesOneContainerInstance(): void
    {
        $store = $this->container->get(TokenSessionRepository::class);
        self::assertInstanceOf(TokenSessionRepository::class, $store);

        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);
        $middleware = $this->container->get(AuthMiddleware::class);
        self::assertInstanceOf(AuthMiddleware::class, $middleware);
        $alexa = $this->container->get(AlexaAccountLink::class);
        self::assertInstanceOf(AlexaAccountLink::class, $alexa);

        $managerStore = (new ReflectionProperty(AuthManager::class, 'tokenSessions'))
            ->getValue($manager);
        $middlewareStore = (new ReflectionProperty(AuthMiddleware::class, 'tokenSessions'))
            ->getValue($middleware);
        $alexaStore = (new ReflectionProperty(AlexaAccountLink::class, 'tokenSessions'))
            ->getValue($alexa);

        // One SSOT means ONE instance: two stores would mean two read paths
        // that can disagree about whether a family is alive.
        self::assertSame($store, $managerStore);
        self::assertSame($store, $middlewareStore);
        self::assertSame($store, $alexaStore);
    }

    public function testTheFamilyStoreRidesTheSharedContainerConnectionNode(): void
    {
        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);
        /** @var mixed $store */
        $store = (new ReflectionProperty(AuthManager::class, 'tokenSessions'))->getValue($manager);
        self::assertInstanceOf(TokenSessionRepository::class, $store);
        /** @var mixed $storeHandle */
        $storeHandle = (new ReflectionProperty(TokenSessionRepository::class, 'db'))->getValue($store);

        // The binding takes the container's Connection::class node — the
        // same node the UserRepository binding beside it consumes, which in
        // this harness is the FIXED test handle. A future edit that routes
        // this node through ConnectionPool would re-impose
        // ConnectionPool::init() on every DB-free container resolving the
        // middleware or Alexa seam (the exact unit-venue breakage the
        // CP-029 binding comment forbids) — and this pin reddens first.
        self::assertSame(
            $this->db,
            $storeHandle,
            'The family store must ride the shared container Connection node, '
            . 'not a per-seam constructed handle',
        );
    }

    public function testAContainerMintLandsFamilyAndGrantRowsKeyedByTheRealJti(): void
    {
        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);

        $response = $manager->register('cp029wired', 'cp029wired@example.test', 'Str0ng-Passw0rd!');

        /** @var mixed $mintedRows */
        $mintedRows = $this->db->query('SELECT id, user_id, status FROM auth_token_sessions');
        $mintedRows = Narrow::rows($mintedRows);
        self::assertCount(1, $mintedRows, 'the ceremony must open exactly one family');
        self::assertSame('active', $mintedRows[0]['status']);

        // The grant is keyed by the jti the handler ACTUALLY SIGNED — read
        // back from the wire artifact, never from the builder's own scope.
        $jwt = $this->container->get(JwtHandler::class);
        self::assertInstanceOf(JwtHandler::class, $jwt);
        $lineage = $jwt->validateRefreshTokenWithSession($response['refresh_token']);
        self::assertNotNull($lineage);
        $claims = $lineage['claims'];
        self::assertSame($mintedRows[0]['id'], $lineage['session_id']);

        /** @var mixed $grantRows */
        $grantRows = $this->db->query(
            'SELECT session_id FROM auth_refresh_grants WHERE jti = :jti',
            ['jti' => (string) $claims->jti],
        );
        self::assertIsArray($grantRows);
        self::assertSame(
            [$mintedRows[0]['id']],
            array_column($grantRows, 'session_id'),
            'an unregistered mint would make its own first rotation fail closed '
            . 'as refresh_grant_unknown — this row is what makes rotation legal',
        );

        // And the published envelope stayed byte-ident: the fixed-field DTO
        // echo carries no sid, so the wiring adds capability without
        // touching the wire. The raw JWT does name the family (proved
        // above); the response never reveals it.
        self::assertArrayNotHasKey('sid', $response['claims']);
    }
}
