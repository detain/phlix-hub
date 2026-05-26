<?php

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\JwtClaims;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Orchestrates the hub's user-account lifecycle: register, login, refresh,
 * logout, plus the auto-promotion of the first registered user to admin.
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
    private const int RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const int RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes

    /** @var array<string, array{attempts: int, reset_at: int}> Static rate limit storage per IP */
    private static array $rateLimitStore = [];

    /**
     * @param UserRepository                $userRepository
     * @param JwtHandler                    $jwtHandler
     * @param AuditLogger                   $auditLogger
     * @param StructuredLogger              $logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher.
     * @param Connection|null               $db              Optional DB handle so the
     *                                                       first-user admin promotion
     *                                                       can run inside a transaction.
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JwtHandler $jwtHandler,
        private readonly AuditLogger $auditLogger,
        private readonly StructuredLogger $logger,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?Connection $db = null,
    ) {
    }

    /**
     * Get the client IP address for rate limiting.
     */
    private function getClientIp(): string
    {
        /** @var mixed $ip */
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return is_string($ip) ? $ip : '127.0.0.1';
    }

    /**
     * Check if the client IP has exceeded the rate limit.
     *
     * @throws RateLimitException When rate limit is exceeded
     */
    private function checkRateLimit(string $ip): void
    {
        $now = time();

        if (!isset(self::$rateLimitStore[$ip])) {
            return;
        }

        $record = self::$rateLimitStore[$ip];

        if ($record['reset_at'] <= $now) {
            unset(self::$rateLimitStore[$ip]);
            return;
        }

        if ($record['attempts'] >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            throw new RateLimitException(
                resetAt: $record['reset_at'],
                remaining: 0
            );
        }
    }

    /**
     * Record a failed authentication attempt for rate limiting.
     */
    private function recordFailedAttempt(string $ip): void
    {
        $now = time();

        if (!isset(self::$rateLimitStore[$ip]) || self::$rateLimitStore[$ip]['reset_at'] <= $now) {
            self::$rateLimitStore[$ip] = [
                'attempts' => 1,
                'reset_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
            ];
            return;
        }

        self::$rateLimitStore[$ip]['attempts']++;
    }

    /**
     * Clear rate limit data for a client IP after successful auth.
     */
    private function clearRateLimit(string $ip): void
    {
        unset(self::$rateLimitStore[$ip]);
    }

    /**
     * Register a fresh account.
     *
     * @param string $username Chosen username (3-50 chars).
     * @param string $email    Email; must pass {@see FILTER_VALIDATE_EMAIL}.
     * @param string $password Plain password (>= 8 chars). Hashed with Argon2ID.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When validation fails or the email/username is already taken.
     */
    public function register(string $username, string $email, string $password): array
    {
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new InvalidArgumentException('Username must be 3-50 characters');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }
        if ($this->userRepository->usernameExists($username)) {
            throw new InvalidArgumentException('Username already taken');
        }
        if ($this->userRepository->emailExists($email)) {
            throw new InvalidArgumentException('Email already registered');
        }

        // Detect first-user case BEFORE create() so the row we are about
        // to insert does not itself count as a "prior" user. Same policy
        // the server uses; see SESSION_HANDOFF.md decision #7.
        $isFirstUser = $this->userRepository->countUsers() === 0;

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
     * Authenticate a user with credentials.
     *
     * @param string $usernameOrEmail Either the username or the email — looked up against both indexes.
     * @param string $password        Plain password.
     * @param string $deviceId        Opaque device/session identifier (no formal session rows yet).
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When the credentials do not match.
     */
    public function login(string $usernameOrEmail, string $password, string $deviceId): array
    {
        $clientIp = $this->getClientIp();
        $this->checkRateLimit($clientIp);

        $user = $this->userRepository->findByUsername($usernameOrEmail);
        if ($user === null) {
            $user = $this->userRepository->findByEmail($usernameOrEmail);
        }

        if ($user === null) {
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
     * Validate a refresh token and mint a fresh access + refresh pair.
     *
     * @param string $refreshToken Encoded refresh JWT.
     *
     * @return array{access_token:string,refresh_token:string,token_type:string,expires_in:int,user:array<string,mixed>,claims:array<string,mixed>}
     *
     * @throws InvalidArgumentException When the token is invalid or expired.
     */
    public function refresh(string $refreshToken): array
    {
        $claims = $this->jwtHandler->validateRefreshToken($refreshToken);
        if ($claims === null) {
            throw new InvalidArgumentException('Invalid or expired refresh token');
        }
        return $this->createAuthResponse($claims->sub);
    }

    /**
     * Mark the user as logged out. The hub does NOT track refresh-token
     * revocation server-side, so this method only writes the audit and
     * event entries.
     */
    public function logout(string $userId, string $sessionId, string $reason = UserLoggedOut::REASON_EXPLICIT): void
    {
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

    /**
     * Expose the underlying JwtHandler for callers that need to mint or
     * validate tokens directly (e.g. the AuthMiddleware). We keep the
     * accessor narrow so the rest of the surface remains stable.
     */
    public function jwt(): JwtHandler
    {
        return $this->jwtHandler;
    }
}
