<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\integration\Auth;

use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Auth\JwtHandler;
use Phlex\Hub\Auth\UserRepository;
use Phlex\Hub\Common\Database\MigrationRunner;
use Phlex\Hub\Common\Logger\AuditLogger;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Shared\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * End-to-end signup → login → protected → logout flow against a real DB.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set, matching the
 * gating pattern from B.6's MigrationRunnerIntegrationTest.
 *
 * @package Phlex\Hub\Tests\integration\Auth
 * @since 0.2.0
 *
 * @covers \Phlex\Hub\Auth\AuthManager
 * @covers \Phlex\Hub\Auth\UserRepository
 * @covers \Phlex\Hub\Auth\JwtHandler
 *
 * @group integration
 */
final class SignupLoginFlowTest extends TestCase
{
    private const SECRET = 'integration-test-secret-32-bytes-minimum';

    private Connection $db;
    private AuthManager $auth;
    private JwtHandler $jwt;
    private UserRepository $users;

    protected function setUp(): void
    {
        $host = getenv('HUB_TEST_DB_HOST');
        $name = getenv('HUB_TEST_DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            self::markTestSkipped(
                'HUB_TEST_DB_* environment variables not set — skipping integration suite.',
            );
        }

        $port = (int) (getenv('HUB_TEST_DB_PORT') ?: '3306');
        $user = (string) (getenv('HUB_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('HUB_TEST_DB_PASSWORD') ?: '');

        $this->db = new Connection($host, $port, $user, $pass, $name);
        $this->skipOnIncompatibleCluster();
        $this->dropAllTables();

        // Apply migrations so the users table exists.
        $runner = new MigrationRunner($this->db, dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $loggerConfig = ['handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']], 'processors' => []];
        $logger = new StructuredLogger('test', $loggerConfig);
        $auditLogger = new StructuredLogger('test-audit', $loggerConfig);

        $this->jwt = new JwtHandler(self::SECRET);
        $this->users = new UserRepository($this->db);
        $this->auth = new AuthManager(
            $this->users,
            $this->jwt,
            new AuditLogger($auditLogger),
            $logger,
            null,
            $this->db,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAllTables();
        }
    }

    public function testEndToEndSignupThenLoginThenProtectedRoute(): void
    {
        // 1. Signup.
        $signupResult = $this->auth->register('alice', 'a@example.com', 'correct-horse-battery');
        self::assertIsString($signupResult['access_token']);
        self::assertIsString($signupResult['refresh_token']);
        self::assertSame('Bearer', $signupResult['token_type']);

        // 2. First user becomes admin.
        $rows = $this->db->query('SELECT is_admin FROM users WHERE username = :u', ['u' => 'alice']);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertIsArray($row);
        self::assertSame(1, (int) ($row['is_admin'] ?? 0));

        // 3. Login with the same creds returns valid tokens.
        $loginResult = $this->auth->login('alice', 'correct-horse-battery', '1.2.3.4');
        $token = (string) $loginResult['access_token'];
        $claims = $this->jwt->validateAccessToken($token);
        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame('phlex-hub', $claims->iss);
        self::assertSame('hub', $claims->aud);

        // 4. Login via email also works.
        $loginByEmail = $this->auth->login('a@example.com', 'correct-horse-battery', '1.2.3.4');
        self::assertIsString($loginByEmail['access_token']);

        // 5. Bad password rejected.
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->login('alice', 'wrong-password', '1.2.3.4');
    }

    public function testRefreshTokenRoundTrip(): void
    {
        $result = $this->auth->register('bob', 'b@example.com', 'correct-horse-battery');
        $refreshed = $this->auth->refresh((string) $result['refresh_token']);
        self::assertIsString($refreshed['access_token']);

        $claims = $this->jwt->validateAccessToken((string) $refreshed['access_token']);
        self::assertNotNull($claims);
        self::assertSame($result['user']['id'] ?? '', $claims->sub);
    }

    public function testSecondRegistrationIsNotAdmin(): void
    {
        $this->auth->register('alice', 'a@example.com', 'correct-horse-battery');
        $this->auth->register('bob', 'b@example.com', 'correct-horse-battery');

        $rows = $this->db->query('SELECT is_admin FROM users WHERE username = :u', ['u' => 'bob']);
        self::assertIsArray($rows);
        $row = $rows[0];
        self::assertIsArray($row);
        self::assertSame(0, (int) ($row['is_admin'] ?? 1));
    }

    public function testLogoutCompletesWithoutThrowing(): void
    {
        $result = $this->auth->register('carol', 'c@example.com', 'correct-horse-battery');
        $userId = (string) ($result['user']['id'] ?? '');
        $this->auth->logout($userId, 'session-1');
        // Just assert no exception escaped.
        self::assertTrue(true);
    }

    public function testDuplicateEmailRejected(): void
    {
        $this->auth->register('alice', 'a@example.com', 'correct-horse-battery');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already registered');
        $this->auth->register('alice2', 'a@example.com', 'correct-horse-battery');
    }

    public function testDuplicateUsernameRejected(): void
    {
        $this->auth->register('alice', 'a@example.com', 'correct-horse-battery');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already taken');
        $this->auth->register('alice', 'b@example.com', 'correct-horse-battery');
    }

    private function skipOnIncompatibleCluster(): void
    {
        try {
            $rows = $this->db->query(
                "SHOW VARIABLES LIKE 'group_replication_enforce_update_everywhere_checks'",
            );
        } catch (\Throwable) {
            return;
        }
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $row = $rows[0];
        $rawValue = is_array($row) && isset($row['Value']) ? $row['Value'] : '';
        $value = is_string($rawValue) ? $rawValue : '';
        if (strtoupper($value) === 'ON') {
            self::markTestSkipped(
                'Test DB runs Group Replication multi-primary; B.7 integration suite needs single-primary.',
            );
        }
    }

    private function dropAllTables(): void
    {
        $name = (string) getenv('HUB_TEST_DB_NAME');
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema",
            ['schema' => $name],
        );
        if (!is_array($rows)) {
            return;
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['TABLE_NAME']) || !is_string($row['TABLE_NAME'])) {
                continue;
            }
            $table = $row['TABLE_NAME'];
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
