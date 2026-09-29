<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Orchestrates the hub's user-account lifecycle: register, login, refresh,
 * logout, plus the race-free auto-promotion of the first registered user to
 * admin.
 *
 * Each lifecycle method:
 *
 *  - persists or validates state through {@see UserRepository},
 *  - mints / validates tokens through {@see JwtHandler},
 *  - records every action through {@see AuditLogger}, and
 *  - dispatches the matching `Phlix\Shared\Events\Auth\*` event on the
 *    optional PSR-14 dispatcher.
 *
 * @package Phlix\Hub\Auth
 */
class AuthManager
{
    /**
     * Ceiling on accepted password length. Argon2id memory-cost is per-call,
     * so an unbounded password on the anonymous register path is a cheap DoS
     * amplifier; the DB column is VARCHAR(255) for the HASH and any human-
     * memorable secret fits far below this. Rejected pre-hash, pre-limiter-
     * success, with a plain validation error.
     */
    private const int MAX_PASSWORD_LENGTH = 4096;

    /**
     * Primary key of the one-row first-admin election guard
     * (`auth_signup_guard`, migration 047).
     */
    private const string FIRST_ADMIN_GUARD_KEY = 'first-admin-election';

    /**
     * @param UserRepository                $userRepository
     * @param JwtHandler                    $jwtHandler
     * @param AuditLogger                   $auditLogger
     * @param StructuredLogger              $logger
     * @param RateLimiterInterface          $rateLimiter        Bounded, TTL-windowed login
     *                                                          attempt counter keyed by the
     *                                                          real client IP (finding B1 —
     *                                                          replaces the old unbounded
     *                                                          `static array` map).
     * @param EventDispatcherInterface|null $eventDispatcher    Optional PSR-14 dispatcher.
     * @param Connection|null               $db                 Optional DB handle so the
     *                                                          first-admin election can run
     *                                                          on its own transaction.
     * @param RateLimiterInterface|null     $signupRateLimiter  Optional global attempt cap
     *                                                          on account creation (the
     *                                                          `rate_limiter.signup` profile,
     *                                                          3 / 3600s per IP). Null keeps
     *                                                          register unthrottled — unit
     *                                                          tests only; production wiring
     *                                                          always injects it.
     * @param bool                          $signupsEnabled     When false, `register()`
     *                                                          throws {@see SignupsDisabledException}
     *                                                          before any crypto work.
     * @param RefreshTokenRevocationService|null $revocations   Optional jti revocation
     *                                                          registry consulted by
     *                                                          `refresh()` and written by
     *                                                          `logout()` (migration 047).
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JwtHandler $jwtHandler,
        private readonly AuditLogger $auditLogger,
        private readonly StructuredLogger $logger,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?Connection $db = null,
        private readonly ?RateLimiterInterface $signupRateLimiter = null,
        private readonly bool $signupsEnabled = true,
        private readonly ?RefreshTokenRevocationService $revocations = null,
    ) {
    }

    /**
     * Lazy dummy hash used to equalise the unknown-user login path with the
     * known-user path (timing-oracle hardening, see {@see burnPasswordTime()}).
     */
    private ?string $dummyPasswordHash = null;

    /**
     * Build the per-IP login rate-limit bucket key. Empty/unknown IPs collapse
     * to a single shared bucket rather than going unlimited.
     */
    private static function rateLimitKey(string $clientIp): string
    {
        $ip = $clientIp !== '' ? $clientIp : 'unknown';
        return 'auth:login:' . $ip;
    }

    /**
     * Build the per-IP signup rate-limit bucket key. Same collapse rule as
     * {@see rateLimitKey()}.
     */
    private static function signupRateLimitKey(string $clientIp): string
    {
        $ip = $clientIp !== '' ? $clientIp : 'unknown';
        return 'auth:signup:' . $ip;
    }

    /**
     * Reject the attempt if the client IP is already over the limit, WITHOUT
     * recording an attempt (the attempt itself is counted only on failure via
     * {@see recordFailedAttempt()}). Preserves the historical semantics: once
     * the threshold of failures within the window is reached, subsequent
     * attempts are blocked until the window resets.
     *
     * @throws RateLimitException When the rate limit is exceeded.
     */
    private function checkRateLimit(string $clientIp): void
    {
        $state = $this->rateLimiter->peek(self::rateLimitKey($clientIp));
        if ($state->limited) {
            throw new RateLimitException(
                resetAt: $state->resetAt,
                remaining: 0,
            );
        }
    }

    /**
     * Record a failed authentication attempt for the client IP.
     */
    private function recordFailedAttempt(string $clientIp): void
    {
        $this->rateLimiter->hit(self::rateLimitKey($clientIp));
    }

    /**
     * Clear rate-limit data for a client IP after successful auth.
     */
    private function clearRateLimit(string $clientIp): void
    {
        $this->rateLimiter->reset(self::rateLimitKey($clientIp));
    }

    /**
     * Count every signup attempt for the client IP and refuse when the bucket
     * is over budget. Unlike login (which counts only failures so a legitimate
     * user is never penalised), signup caps TOTAL attempts: a successfully
     * created account is exactly the event the limit exists to bound, and each
     * attempt costs an Argon2id hash on a path anyone can hit.
     *
     * @throws RateLimitException When the client IP is over the signup budget.
     */
    private function checkSignupRateLimit(string $clientIp): void
    {
        if ($this->signupRateLimiter === null) {
            return;
        }
        $state = $this->signupRateLimiter->hit(self::signupRateLimitKey($clientIp));
        if ($state->limited) {
            throw new RateLimitException(
                resetAt: $state->resetAt,
                remaining: $state->remaining,
            );
        }
    }

    /**
     * Register a fresh account.
     *
     * @param string $username Chosen username (3-50 chars).
     * @param string $email    Email; must pass {@see FILTER_VALIDATE_EMAIL}.
     * @param string $password Plain password (8-4096 chars). Hashed with Argon2ID.
     * @param string $clientIp Real client IP the signup limiter buckets on
     *                         (empty disables only the IP granularity, never
     *                         the cap — unknown IPs share one bucket).
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws SignupsDisabledException When self-service registration is off.
     * @throws RateLimitException       When the client IP exceeded the signup budget.
     * @throws InvalidArgumentException When validation fails or the email/username is already taken.
     */
    public function register(string $username, string $email, string $password, string $clientIp = ''): array
    {
        if (!$this->signupsEnabled) {
            throw new SignupsDisabledException('Registration is disabled on this hub');
        }
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new InvalidArgumentException('Username must be 3-50 characters');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password must be at most 4096 characters');
        }

        $this->checkSignupRateLimit($clientIp);

        if ($this->userRepository->usernameExists($username)) {
            throw new InvalidArgumentException('Username already taken');
        }
        if ($this->userRepository->emailExists($email)) {
            throw new InvalidArgumentException('Email already registered');
        }

        $db = $this->db;
        if ($db !== null) {
            $db->beginTrans();
        }

        try {
            $userId = $this->userRepository->create([
                'username'     => $username,
                'email'        => $email,
                'password'     => $password,
                'display_name' => $username,
            ]);

            $isFirstUser = $db !== null
                ? $this->electFirstAdminAtomically($userId)
                : $this->userRepository->countUsers() === 0;

            if ($isFirstUser) {
                $this->userRepository->setAdmin($userId, true);
                $this->logger->info('Promoted first user to admin', [
                    'user_id'  => $userId,
                    'username' => $username,
                ]);
            }

            if ($db !== null) {
                $db->commitTrans();
            }
        } catch (Throwable $e) {
            if ($db !== null) {
                try {
                    $db->rollBackTrans();
                } catch (Throwable $rollbackError) {
                    $this->logger->error('Failed to roll back failed registration', [
                        'username'       => $username,
                        'rollback_error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            $this->logger->error('User registration failed', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('User registered', ['user_id' => $userId, 'username' => $username]);
        $this->auditLogger->logSignup($userId, $username, $email);
        $this->dispatchUserCreated($userId, $username, $email);

        return $this->createAuthResponse($userId);
    }

    /**
     * Race-free first-admin election.
     *
     * The old code read `countUsers() === 0` OUTSIDE the transaction, so two
     * concurrent registrations could both see zero and both self-elect. The
     * election is now decided by a one-row INSERT on the `auth_signup_guard`
     * PRIMARY KEY: InnoDB's duplicate-key check serialises rival inserters
     * (each blocks until the guard holder commits or rolls back), and the
     * winner is then read back from the same connection — the row carries the
     * electee's id, so `guard.user_id === $userId` is the verdict. (The
     * affected-rows signal is unusable here: workerman's `query()` returns
     * `lastInsertId()` — `'0'` on this auto-increment-less table — for an
     * INSERT and `null` for an IGNORE-duplicate, so only the read-back
     * distinguishes win from lose.)
     *
     * Winning the row alone is not enough — on an upgraded install the users
     * table already has rows while the guard table is empty, and the next
     * newcomer must NOT inherit the first-admin crown. So the winner is
     * promoted only when it is also alone in `users` at election time
     * (`countUsers() <= 1`, counting its own just-created row). The residual
     * failure mode is fail-SAFE: two simultaneous bootstrap registrations can
     * leave the hub with zero admins (recoverable by operator), never with
     * two.
     *
     * Runs on the dedicated 'txn' connection inside the caller's transaction,
     * so a rolled-back registration releases the guard for a retry.
     */
    private function electFirstAdminAtomically(string $userId): bool
    {
        $db = $this->db;
        if ($db === null) {
            return false;
        }

        // The return value of the INSERT is deliberately unread: on this
        // auto-increment-less table it cannot distinguish 'I won' ('0' =
        // lastInsertId) from 'someone already holds the row' (null). The
        // race-free signal is that this call BLOCKS until any rival guard
        // holder commits or rolls back; the read-back on the same connection
        // then sees the settled truth.
        $db->query(
            'INSERT IGNORE INTO auth_signup_guard (guard_key, user_id) VALUES (:guard_key, :user_id)',
            ['guard_key' => self::FIRST_ADMIN_GUARD_KEY, 'user_id' => $userId],
        );

        /** @var mixed $rows */
        $rows = $db->query(
            'SELECT user_id FROM auth_signup_guard WHERE guard_key = :guard_key LIMIT 1',
            ['guard_key' => self::FIRST_ADMIN_GUARD_KEY],
        );

        $holder = '';
        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            /** @var mixed $holderRaw */
            $holderRaw = $rows[0]['user_id'] ?? null;
            $holder = is_string($holderRaw) ? $holderRaw : '';
        }
        $wonGuard = $holder === $userId && $holder !== '';

        return $wonGuard && $this->userRepository->countUsers() <= 1;
    }

    /**
     * Authenticate a user with credentials.
     *
     * @param string $usernameOrEmail Either the username or the email — looked up against both indexes.
     * @param string $password        Plain password.
     * @param string $clientIp        Real client IP (from `Request::$remoteIp`); the key the
     *                                login limiter buckets on. Distinct from `$deviceId`.
     * @param string $deviceId        Opaque device/session identifier (no formal session rows yet).
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When the credentials do not match.
     * @throws RateLimitException       When the client IP exceeded the login rate limit.
     */
    public function login(string $usernameOrEmail, string $password, string $clientIp, string $deviceId = ''): array
    {
        $this->checkRateLimit($clientIp);

        $user = $this->userRepository->findByUsername($usernameOrEmail);
        if ($user === null) {
            $user = $this->userRepository->findByEmail($usernameOrEmail);
        }

        if ($user === null) {
            // Equalise against the bad-password path: an unknown identifier
            // still pays one Argon2id verification, so response time cannot
            // enumerate accounts.
            $this->burnPasswordTime($password);
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logFailedAuth('unknown_user', [
                'identifier' => $usernameOrEmail,
                'device_id'  => $deviceId,
            ]);
            throw new InvalidArgumentException('Invalid username or password');
        }

        $userId = self::asString($user['id'] ?? null);
        if ($userId === '' || !$this->userRepository->verifyPassword($userId, $password)) {
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logLogin($userId, $deviceId, false, 'bad_password');
            throw new InvalidArgumentException('Invalid username or password');
        }

        $this->clearRateLimit($clientIp);
        $this->userRepository->updateLastLogin($userId);
        $this->auditLogger->logLogin($userId, $deviceId, true);
        $this->logger->info('User logged in', ['user_id' => $userId, 'device_id' => $deviceId]);
        $this->dispatchUserLoggedIn($userId, $deviceId);

        return $this->createAuthResponse($userId);
    }

    /**
     * Run one throwaway Argon2id verification against a never-reachable dummy
     * hash so the unknown-user branch costs the same as the wrong-password
     * branch. The dummy hash is minted lazily (once per instance) with fresh
     * randomness — nothing can ever match it, so the result is always false.
     */
    private function burnPasswordTime(string $password): void
    {
        $this->dummyPasswordHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
        password_verify($password, $this->dummyPasswordHash);
    }

    /**
     * Validate a refresh token and mint a fresh access + refresh pair.
     *
     * Three gates, cheapest first, all collapsing to the same indistinguishable
     * failure so the endpoint cannot be probed:
     *
     *  1. cryptography — signature/issuer/audience/expiry via {@see JwtHandler};
     *  2. revocation   — a `jti` cut by {@see logout()} is dead even while the
     *                    JWT itself still verifies (this is what makes logout
     *                    actually end the session, per the openapi promise);
     *  3. existence    — a deleted user must not keep minting fresh 7-day
     *                    rolling pairs from a pre-deletion token.
     *
     * @param string $refreshToken Encoded refresh JWT.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When the token is invalid, revoked, or the user is gone.
     */
    public function refresh(string $refreshToken): array
    {
        $claims = $this->jwtHandler->validateRefreshToken($refreshToken);
        if ($claims === null) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }

        if ($this->revocations !== null && $claims->jti !== null && $this->revocations->isRevoked($claims->jti)) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }

        if ($this->userRepository->findById($claims->sub) === null) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }

        return $this->createAuthResponse($claims->sub);
    }

    /**
     * Mark the user as logged out and, when the caller presents the refresh
     * JWT it is ending the session with, revoke it for real: its `jti` goes
     * into {@see RefreshTokenRevocationService} and every later `refresh()`
     * of that token is refused as though it had expired.
     *
     * A token that does not verify is ignored (logout stays the friendly 204
     * it has always been — nothing here may error on a best-effort cleanup).
     * When a token DOES verify, its signed `sub` wins over the `$userId`
     * argument for the audit/event identity: the JWT is the authority on
     * which lineage was cut.
     */
    public function logout(
        string $userId,
        string $sessionId,
        string $reason = UserLoggedOut::REASON_EXPLICIT,
        string $refreshToken = '',
    ): void {
        if ($refreshToken !== '' && $this->revocations !== null) {
            $claims = $this->jwtHandler->validateRefreshToken($refreshToken);
            if ($claims !== null && $claims->jti !== null) {
                $this->revocations->revoke($claims->jti, $claims->sub, $claims->exp);
                $userId = $claims->sub;
            }
        }

        $this->logger->info('User logged out', [
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'reason'     => $reason,
        ]);
        $this->auditLogger->logLogout($userId, $sessionId);
        $this->dispatchUserLoggedOut($userId, $sessionId, $reason);
    }

    /**
     * Fetch the current user record by id, with `password_hash` stripped.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentUser(string $userId): ?array
    {
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    /**
     * Build the standard JSON auth response for a user.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     */
    private function createAuthResponse(string $userId): array
    {
        $accessToken = $this->jwtHandler->createAccessToken($userId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId);
        $claims = $this->jwtHandler->validateAccessToken($accessToken);
        $user = $this->getCurrentUser($userId) ?? [];

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $this->jwtHandler->getAccessTtl(),
            'user'          => $user,
            'claims'        => $claims?->toPayload() ?? [],
        ];
    }

    /**
     * Dispatch the cross-repo {@see UserCreated} event when a dispatcher
     * is wired.
     */
    private function dispatchUserCreated(string $userId, string $username, string $email): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserCreated(
            userId: $userId,
            username: $username,
            email: $email,
        ));
    }

    /**
     * Dispatch the cross-repo {@see UserLoggedIn} event when a dispatcher
     * is wired.
     */
    private function dispatchUserLoggedIn(string $userId, string $sessionId): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserLoggedIn(
            userId: $userId,
            sessionId: $sessionId,
            ipAddress: '',
            userAgent: '',
        ));
    }

    /**
     * Dispatch the cross-repo {@see UserLoggedOut} event when a dispatcher
     * is wired.
     */
    private function dispatchUserLoggedOut(string $userId, string $sessionId, string $reason): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserLoggedOut(
            userId: $userId,
            sessionId: $sessionId,
            reason: $reason,
        ));
    }

    /**
     * Coerce a mixed value to a string for downstream use; returns "" for
     * non-string / non-scalar input.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }
}
