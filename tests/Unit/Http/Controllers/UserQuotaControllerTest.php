<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Http\Controllers\UserQuotaController;
use Phlix\Hub\Http\Request;

/**
 * Unit tests for {@see UserQuotaController} (HB-3.4 G5).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\UserQuotaController
 */
final class UserQuotaControllerTest extends TestCase
{
    /** @var RelaySessionManager&MockObject */
    private RelaySessionManager $sessions;
    /** @var UserRepository&MockObject */
    private UserRepository $users;
    /** @var AuditLogger&MockObject */
    private AuditLogger $audit;
    private UserQuotaController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = $this->createMock(RelaySessionManager::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->controller = new UserQuotaController($this->sessions, $this->users, $this->audit);
    }

    // ---- viewOwnBandwidth (self, auth only) -------------------------------

    public function testViewOwnBandwidthReturns401WhenUnauthenticated(): void
    {
        $response = $this->controller->viewOwnBandwidth($this->request('GET', '/api/v1/me/bandwidth'));

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testViewOwnBandwidthReturnsCallersOwnUsageWithoutAdminCheck(): void
    {
        // Self path must NOT consult the admin repository at all.
        $this->users->expects(self::never())->method('findAdminById');
        $this->sessions->expects(self::once())->method('getUserBandwidth')->with('u-self')->willReturn([
            'bytes_in' => 111,
            'bytes_out' => 222,
            'quota_bytes_in' => 1000,
            'quota_bytes_out' => 2000,
        ]);
        $this->sessions->expects(self::once())->method('getUserMaxConcurrentStreams')->with('u-self')->willReturn(3);

        $response = $this->controller->viewOwnBandwidth(
            $this->request('GET', '/api/v1/me/bandwidth', userId: 'u-self'),
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame('u-self', $payload['user_id']);
        self::assertSame(111, $payload['bytes_in']);
        self::assertSame(222, $payload['bytes_out']);
        self::assertSame(1000, $payload['quota_bytes_in']);
        self::assertSame(2000, $payload['quota_bytes_out']);
        self::assertSame(3, $payload['max_concurrent_streams']);
    }

    public function testViewOwnBandwidthZeroesUsageWhenNoRow(): void
    {
        $this->sessions->method('getUserBandwidth')->willReturn(null);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);

        $response = $this->controller->viewOwnBandwidth(
            $this->request('GET', '/api/v1/me/bandwidth', userId: 'u-self'),
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame(0, $payload['bytes_in']);
        self::assertSame(0, $payload['max_concurrent_streams']);
    }

    // ---- viewUserBandwidth (admin or 403) ---------------------------------

    public function testViewUserBandwidthReturns401WhenUnauthenticated(): void
    {
        $response = $this->controller->viewUserBandwidth(
            $this->request('GET', '/api/v1/admin/users/u-target/bandwidth'),
            ['id' => 'u-target'],
        );

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testViewUserBandwidthReturns403WhenCallerNotAdmin(): void
    {
        // A non-admin trying to read ANOTHER user's bandwidth is forbidden.
        $this->users->method('findAdminById')->willReturn(null);
        $this->audit->expects(self::once())->method('logPermissionDenied');
        $this->sessions->expects(self::never())->method('getUserBandwidth');

        $response = $this->controller->viewUserBandwidth(
            $this->request('GET', '/api/v1/admin/users/u-victim/bandwidth', userId: 'u-attacker'),
            ['id' => 'u-victim'],
        );

        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('admin_required', $response->body);
    }

    public function testViewUserBandwidthReturns400WhenIdMissing(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);

        $response = $this->controller->viewUserBandwidth(
            $this->request('GET', '/api/v1/admin/users//bandwidth', userId: 'admin'),
            ['id' => ''],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('missing_user_id', $response->body);
    }

    public function testViewUserBandwidthReturns200ForAdminReadingAnyUser(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::once())->method('getUserBandwidth')->with('u-target')->willReturn([
            'bytes_in' => 5,
            'bytes_out' => 6,
            'quota_bytes_in' => 7,
            'quota_bytes_out' => 8,
        ]);
        $this->sessions->expects(self::once())->method('getUserMaxConcurrentStreams')->with('u-target')->willReturn(9);

        $response = $this->controller->viewUserBandwidth(
            $this->request('GET', '/api/v1/admin/users/u-target/bandwidth', userId: 'admin'),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame('u-target', $payload['user_id']);
        self::assertSame(5, $payload['bytes_in']);
        self::assertSame(9, $payload['max_concurrent_streams']);
    }

    // ---- setUserQuota (admin or 403) --------------------------------------

    public function testSetUserQuotaReturns401WhenUnauthenticated(): void
    {
        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users/u-target/quota'),
            ['id' => 'u-target'],
        );

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testSetUserQuotaReturns403WhenCallerNotAdmin(): void
    {
        $this->users->method('findAdminById')->willReturn(null);
        $this->audit->expects(self::once())->method('logPermissionDenied');
        $this->sessions->expects(self::never())->method('setUserQuota');

        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users/u-target/quota', userId: 'u-nonadmin', body: [
                'quota_bytes_in' => 10,
                'quota_bytes_out' => 20,
                'max_concurrent_streams' => 2,
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('admin_required', $response->body);
    }

    public function testSetUserQuotaReturns400WhenIdMissing(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);

        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users//quota', userId: 'admin', body: [
                'quota_bytes_in' => 10,
                'quota_bytes_out' => 20,
                'max_concurrent_streams' => 2,
            ]),
            ['id' => ''],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('missing_user_id', $response->body);
    }

    /**
     * @dataProvider invalidQuotaBodies
     *
     * @param array<string, mixed> $body
     */
    public function testSetUserQuotaReturns400OnInvalidBody(array $body): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::never())->method('setUserQuota');

        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users/u-target/quota', userId: 'admin', body: $body),
            ['id' => 'u-target'],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('invalid_quota', $response->body);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidQuotaBodies(): array
    {
        return [
            'missing quota_bytes_in' => [['quota_bytes_out' => 1, 'max_concurrent_streams' => 1]],
            'missing quota_bytes_out' => [['quota_bytes_in' => 1, 'max_concurrent_streams' => 1]],
            'missing max_concurrent_streams' => [['quota_bytes_in' => 1, 'quota_bytes_out' => 1]],
            'negative bytes_in' => [['quota_bytes_in' => -1, 'quota_bytes_out' => 1, 'max_concurrent_streams' => 1]],
            'non-integer float' => [['quota_bytes_in' => 1.5, 'quota_bytes_out' => 1, 'max_concurrent_streams' => 1]],
            'non-numeric string' => [['quota_bytes_in' => 'lots', 'quota_bytes_out' => 1, 'max_concurrent_streams' => 1]],
            'streams over bound' => [['quota_bytes_in' => 1, 'quota_bytes_out' => 1, 'max_concurrent_streams' => 1001]],
            'bytes over bound' => [
                ['quota_bytes_in' => 1125899906842645, 'quota_bytes_out' => 1, 'max_concurrent_streams' => 1],
            ],
        ];
    }

    public function testSetUserQuotaAppliesAllThreeCapsAndAudits(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        // The critical assertion: all three caps (incl. max_concurrent_streams)
        // are forwarded to the extended 4-arg setUserQuota signature.
        $this->sessions->expects(self::once())
            ->method('setUserQuota')
            ->with('u-target', 10485760, 5242880, 4);
        $this->audit->expects(self::once())
            ->method('logAdminAction')
            ->with('admin', 'user.quota.set', 'u-target', self::anything());
        // Read-back for the 200 response body.
        $this->sessions->method('getUserBandwidth')->willReturn([
            'bytes_in' => 0,
            'bytes_out' => 0,
            'quota_bytes_in' => 10485760,
            'quota_bytes_out' => 5242880,
        ]);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(4);

        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users/u-target/quota', userId: 'admin', body: [
                'quota_bytes_in' => 10485760,
                'quota_bytes_out' => 5242880,
                'max_concurrent_streams' => 4,
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame(10485760, $payload['quota_bytes_in']);
        self::assertSame(4, $payload['max_concurrent_streams']);
    }

    public function testSetUserQuotaAcceptsZeroAsUnlimited(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::once())->method('setUserQuota')->with('u-target', 0, 0, 0);
        $this->sessions->method('getUserBandwidth')->willReturn(null);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);

        $response = $this->controller->setUserQuota(
            $this->request('PUT', '/api/v1/admin/users/u-target/quota', userId: 'admin', body: [
                'quota_bytes_in' => 0,
                'quota_bytes_out' => 0,
                'max_concurrent_streams' => 0,
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?string $userId = null, ?array $body = null): Request
    {
        $req = new Request();
        $req->method = $method;
        $req->path = $path;
        if ($userId !== null) {
            $req->userId = $userId;
        }
        if ($body !== null) {
            $req->body = $body;
        }
        return $req;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
