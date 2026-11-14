<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Container;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Tests\Support\ConnectionPoolTestControl;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Throwable;
use Workerman\MySQL\Connection;
use PHPUnit\Framework\Attributes\Group;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_numeric;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * SH-051 (review hub__dba-code) — the registration transaction must wrap the
 * WHOLE of register()'s write set, not just the election guard.
 *
 * ## The defect this exists to make impossible again
 *
 * `AuthManager::register()` opens its transaction on the dedicated 'txn'
 * handle, but until this lane the container built the AuthManager's
 * {@see UserRepository} from the pool-`mysql` binding — so the user INSERT
 * auto-committed OUTSIDE the very transaction its caller believed was
 * protecting it. When anything after the INSERT threw (admin promotion, the
 * election itself, a lost connection), the catch rolled the 'txn' handle back
 * and the half-written registration — a non-admin orphan user whose signup
 * visibly FAILED — stayed committed. The docblock on
 * `electFirstAdminAtomically()` ("Runs on the dedicated txn connection inside
 * the caller's transaction, so a rolled-back registration releases the guard
 * for a retry") promised exactly what the wiring did not deliver.
 *
 * The cure moved the repository onto the same `$txn` handle inside
 * AuthServicesProvider's factory closure. Nothing that hand-constructs
 * AuthManager can see that — the pairing lives ONLY in the provider — so this
 * file resolves the PRODUCTION container stack (the S269 precedent:
 * {@see FixedConnectionProvider} + {@see ConnectionPoolTestControl} for the
 * static `getConnection('txn')` the factory performs).
 *
 * ## The three legs
 *
 *  1. handle identity: `AuthManager::$db` IS `UserRepository::$db` (the
 *     structural law — red pre-cure, where the two were distinct objects);
 *  2. all-or-nothing: a forced failure AT the admin promotion leaves the
 *     `users` and `auth_signup_guard` tables EMPTY (red pre-cure: the orphan
 *     row survived every rollback, because it was never in the transaction);
 *  3. retry-ability: after that rollback the SAME username registers cleanly
 *     and the guard genuinely holds the new user — the released-for-retry
 *     promise, verified rather than asserted in prose.
 *
 * @package Phlix\Hub\Tests\Integration\Container
 *
 */
#[Group('integration')]
final class RegistrationTransactionWiringTest extends RealDatabaseTestCase
{
    use ConnectionPoolTestControl;

    /** A >=32-byte HS256 secret so the real JwtHandler accepts it. */
    private const JWT_SECRET = 'SH-051-registration-transaction-wiring-secret';

    private const TRIGGER = 'w16_fail_first_admin';

    private string $tmpDir = '';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pointConnectionPoolAtTestDatabase();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-sh051-' . bin2hex(random_bytes(6));
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0700, true);
        }

        // Same posture as the S269 binding test: the auth channel must go
        // nowhere visible (beStrictAboutOutputDuringTests), and the JWT
        // secret must be configured via the real config path because
        // AuthServicesProvider::resolveSecret() throws when absent in non-dev.
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
        if (!isset($this->db)) {
            // setUp() skipped before provisioning (no test database configured).
            parent::tearDown();

            return;
        }

        $this->dropFailureTrigger();
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testTheTransactionHandleAndTheRepositoryHandleAreTheSameObject(): void
    {
        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);

        /** @var mixed $txHandle */
        $txHandle = (new ReflectionProperty(AuthManager::class, 'db'))->getValue($manager);
        /** @var mixed $repo */
        $repo = (new ReflectionProperty(AuthManager::class, 'userRepository'))->getValue($manager);
        self::assertInstanceOf(UserRepository::class, $repo);
        /** @var mixed $repoHandle */
        $repoHandle = (new ReflectionProperty(UserRepository::class, 'db'))->getValue($repo);

        self::assertInstanceOf(Connection::class, $txHandle);
        self::assertInstanceOf(Connection::class, $repoHandle);
        self::assertSame(
            $txHandle,
            $repoHandle,
            'SH-051: register() wraps $this->db in a transaction; the repository the '
            . 'transaction protects must live on that SAME handle — a pool-mysql repo '
            . 'auto-commits outside the BEGIN and rollback cannot un-write it',
        );
    }

    public function testAFailedFirstAdminPromotionRollsBackTheWholeRegistration(): void
    {
        $this->armFailureTriggerAtAdminPromotion();

        $manager = $this->container->get(AuthManager::class);
        self::assertInstanceOf(AuthManager::class, $manager);

        try {
            $manager->register('w16victim', 'w16victim@example.test', 'Str0ng-Passw0rd!');
            self::fail('the trigger must make the admin promotion fail');
        } catch (Throwable) {
            // Expected: the promotion error must propagate (register()
            // rethrows after rollback), not be swallowed into a lie of success.
        }

        self::assertSame(
            0,
            $this->countRows('users'),
            'SH-051: the user INSERT is inside the transaction; a rolled-back signup '
            . 'must leave no orphan row (pre-cure, the repo rode the mysql pool and the '
            . 'committed INSERT survived) ',
        );
        self::assertSame(
            0,
            $this->countRows('auth_signup_guard'),
            'the guard row must roll back too, so the retry below can re-elect',
        );

        $this->dropFailureTrigger();

        // Leg 3: the released-for-retry promise. Same username, same email,
        // same manager — now clean through to commit.
        $response = $manager->register('w16victim', 'w16victim@example.test', 'Str0ng-Passw0rd!');
        self::assertArrayHasKey(
            'access_token',
            $response,
            'the retry must return the real auth response, not a stub',
        );

        self::assertSame(1, $this->countRows('users'));
        self::assertSame(1, $this->countRows('auth_signup_guard'));
        /** @var list<array{is_admin: int}> $rows */
        $rows = $this->db->query('SELECT is_admin FROM users WHERE username = :u', ['u' => 'w16victim']);
        self::assertSame([['is_admin' => 1]], $rows, 'the retry must still land the first-admin election');
    }

    private function armFailureTriggerAtAdminPromotion(): void
    {
        $this->dropFailureTrigger();
        /** @var mixed $created */
        $created = $this->db->query(
            'CREATE TRIGGER ' . self::TRIGGER . ' AFTER UPDATE ON users FOR EACH ROW '
            . 'BEGIN IF NEW.is_admin = 1 AND OLD.is_admin = 0 THEN '
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sh051 forced registration failure'; "
            . 'END IF; END',
        );
        self::assertNotFalse($created, 'the failure trigger must be creatable in the test schema');
    }

    private function dropFailureTrigger(): void
    {
        /** @var mixed $dropped */
        $dropped = $this->db->query('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        self::assertNotFalse($dropped);
    }

    private function countRows(string $table): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM ' . $table);
        /** @var list<array{c: mixed}> $rows */
        $raw = $rows[0]['c'] ?? 0;

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
