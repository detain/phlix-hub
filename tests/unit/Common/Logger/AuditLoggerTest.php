<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Common\Logger;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AuditLogger}.
 *
 * @package Phlix\Hub\Tests\unit\Common\Logger
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Common\Logger\AuditLogger
 */
final class AuditLoggerTest extends TestCase
{
    public function testLogLoginSuccessUsesInfoLevel(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('User login attempt', [
                'event'     => 'login',
                'user_id'   => 'u-1',
                'device_id' => 'dev',
                'success'   => true,
                'reason'    => null,
            ]);

        (new AuditLogger($logger))->logLogin('u-1', 'dev', true);
    }

    public function testLogLoginFailureCarriesReason(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('User login attempt', self::callback(static function ($context): bool {
                return is_array($context)
                    && ($context['success'] ?? null) === false
                    && ($context['reason'] ?? null) === 'bad_password';
            }));

        (new AuditLogger($logger))->logLogin('u-1', 'dev', false, 'bad_password');
    }

    public function testLogLogoutEmitsInfo(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('User logout', [
                'event'      => 'logout',
                'user_id'    => 'u-2',
                'session_id' => 'sess-9',
            ]);

        (new AuditLogger($logger))->logLogout('u-2', 'sess-9');
    }

    public function testLogFailedAuthEmitsWarning(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Authentication failure', self::callback(static function ($context): bool {
                return is_array($context)
                    && ($context['event'] ?? null) === 'auth_failure'
                    && ($context['reason'] ?? null) === 'rate_limited'
                    && ($context['username'] ?? null) === 'attacker';
            }));

        (new AuditLogger($logger))->logFailedAuth('rate_limited', ['username' => 'attacker']);
    }

    public function testLogPermissionDeniedEmitsWarning(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Permission denied', [
                'event'    => 'permission_denied',
                'user_id'  => 'u-3',
                'resource' => 'admin',
                'action'   => 'access',
            ]);

        (new AuditLogger($logger))->logPermissionDenied('u-3', 'admin', 'access');
    }

    public function testLogSignupEmitsInfoWithUsernameAndEmail(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('User signup', [
                'event'    => 'signup',
                'user_id'  => 'u-4',
                'username' => 'dave',
                'email'    => 'd@example.com',
            ]);

        (new AuditLogger($logger))->logSignup('u-4', 'dave', 'd@example.com');
    }
}
