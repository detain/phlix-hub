<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AdminMiddleware}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 *
 * @covers \Phlix\Hub\Http\Middleware\AdminMiddleware
 */
final class AdminMiddlewareTest extends TestCase
{
    public function testMissingUserIdReturns401(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);
        $mw = new AdminMiddleware($repo, $audit);

        $request = new Request();
        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
    }

    public function testNonAdminReturns403AndAuditsPermissionDenied(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findAdminById')->willReturn(null);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logPermissionDenied')
            ->with('u-1', 'admin', 'access');

        $mw = new AdminMiddleware($repo, $audit);

        $request = new Request();
        $request->userId = 'u-1';

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('auth.not_admin', $response->body);
    }

    public function testAdminPassesThrough(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findAdminById')->willReturn(['id' => 'u-9', 'is_admin' => 1]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logPermissionDenied');

        $mw = new AdminMiddleware($repo, $audit);

        $request = new Request();
        $request->userId = 'u-9';

        self::assertNull($mw($request));
    }

    public function testCheckAccessReturnsStatusCodeWithoutBuildingResponse(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);
        $mw = new AdminMiddleware($repo, $audit);

        $request = new Request();
        self::assertSame(401, $mw->checkAccess($request));
    }

    public function testCheckAccessReturnsNullForAdmin(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findAdminById')->willReturn(['id' => 'u-9', 'is_admin' => 1]);
        $audit = $this->createMock(AuditLogger::class);

        $mw = new AdminMiddleware($repo, $audit);
        $request = new Request();
        $request->userId = 'u-9';

        self::assertNull($mw->checkAccess($request));
    }
}
