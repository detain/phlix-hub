<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Auth;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Phlix\Shared\Auth\JwtClaims;

/**
 * End-to-end signup → login → protected → logout flow against a real DB.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set, matching the
 * gating pattern from the MigrationRunnerIntegrationTest.
 *
 * S185: the connect / skip-gate / schema / data-reset boilerplate moved to
 * {@see RealDatabaseTestCase}, which builds the schema once per process and
 * empties every table before and after each test instead of re-applying all 29
 * migrations six times over. The isolation contract is unchanged — see that
 * class for how the cached schema is re-validated on every `setUp()`.
 *
 * @package Phlix\Hub\Tests\Integration\Auth
 *
 * @covers \Phlix\Hub\Auth\AuthManager
 * @covers \Phlix\Hub\Auth\UserRepository
 * @covers \Phlix\Hub\Auth\JwtHandler
 *
 * @group integration
 */
final class SignupLoginFlowTest extends RealDatabaseTestCase
{
    private const SECRET = 'integration-test-secret-32-bytes-minimum';

    private AuthManager $auth;
    private JwtHandler $jwt;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $loggerConfig = [
            'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => []
        ];
        $logger = new StructuredLogger('test', $loggerConfig);
        $auditLogger = new StructuredLogger('test-audit', $loggerConfig);

        $this->jwt = new JwtHandler(self::SECRET);
        $this->users = new UserRepository($this->db);
        $this->auth = new AuthManager(
            $this->users,
            $this->jwt,
            new AuditLogger($auditLogger),
            $logger,
            new RateLimiter(windowSeconds: 900, maxAttempts: 5, cap: 1000),
            null,
            $this->db,
        );
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
        self::assertSame('phlix-hub', $claims->iss);
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
}
