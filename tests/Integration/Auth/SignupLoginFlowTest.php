<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Auth;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\RefreshTokenRevocationService;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
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
 * @group integration
 */
final class SignupLoginFlowTest extends RealDatabaseTestCase
{
    use DecodedJsonAssertions;

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
        $userId = self::stringNode(self::arrayNode($result['user'] ?? null)['id'] ?? '');
        $this->auth->logout($userId, 'session-1');
        // Passing means no exception escaped: there is no observable state to assert.
        self::addToAssertionCount(1);
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

    /**
     * Fix 2 (first-admin TOCTOU) at the SQL layer: the election is decided by
     * the `auth_signup_guard` PRIMARY KEY, so a registrant that finds the
     * users table empty-but-for-itself must STILL be denied the crown when a
     * previous generation already claimed the guard row. Pre-seeding the
     * guard reproduces the "upgraded install / re-bootstrap" race without
     * needing two live processes: whoever holds the row elects, everybody
     * else falls through to plain registration.
     */
    public function testFirstAdminElectionIsGuardedByTheSignupSentinel(): void
    {
        $this->db->query(
            'INSERT INTO auth_signup_guard (guard_key, user_id) VALUES (:guard_key, :user_id)',
            ['guard_key' => 'first-admin-election', 'user_id' => 'u-ancestor'],
        );

        $result = $this->auth->register('latecomer', 'late@example.com', 'correct-horse-battery');
        $userId = self::stringNode($result['user']['id']);

        $rows = $this->db->query(
            'SELECT is_admin FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertIsArray($rows);
        self::assertSame(
            0,
            (int) $rows[0]['is_admin'],
            'the guard holder is the election — a newcomer must not self-elect',
        );

        // The guard still names the pre-seeded ancestor, untouched by the loser's transaction.
        $guard = $this->db->query(
            'SELECT user_id FROM auth_signup_guard WHERE guard_key = :k',
            ['k' => 'first-admin-election'],
        );
        self::assertIsArray($guard);
        self::assertSame('u-ancestor', (string) $guard[0]['user_id']);
    }

    /**
     * Fix 4 (refresh re-checks user existence): deleting the account must end
     * its rolling token pair at the next refresh instead of minting a fresh
     * 7-day pair from a ghost.
     */
    public function testRefreshRejectsDeletedUser(): void
    {
        $result = $this->auth->register('ghost', 'g@example.com', 'correct-horse-battery');
        $userId = self::stringNode($result['user']['id']);
        $refresh = (string) $result['refresh_token'];

        // Sanity: the pair rotates fine while the user exists.
        $alive = $this->auth->refresh($refresh);
        self::assertIsString($alive['access_token']);

        $this->users->delete($userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');
        $this->auth->refresh((string) $alive['refresh_token']);
    }

    /**
     * Fix 5 (logout actually invalidates the refresh token): the presented
     * token's jti lands in the registry, the very next refresh of it is
     * refused as though it had expired, and the logout-before-rotation path
     * keeps working — revocation ends the presented lineage and nothing else.
     */
    public function testLogoutInvalidatesThePresentedRefreshToken(): void
    {
        $auth = $this->wiredAuthManagerWithRevocationRegistry();

        $result = $auth->register('carol', 'c@example.com', 'correct-horse-battery');
        $refresh = (string) $result['refresh_token'];

        // Logout WITHOUT a token remains the friendly no-op it always was.
        $auth->logout(self::stringNode($result['user']['id']), 'session-pre', UserLoggedOut::REASON_EXPLICIT, '');
        $rotated = $auth->refresh($refresh);
        self::assertIsString($rotated['access_token']);

        // Logout WITH the live refresh token cuts that lineage.
        $auth->logout('', 'session-final', UserLoggedOut::REASON_EXPLICIT, (string) $rotated['refresh_token']);

        $rows = $this->db->query('SELECT jti, user_id FROM auth_revoked_refresh_tokens');
        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'logout must persist exactly the presented token jti');

        $claims = $this->jwt->validateRefreshToken((string) $rotated['refresh_token']);
        self::assertNotNull($claims);
        self::assertSame($claims->jti, self::stringNode(self::arrayNode($rows[0])['jti']));
        self::assertSame(
            self::stringNode($result['user']['id']),
            self::stringNode(self::arrayNode($rows[0])['user_id']),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');
        $auth->refresh((string) $rotated['refresh_token']);
    }

    /**
     * Same wiring as setUp(), plus the optional collaborators production
     * injects but the base flow test never needed: the jti revocation
     * registry (fix 5).
     */
    private function wiredAuthManagerWithRevocationRegistry(): AuthManager
    {
        $loggerConfig = [
            'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => [],
        ];
        $logger = new StructuredLogger('test', $loggerConfig);
        $auditLogger = new StructuredLogger('test-audit', $loggerConfig);

        return new AuthManager(
            $this->users,
            $this->jwt,
            new AuditLogger($auditLogger),
            $logger,
            new RateLimiter(windowSeconds: 900, maxAttempts: 5, cap: 1000),
            null,
            $this->db,
            null,
            true,
            new RefreshTokenRevocationService($this->db),
        );
    }
}
