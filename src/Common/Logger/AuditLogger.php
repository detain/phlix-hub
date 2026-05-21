<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Logger;

/**
 * Specialised logger for security and audit events on the hub.
 *
 * Mirrors `phlix-server`'s `\Phlix\Common\Logger\AuditLogger` minus the
 * plugin-specific helpers; the hub does not host plugins so
 * `logPluginAction()` is intentionally omitted.
 *
 * Every method writes to the configured `audit` channel — see
 * {@see \Phlix\Hub\Common\Logger\LogChannels::AUDIT}. The channel writes
 * to `.logs/audit.log` by default (config/logger.php).
 *
 * @package Phlix\Hub\Common\Logger
 * @since 0.2.0
 */
class AuditLogger
{
    /**
     * @param StructuredLogger $logger Underlying channel-bound logger.
     */
    public function __construct(private readonly StructuredLogger $logger)
    {
    }

    /**
     * Record a successful or failed login attempt.
     *
     * @param string  $userId   UUID of the user (or empty string when unknown).
     * @param string  $deviceId Opaque device/session identifier.
     * @param bool    $success  Whether authentication succeeded.
     * @param ?string $reason   Optional human-readable reason (e.g. "bad_password").
     */
    public function logLogin(string $userId, string $deviceId, bool $success, ?string $reason = null): void
    {
        $this->logger->info('User login attempt', [
            'event'     => 'login',
            'user_id'   => $userId,
            'device_id' => $deviceId,
            'success'   => $success,
            'reason'    => $reason,
        ]);
    }

    /**
     * Record a successful logout.
     */
    public function logLogout(string $userId, string $sessionId): void
    {
        $this->logger->info('User logout', [
            'event'      => 'logout',
            'user_id'    => $userId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Record a generic auth failure (rate limiting, invalid credentials, etc.).
     *
     * @param string               $reason  Short machine-friendly tag.
     * @param array<string, mixed> $context Additional structured context.
     */
    public function logFailedAuth(string $reason, array $context = []): void
    {
        $this->logger->warning('Authentication failure', array_merge([
            'event'  => 'auth_failure',
            'reason' => $reason,
        ], $context));
    }

    /**
     * Record a permission-denied / authz failure.
     */
    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->logger->warning('Permission denied', [
            'event'    => 'permission_denied',
            'user_id'  => $userId,
            'resource' => $resource,
            'action'   => $action,
        ]);
    }

    /**
     * Record a fresh user signup. Separate from login so dashboards can
     * count the two cardinalities independently.
     */
    public function logSignup(string $userId, string $username, string $email): void
    {
        $this->logger->info('User signup', [
            'event'    => 'signup',
            'user_id'  => $userId,
            'username' => $username,
            'email'    => $email,
        ]);
    }

    /**
     * Record an admin-initiated action on a target resource (HUB-A09-2).
     *
     * @param string               $userId   Admin user id performing the action.
     * @param string               $action   Short machine-friendly action tag
     *                                       (e.g. "request.approve").
     * @param string               $resource Resource id the action targets.
     * @param array<string, mixed> $context  Additional structured context.
     */
    public function logAdminAction(string $userId, string $action, string $resource, array $context = []): void
    {
        $this->logger->info('Admin action', array_merge([
            'event'    => 'admin_action',
            'user_id'  => $userId,
            'action'   => $action,
            'resource' => $resource,
        ], $context));
    }
}
