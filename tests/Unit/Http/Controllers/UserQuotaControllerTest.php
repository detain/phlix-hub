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
            'non-numeric string' => [[
                'quota_bytes_in' => 'lots',
                'quota_bytes_out' => 1,
                'max_concurrent_streams' => 1
            ]],
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

    // ---- setUserThrottle (admin or 403) — S41 -----------------------------

    public function testSetUserThrottleReturns401WhenUnauthenticated(): void
    {
        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users/u-target/throttle'),
            ['id' => 'u-target'],
        );

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testSetUserThrottleReturns403WhenCallerNotAdmin(): void
    {
        $this->users->method('findAdminById')->willReturn(null);
        $this->audit->expects(self::once())->method('logPermissionDenied');
        $this->sessions->expects(self::never())->method('setUserThrottle');

        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users/u-target/throttle', userId: 'u-nonadmin', body: [
                'throttle_bps' => 5000000,
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('admin_required', $response->body);
    }

    public function testSetUserThrottleReturns400WhenIdMissing(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);

        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users//throttle', userId: 'admin', body: [
                'throttle_bps' => 5000000,
            ]),
            ['id' => ''],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('missing_user_id', $response->body);
    }

    /**
     * @dataProvider invalidThrottleBodies
     *
     * @param array<string, mixed> $body
     */
    public function testSetUserThrottleReturns400OnInvalidBody(array $body): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::never())->method('setUserThrottle');

        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users/u-target/throttle', userId: 'admin', body: $body),
            ['id' => 'u-target'],
        );

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('invalid_throttle', $response->body);
    }

    /**
     * Values NOT in the fixed allow-list {0,1,3,5,10,20,50 Mbps} must be rejected.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidThrottleBodies(): array
    {
        return [
            'missing throttle_bps' => [[]],
            'negative' => [['throttle_bps' => -1]],
            'non-integer float' => [['throttle_bps' => 3000000.5]],
            'non-numeric string' => [['throttle_bps' => 'fast']],
            'off-list value (2 Mbps)' => [['throttle_bps' => 2000000]],
            'off-list value (100 Mbps)' => [['throttle_bps' => 100000000]],
            'off-list value (1 bps)' => [['throttle_bps' => 1]],
        ];
    }

    /**
     * @dataProvider validThrottleLevels
     */
    public function testSetUserThrottleAcceptsEachAllowedLevelAndAudits(int $level): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::once())->method('setUserThrottle')->with('u-target', $level);
        $this->audit->expects(self::once())
            ->method('logAdminAction')
            ->with('admin', 'user.throttle.set', 'u-target', ['throttle_bps' => $level]);
        // Read-back for the 200 response body — throttle_bps is surfaced.
        $this->sessions->method('getUserBandwidth')->willReturn(null);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);
        $this->sessions->method('getUserThrottleBps')->willReturn($level);

        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users/u-target/throttle', userId: 'admin', body: [
                'throttle_bps' => $level,
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame($level, $payload['throttle_bps']);
    }

    /**
     * The full allowed dropdown set: 0 (Unlimited) + 1/3/5/10/20/50 Mbps.
     *
     * @return array<string, array{0: int}>
     */
    public static function validThrottleLevels(): array
    {
        return [
            'unlimited' => [0],
            '1 Mbps' => [1000000],
            '3 Mbps' => [3000000],
            '5 Mbps' => [5000000],
            '10 Mbps' => [10000000],
            '20 Mbps' => [20000000],
            '50 Mbps' => [50000000],
        ];
    }

    public function testSetUserThrottleAcceptsDigitStringLevel(): void
    {
        // Some clients send JSON numbers as strings; a digit-only string that maps
        // to an allowed level is accepted (mirrors the quota parser's narrowing).
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->expects(self::once())->method('setUserThrottle')->with('u-target', 10000000);
        $this->sessions->method('getUserBandwidth')->willReturn(null);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);
        $this->sessions->method('getUserThrottleBps')->willReturn(10000000);

        $response = $this->controller->setUserThrottle(
            $this->request('PUT', '/api/v1/admin/users/u-target/throttle', userId: 'admin', body: [
                'throttle_bps' => '10000000',
            ]),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
    }

    public function testThrottleValueIsSurfacedOnBandwidthGetPayload(): void
    {
        // The admin GET user-detail payload exposes the current throttle_bps so
        // the UI can display it — round-trips the read path (S41).
        $this->users->method('findAdminById')->willReturn(['id' => 'admin', 'is_admin' => 1]);
        $this->sessions->method('getUserBandwidth')->willReturn(null);
        $this->sessions->method('getUserMaxConcurrentStreams')->willReturn(0);
        $this->sessions->expects(self::once())->method('getUserThrottleBps')
            ->with('u-target')->willReturn(20000000);

        $response = $this->controller->viewUserBandwidth(
            $this->request('GET', '/api/v1/admin/users/u-target/bandwidth', userId: 'admin'),
            ['id' => 'u-target'],
        );

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode($response->body);
        self::assertSame(20000000, $payload['throttle_bps']);
        // Setting/reading throttle must NOT disturb the monthly byte-cap quota.
        self::assertSame(0, $payload['quota_bytes_in']);
        self::assertSame(0, $payload['quota_bytes_out']);
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
