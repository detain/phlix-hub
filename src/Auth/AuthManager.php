<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
     * Width of the refresh-replay grace window (seconds) — the server's CP-005
     * design constant mirrored hub-side (CP-029 / D-1a). One number rules the
     * store read ({@see TokenSessionRepository::findGrant()}) AND the mint
     * stamp: a consumed grant replayed within this window is the benign
     * multi-tab race and rotates on; outside it, it is reuse and the family
     * dies. The hub has no in-process rotation memo (deviation from the
     * server, recorded in the twin register), so this is store-scope only.
     */
    private const int ROTATION_GRACE_SECONDS = 10;

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
     * @param (callable(): ?bool)|null          $signupsDisabledResolver Optional LIVE
     *                                                          gate consulted BEFORE the
     *                                                          boot-time `$signupsEnabled`
     *                                                          flag: returns `?bool` —
     *                                                          true = registrations closed,
     *                                                          null = "no live answer, use
     *                                                          the flag". Wired by
     *                                                          AuthServicesProvider to read
     *                                                          the `auth.signups_disabled`
     *                                                          hub setting so an admin
     *                                                          toggle applies to the very
     *                                                          next request without a
     *                                                          restart; any throw from it
     *                                                          degrades to the flag
     *                                                          (fail-safe: a settings-read
     *                                                          outage must not change what
     *                                                          the env already dictates).
     * @param TokenSessionRepository|null $tokenSessions     Optional CP-029 / D-1a
     *                                                          session-family store. When
     *                                                          wired, every mint stamps an
     *                                                          `sid` claim, registers the
     *                                                          minted refresh `jti` as a
     *                                                          grant, and every family-
     *                                                          carrying refresh is
     *                                                          adjudicated (reuse → family
     *                                                          death). Null keeps the
     *                                                          pre-055 wire byte-ident.
     *                                                          Like `$revocations` above,
     *                                                          this MUST be passed by the
     *                                                          explicit AuthServicesProvider
     *                                                          factory — a silently-unwired
     *                                                          store would disable theft
     *                                                          detection while every other
     *                                                          signal stayed green; pinned
     *                                                          by AuthTokenSessionWiringGuardTest.
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
        ?callable $signupsDisabledResolver = null,
        private readonly ?TokenSessionRepository $tokenSessions = null,
    ) {
        // `callable` is not a legal property type in PHP — the seam is stored
        // as a native \Closure, coerced at this boundary (review hub Major 34
        // tail), mirroring JwtHandler::$ttlResolver.
        $this->signupsDisabledResolver = $signupsDisabledResolver === null
            ? null
            : \Closure::fromCallable($signupsDisabledResolver);
    }

    /**
     * Live signup-gate resolver — see the constructor documentation. Null
     * means "no live store wired"; the boot-time `$signupsEnabled` flag decides.
     *
     * Stored as a native \Closure (review hub Major 34 tail): the constructor
     * accepts any callable and coerces at the boundary.
     *
     * @var \Closure(): ?bool|null
     */
    private ?\Closure $signupsDisabledResolver;

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
     * Whether self-service registration is closed RIGHT NOW.
     *
     * Precedence (auth spec-debt fix, 2026-09-29): the live
     * `auth.signups_disabled` hub setting answers first when a resolver is
     * wired and reachable; otherwise the boot-time `HUB_SIGNUPS_ENABLED`
     * flag decides. Concretely:
     *
     *  1. resolver returns `bool` → that is the effective gate (the resolver
     *     itself composes override-row → config-default, so "no settings row"
     *     already falls through to the env-derived default and the historical
     *     precedence is preserved without a second lookup here);
     *  2. resolver returns `null` (no DB pool — unit tests, CLI) → flag;
     *  3. resolver THROWS (DB down, bad row) → flag, fail-safe: a settings
     *     outage must never brick registration beyond what the env dictates.
     *     Mirrors the JwtHandler TTL-resolver doctrine — "token minting must
     *     never fail because a settings lookup did", applied to signup.
     *
     * Evaluated before ANY hashing, limiter, or repository work.
     */
    private function registrationsAreClosed(): bool
    {
        if ($this->signupsDisabledResolver === null) {
            return !$this->signupsEnabled;
        }

        try {
            $live = ($this->signupsDisabledResolver)();
        } catch (Throwable) {
            return !$this->signupsEnabled;
        }

        return $live === null ? !$this->signupsEnabled : $live;
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
        if ($this->registrationsAreClosed()) {
            throw new SignupsDisabledException('Registration is disabled on this hub');
        }
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new InvalidArgumentException('Username must be 3-50 characters');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
        self::assertPasswordPolicy($password);

        $this->checkSignupRateLimit($clientIp);

        if ($this->userRepository->usernameExists($username)) {
            throw new InvalidArgumentException('Username already taken');
        }
        if ($this->userRepository->emailExists($email)) {
            throw new InvalidArgumentException('Email already registered');
        }

        // Minor 45: the CPU-bound Argon2id digest is computed BEFORE the
        // transaction opens — hashing inside BEGIN..COMMIT stretched the
        // signup-election gap locks across pure CPU work. Pure function of
        // $password; the insert below now rides the precomputed arm.
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $db = $this->db;
        if ($db !== null) {
            $db->beginTrans();
        }

        try {
            $userId = $this->userRepository->create([
                'username'      => $username,
                'email'         => $email,
                'password_hash' => $passwordHash,
                'display_name'  => $username,
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
     * The single password-strength policy for every secret this hub persists:
     * 8..{@see MAX_PASSWORD_LENGTH} characters. Shared verbatim between
     * {@see register()} and {@see changePassword()} so the two paths can
     * never drift apart and an account's own password ceiling differs from
     * the ceiling it signed up under.
     *
     * Messages are the exact historical register() strings — byte-stable
     * wire text for the 400 `validation_failed` frame.
     *
     * @throws InvalidArgumentException When the secret is too short or too long.
     */
    private static function assertPasswordPolicy(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password must be at most 4096 characters');
        }
    }

    /**
     * Change an authenticated user's OWN password.
     *
     * The self-service counterpart of the admin-only
     * `POST /api/v1/admin/users/{id}/reset-password`: the caller proves
     * knowledge of the current secret BEFORE any write happens, so a stolen
     * access token alone cannot lock the owner out of their own account
     * (the proof-first order is the whole point — checking the new
     * password's policy first would let an attacker who only holds a token
     * learn policy details and, worse, rewrite the secret of any account
     * whose token they captured).
     *
     * Gates, in order, mirroring {@see login()}'s limiter posture:
     *
     *  1. the shared login budget for this client IP is consulted first
     *     (`peek`, no consumption) and every failed current-password proof
     *     consumes one unit — a brute-force of THIS endpoint drains the
     *     attacker's own login budget, exactly like repeated bad logins;
     *     a successful change never clears the bucket (only a successful
     *     LOGIN does — the budget is a login defence, not a courtesy);
     *  2. current-password proof via {@see UserRepository::verifyPassword()}
     *     — an unknown user id fails the same way (false), so the endpoint
     *     is no account oracle;
     *  3. {@see assertPasswordPolicy()} on the NEW secret (same 8..4096
     *     ceiling and the same messages as registration).
     *
     * The write goes through {@see UserRepository::update()} — the one
     * hashing path (Argon2ID minted inside the repository), so this method
     * never touches a hash itself.
     *
     * Scope note (deliberate, matches the admin reset today): existing
     * sessions are NOT revoked — access tokens ride out their TTL and
     * refresh jti revocation has no per-user bulk API in
     * {@see RefreshTokenRevocationService}. Rotating the secret stops
     * FUTURE logins, which is what the self-service contract promises.
     *
     * Audit: the failure rides `logFailedAuth` with reason
     * 'password_change_current_invalid' (auth-failure family, like logins);
     * the success rides {@see AuditLogger::logAdminAction()} with action
     * 'user.password_changed' — the generic audited-mutation recorder the
     * sibling 'user.reset_password' admin path uses; `self_service` context
     * distinguishes owner-initiated changes from admin-imposed ones. (No
     * PSR-14 event: the shared package's Auth event set is
     * Created/LoggedIn/LoggedOut only; adding one is a shared-repo seam
     * this hub-local cure must not fork.)
     *
     * @param string $userId          Authenticated user id (from the JWT claim).
     * @param string $currentPassword Plain current secret, presented as proof.
     * @param string $newPassword     Plain replacement secret (8..4096 chars).
     * @param string $clientIp        Real client IP the login limiter buckets on.
     *
     * @throws RateLimitException            When the client IP exceeded the login budget.
     * @throws CurrentPasswordInvalidException When the proof does not verify.
     * @throws InvalidArgumentException      When the new secret violates the policy.
     */
    public function changePassword(
        string $userId,
        string $currentPassword,
        string $newPassword,
        string $clientIp = '',
    ): void {
        $this->checkRateLimit($clientIp);

        if ($userId === '' || !$this->userRepository->verifyPassword($userId, $currentPassword)) {
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logFailedAuth('password_change_current_invalid', [
                'user_id' => $userId,
            ]);
            throw new CurrentPasswordInvalidException('Current password is incorrect');
        }

        self::assertPasswordPolicy($newPassword);

        $this->userRepository->update($userId, ['password' => $newPassword]);

        $this->auditLogger->logAdminAction($userId, 'user.password_changed', $userId, [
            'self_service' => true,
        ]);
        $this->logger->info('User password changed', ['user_id' => $userId]);
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
     *  4. family       — CP-029 / D-1a: a token carrying a `sid` claim must
     *                    present a KNOWN, UNCONSUMED grant of an ACTIVE
     *                    family (or a consumed one inside the grace window).
     *                    Reuse outside grace kills exactly that family. Runs
     *                    AFTER the three historical guards (a dead signature
     *                    is cheaper to refuse than an adjudicated one) and
     *                    BEFORE the mint (consume-before-mint, the server law
     *                    verbatim: a mint that dies mid-way leaves the retry
     *                    inside the grace window — it rotates on, not steals).
     *                    Every family refusal lands on the SAME collapsed
     *                    text as the other gates — the no-probe law the hub
     *                    already ships outranks the server's richer literal;
     *                    the audit reasons carry the distinction.
     *
     * @param string $refreshToken Encoded refresh JWT.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When the token is invalid, revoked, or the user is gone.
     */
    public function refresh(string $refreshToken): array
    {
        $lineage = $this->jwtHandler->validateRefreshTokenWithSession($refreshToken);
        if ($lineage === null) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }
        $claims = $lineage['claims'];

        if ($this->revocations !== null && $claims->jti !== null && $this->revocations->isRevoked($claims->jti)) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }

        if ($this->userRepository->findById($claims->sub) === null) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }

        $sessionId = $lineage['session_id'];
        if ($sessionId !== null) {
            $this->adjudicateRefreshFamily($claims->sub, $sessionId, $claims->jti);
        }

        return $this->createAuthResponse($claims->sub, $sessionId);
    }

    /**
     * The CP-029 family decision table for a presented refresh token, in the
     * server twin's exact arm order. Refusals audit a precise reason and
     * throw the hub's historical collapsed text; they NEVER leak which arm
     * fired onto the wire.
     *
     * @param string      $userId    Signed `sub` of the presented token.
     * @param string      $sessionId The `sid` claim (non-null by caller gate).
     * @param string|null $jti       The presented token's `jti`.
     *
     * @throws InvalidArgumentException On every family refusal.
     */
    private function adjudicateRefreshFamily(string $userId, string $sessionId, ?string $jti): void
    {
        $store = $this->tokenSessions;

        // (a) A family-carrying token against an unwired store cannot be
        // adjudicated at all — fail closed (the store was wired when this
        // token was minted; an operator removed it underneath the fleet).
        if ($store === null) {
            $this->refuseFamily($userId, $sessionId, $jti, 'token_store_unwired');
        }

        // (b) A family token without a jti cannot name a grant — dead.
        if ($jti === null || $jti === '') {
            $this->refuseFamily($userId, $sessionId, $jti, 'refresh_grant_jti_missing');
        }

        // (c) Unknown grant (never minted here): fail closed.
        $grant = $store->findGrant($jti, self::ROTATION_GRACE_SECONDS);
        if ($grant === null) {
            $this->refuseFamily($userId, $sessionId, $jti, 'refresh_grant_unknown');
        }

        // (d) The family is already revoked: refuse WITHOUT re-revoking — a
        // replay must not rewrite the reason or the timestamp of the kill
        // that already happened.
        if ($grant['sessionStatus'] !== 'active') {
            $this->refuseFamily($userId, $sessionId, $jti, 'token_session_revoked');
        }

        // (e) A grant owned by a DIFFERENT family than the claim it rides is
        // forgery or corruption; nothing rotates on it.
        if ($grant['sessionId'] !== $sessionId) {
            $this->refuseFamily($userId, $sessionId, $jti, 'refresh_grant_session_mismatch');
        }

        if ($grant['consumed']) {
            // (g) Consumed INSIDE grace: the benign multi-tab race. Rotate on
            // — revoke nothing, consume nothing (this grant is already spent;
            // the new mint registers its own row downstream).
            if ($grant['withinGrace']) {
                return;
            }

            // (f) Consumed OUTSIDE grace: reuse. The family dies — exactly
            // this device's chain, the watermark-free carrier layer
            // untouched, every other family of the account intact.
            $store->revokeSession($sessionId, 'refresh_token_reuse');
            $this->refuseFamily($userId, $sessionId, $jti, 'refresh_token_reuse');
        }

        // (h) Live grant: consume BEFORE the caller mints (see the refresh()
        // docblock — the ordering is the whole anti-theft timing argument).
        $store->markGrantConsumed($jti);
    }

    /**
     * One refusal exit for every family arm: audit first (the reason is the
     * only place the distinction lives), then the collapsed wire text.
     */
    private function refuseFamily(string $userId, string $sessionId, ?string $jti, string $reason): never
    {
        $context = [
            'user_id'    => $userId,
            'context'    => 'refresh',
            'session_id' => $sessionId,
        ];
        if ($jti !== null && $jti !== '') {
            $context['jti'] = $jti;
        }
        $this->auditLogger->logFailedAuth($reason, $context);

        throw new InvalidArgumentException('Invalid or expired refresh token');
    }

    /**
     * The caller's still-alive device families for GET /api/v1/auth/sessions.
     * Degenerates to the empty list when the store is unwired — the endpoint
     * never errors on a missing capability (a hub booted without the pool
     * has no families to list, which is the truth).
     *
     * @return list<array{id: string, created_at: string, last_used_at: string,
     *     device_type: string|null, device_name: string|null}>
     */
    public function listTokenSessions(string $userId): array
    {
        if ($this->tokenSessions === null || $userId === '') {
            return [];
        }

        return $this->tokenSessions->listActiveSessions($userId);
    }

    /**
     * End exactly ONE of the user's device families (per-device sign-out).
     *
     * Ownership is checked before anything is written, and a foreign id is
     * refused through the SAME false as a missing one — the caller renders
     * both as one 404 and the endpoint leaks nothing about who owns what.
     * The refresh `jti` registry is NOT consulted: signing one device out of
     * its family is exactly what the family row is for; the carrier layer
     * keeps its own, orthogonal job (COOKIE-DESIGN law).
     *
     * @return bool True when a family of THIS user was revoked (or was
     *              already revoked and the caller owns it — idempotent
     *              success); false for missing/foreign/blank inputs.
     */
    public function revokeTokenSession(string $userId, string $sessionId): bool
    {
        if ($this->tokenSessions === null || $userId === '' || $sessionId === '') {
            return false;
        }

        if (!$this->tokenSessions->sessionBelongsToUser($sessionId, $userId)) {
            return false;
        }

        $this->tokenSessions->revokeSession($sessionId, 'logout');
        $this->auditLogger->logLogout($userId, $sessionId);
        $this->logger->info('Token session revoked', [
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'reason'     => 'logout',
        ]);

        return true;
    }

    /**
     * Effective access-token TTL in seconds (live `auth.access_ttl` override
     * → config default). The cookie dual-emit's fallback lifetime: the access
     * cookie's Max-Age must never drift from the `exp` of the JWT it stores,
     * so the value comes from the handler that minted it, never a literal
     * (mirrors the server's AuthManager::accessTtl()).
     */
    public function accessTtl(): int
    {
        return $this->jwtHandler->getAccessTtl();
    }

    /**
     * Effective refresh-token TTL in seconds (live `auth.refresh_ttl`
     * override → config default). Used by the refresh cookie's Max-Age so the
     * two lifetimes can never disagree when an admin edits the setting.
     */
    public function refreshTtl(): int
    {
        return $this->jwtHandler->getRefreshTtl();
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
     * Build the standard JSON auth response for a user, stamping the CP-029
     * session family when the store is wired.
     *
     * Family law (server twin `buildAuthResponse`, mapped for the hub):
     *  - a null hint mints a NEW family (login/register ceremonies — a profile
     *    switch has no hub analog, see the register's deviation table);
     *  - a non-null hint CONTINUES that family (rotation) and advances its
     *    last-used watermark — rotation is the only use signal the hub has;
     *  - the family row is written BEFORE signing (the fail-closed read path
     *    must find the row the very first request references);
     *  - the freshly minted refresh token's `jti` is read back from the
     *    handler that signed it (never trusted from the builder's own scope)
     *    and registered as a grant of this family — mint-time registration is
     *    what makes an unknown presented jti fail closed.
     *
     * Unwired store → no `sid` claim anywhere, no writes: the response is the
     * historical shape byte-ident. The response `claims` echo passes through
     * the fixed-field JwtClaims DTO, which drops `sid` — the published wire
     * envelope is identical whether or not the family is stamped (pinned).
     *
     * @param string      $userId     Owner of the tokens.
     * @param string|null $familyHint Existing family to continue (rotation),
     *                                or null to open a new one.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     */
    private function createAuthResponse(string $userId, ?string $familyHint = null): array
    {
        $sessionId = null;
        if ($this->tokenSessions !== null) {
            $sessionId = $familyHint ?? $this->tokenSessions->createSession($userId);
        }

        $accessToken = $this->jwtHandler->createAccessToken($userId, sessionId: $sessionId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId, $sessionId);
        $claims = $this->jwtHandler->validateAccessToken($accessToken);
        $user = $this->getCurrentUser($userId) ?? [];

        if ($this->tokenSessions !== null && $sessionId !== null) {
            // Authoritative `jti` from the token the handler actually signed;
            // an empty read-back (a mint that somehow carries no jti) records
            // no grant — the presented token would then fail closed as
            // unknown, which is the honest direction of that degeneracy.
            $minted = $this->jwtHandler->validateToken($refreshToken);
            $mintedJti = $minted?->jti;
            if (is_string($mintedJti) && $mintedJti !== '') {
                $this->tokenSessions->recordGrant($mintedJti, $sessionId);
            }
            if ($familyHint !== null) {
                $this->tokenSessions->touchSession($sessionId);
            }
        }

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
