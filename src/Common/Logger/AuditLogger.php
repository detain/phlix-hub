<?php

/**
 * Phlix hub component: Logger.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Logger;

use Phlix\Hub\Hub\AuditLogRepository;

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
 * When an {@see AuditLogRepository} is available (injected by the container
 * via the optional ctor param), each event is also persisted to the
 * `audit_logs` database table.
 *
 * @package Phlix\Hub\Common\Logger
 */
class AuditLogger
{
    /**
     * @param StructuredLogger    $logger   Underlying channel-bound logger.
     * @param AuditLogRepository|null $auditRepo Optional DB-backed audit repo.
     *
     * @since H.5b Added nullable $auditRepo parameter.
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        private readonly ?AuditLogRepository $auditRepo = null,
    ) {
    }

    /**
     * Record a successful or failed login attempt.
     *
     * @param string  $userId   UUID of the user (or empty string when unknown).
     * @param string  $deviceId  Opaque device/session identifier.
     * @param bool    $success  Whether authentication succeeded.
     * @param ?string $reason   Optional human-readable reason (e.g. "bad_password").
     */
    public function logLogin(string $userId, string $deviceId, bool $success, ?string $reason = null): void
    {
        $this->logger->info('User login attempt', [
            'event' => 'login',
            'user_id' => $userId,
            'device_id' => $deviceId,
            'success' => $success,
            'reason' => $reason,
        ]);

        $this->auditRepo?->log(
            event: 'login',
            userId: $userId,
            deviceId: $deviceId,
            success: $success,
            reason: $reason,
        );
    }

    /**
     * Record a successful logout.
     */
    public function logLogout(string $userId, string $sessionId): void
    {
        $this->logger->info('User logout', [
            'event' => 'logout',
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);

        $this->auditRepo?->log(
            event: 'logout',
            userId: $userId,
            sessionId: $sessionId,
        );
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
            'event' => 'auth_failure',
            'reason' => $reason,
        ], $context));

        $this->auditRepo?->log(
            event: 'auth_failure',
            reason: $reason,
            context: $context,
        );
    }

    /**
     * Record a permission-denied / authz failure.
     */
    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->logger->warning('Permission denied', [
            'event' => 'permission_denied',
            'user_id' => $userId,
            'resource' => $resource,
            'action' => $action,
        ]);

        $this->auditRepo?->log(
            event: 'permission_denied',
            userId: $userId,
            resource: $resource,
            action: $action,
        );
    }

    /**
     * Record a fresh user signup. Separate from login so dashboards can
     * count the two cardinalities independently.
     */
    public function logSignup(string $userId, string $username, string $email): void
    {
        $this->logger->info('User signup', [
            'event' => 'signup',
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
        ]);

        $this->auditRepo?->log(
            event: 'signup',
            userId: $userId,
            resource: $username,
            action: $email,
        );
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
            'event' => 'admin_action',
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
        ], $context));

        $this->auditRepo?->log(
            event: 'admin_action',
            userId: $userId,
            action: $action,
            resource: $resource,
            context: $context,
        );
    }

    /**
     * Record a hub federation connection attempt.
     *
     * @param string  $peerId   Peer UUID.
     * @param string  $peerName Human-readable peer name.
     * @param string  $peerUrl  Public peer URL.
     * @param bool    $success  Whether the connection succeeded.
     * @param ?string $reason   Optional reason for failure.
     */
    public function logHubConnect(
        string $peerId,
        string $peerName,
        string $peerUrl,
        bool $success,
        ?string $reason = null,
    ): void {
        $this->logger->info('Hub connected', [
            'event' => 'hub_connect',
            'peer_id' => $peerId,
            'peer_name' => $peerName,
            'peer_url' => $peerUrl,
            'success' => $success,
            'reason' => $reason,
        ]);

        $this->auditRepo?->log(
            event: 'hub_connect',
            resource: $peerId,
            action: $peerUrl,
            success: $success,
            reason: $reason,
            context: ['peer_name' => $peerName],
        );
    }

    /**
     * Record a hub federation disconnection.
     *
     * @param string $peerId   Peer UUID.
     * @param string $peerName Human-readable peer name.
     * @param string $reason   Reason for disconnection.
     */
    public function logHubDisconnect(string $peerId, string $peerName, string $reason): void
    {
        $this->logger->info('Hub disconnected', [
            'event' => 'hub_disconnect',
            'peer_id' => $peerId,
            'peer_name' => $peerName,
            'reason' => $reason,
        ]);

        $this->auditRepo?->log(
            event: 'hub_disconnect',
            resource: $peerId,
            action: $reason,
            context: ['peer_name' => $peerName],
        );
    }

    /**
     * Record a cross-hub library share event.
     *
     * @param string $peerId     Peer UUID.
     * @param string $libraryId  Library UUID.
     * @param string $permission Share permission level.
     * @param string $action    Action performed ('created'|'revoked'|'accepted'|'rejected').
     */
    public function logLibraryShareCrossHub(string $peerId, string $libraryId, string $permission, string $action): void
    {
        $this->logger->info('Cross-hub library share', [
            'event' => 'library_share_cross_hub',
            'peer_id' => $peerId,
            'library_id' => $libraryId,
            'permission' => $permission,
            'action' => $action,
        ]);

        $this->auditRepo?->log(
            event: 'library_share_cross_hub',
            resource: $libraryId,
            action: $action,
            context: ['peer_id' => $peerId, 'permission' => $permission],
        );
    }

    /**
     * Record an admin delegation change.
     *
     * @param string $peerId Peer UUID.
     * @param string $userId User UUID.
     * @param string $action Action performed ('grant'|'revoke').
     */
    public function logAdminDelegation(string $peerId, string $userId, string $action): void
    {
        $this->logger->info('Admin delegation changed', [
            'event' => 'admin_delegation',
            'peer_id' => $peerId,
            'user_id' => $userId,
            'action' => $action,
        ]);

        $this->auditRepo?->log(
            event: 'admin_delegation',
            userId: $userId,
            resource: $peerId,
            action: $action,
        );
    }
}
