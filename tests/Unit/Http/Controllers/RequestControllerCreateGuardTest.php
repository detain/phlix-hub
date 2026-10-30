<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-23 — the create surface cannot be pumped: per-member budget, pending
 * dedup, and the standing pending cap, each pinned at the controller boundary.
 *
 * The pre-cure route (bare auth-group registration in the review evidence)
 * let any member insert admin-queue rows and fire admin notifications at will
 * — a slow-motion DoS on the operator's queue and the hub's log volume.
 * Cure mirrors the M-HUB-22 relay-token precedent: budget spent BEFORE any
 * queue read, one boundary-transition audit, RateLimitException escaping to
 * the central 429 mapping; then dedup (echo, 200 — no second row, no second
 * notification) and the {@see RequestManager::MAX_PENDING_REQUESTS_PER_USER}
 * cap riding the already-registered `quota.exceeded` code with its additive
 * QuotaFrames discriminator (zero new wire codes).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class RequestControllerCreateGuardTest extends TestCase
{
    /** @var RequestManager&\PHPUnit\Framework\MockObject\MockObject */
    private RequestManager $manager;
    /** @var RequestNotification&\PHPUnit\Framework\MockObject\MockObject */
    private RequestNotification $notification;
    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;
    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createMock(RequestManager::class);
        $this->notification = $this->createMock(RequestNotification::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
    }

    private function controller(?RateLimiterInterface $limiter): RequestController
    {
        return new RequestController($this->manager, $this->notification, $this->users, $this->audit, $limiter);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        $req = new Request();
        $req->method = 'POST';
        $req->path = '/api/v1/me/requests';
        $req->userId = 'u1';
        $req->body = $body;
        return $req;
    }

    /** @return array<string, mixed> */
    private function validBody(): array
    {
        return ['type' => 'movie', 'tmdb_id' => 42, 'title' => 'The Movie'];
    }

    public function testTheCreateValueIsPinnedByLaw(): void
    {
        self::assertSame(50, RequestManager::MAX_PENDING_REQUESTS_PER_USER);
    }

    public function testOverBudgetCreateThrowsBeforeAnyQueueRead(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::once())
            ->method('hit')
            ->with('client_request_create:u1')
            ->willReturn(new RateLimitState(count: 4, remaining: 0, resetAt: 123, limited: true, limit: 3));

        $this->manager->expects(self::never())->method('findPendingDuplicate');
        $this->manager->expects(self::never())->method('countPendingForUser');
        $this->manager->expects(self::never())->method('createRequest');

        $this->expectException(RateLimitException::class);
        $this->controller($limiter)->createRequest($this->post($this->validBody()), []);
    }

    public function testBoundaryTransitionAuditsExactlyOnce(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')
            ->willReturn(new RateLimitState(count: 3, remaining: 0, resetAt: 123, limited: true, limit: 3));

        $this->audit->expects(self::once())
            ->method('logFailedAuth')
            ->with('request_create_rate_limited', ['user_id' => 'u1']);

        $this->expectException(RateLimitException::class);
        $this->controller($limiter)->createRequest($this->post($this->validBody()), []);
    }

    public function testBeyondBoundaryDoesNotReAudit(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')
            ->willReturn(new RateLimitState(count: 9, remaining: 0, resetAt: 123, limited: true, limit: 3));

        $this->audit->expects(self::never())->method('logFailedAuth');

        $this->expectException(RateLimitException::class);
        $this->controller($limiter)->createRequest($this->post($this->validBody()), []);
    }

    public function testWithinBudgetProceedsThroughEveryGuard(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')
            ->willReturn(new RateLimitState(count: 1, remaining: 19, resetAt: 123, limited: false, limit: 20));

        $this->manager->method('findPendingDuplicate')->willReturn(null);
        $this->manager->method('countPendingForUser')->willReturn(0);
        $this->manager->expects(self::once())->method('createRequest')->willReturn($this->row([]));
        $this->notification->expects(self::once())->method('notifySubmitted');

        $response = $this->controller($limiter)->createRequest($this->post($this->validBody()), []);
        self::assertSame(201, $response->statusCode);
    }

    public function testPendingDuplicateEchoesExistingRowWithoutSecondInsertOrNotify(): void
    {
        $existing = $this->row(['id' => 'r-existing', 'title' => 'The Movie', 'tmdb_id' => 42]);

        $this->manager->expects(self::once())
            ->method('findPendingDuplicate')
            ->with('u1', 'movie', 42, null, null)
            ->willReturn($existing);
        $this->manager->expects(self::never())->method('countPendingForUser');
        $this->manager->expects(self::never())->method('createRequest');
        $this->notification->expects(self::never())->method('notifySubmitted');

        $response = $this->controller(null)->createRequest($this->post($this->validBody()), []);

        self::assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Request already pending.', $decoded['message']);
        /** @var array<string, mixed> $pending */
        $pending = $decoded['request'];
        self::assertSame('r-existing', $pending['id']);
    }

    public function testStandingPendingCapRefusesWithQuotaExceededFrame(): void
    {
        $this->manager->method('findPendingDuplicate')->willReturn(null);
        $this->manager->method('countPendingForUser')
            ->with('u1')
            ->willReturn(RequestManager::MAX_PENDING_REQUESTS_PER_USER);
        $this->manager->expects(self::never())->method('createRequest');
        $this->notification->expects(self::never())->method('notifySubmitted');

        $response = $this->controller(null)->createRequest($this->post($this->validBody()), []);

        self::assertSame(409, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('quota.exceeded', $decoded['code']);
        self::assertSame('requests', $decoded['quota_kind']);
        self::assertSame('on_release', $decoded['quota_reset']);
        self::assertIsString($decoded['error']);
        self::assertSame($decoded['error'], $decoded['message']);
    }

    public function testCapCountsOnlyUntilTheBoundaryLeavesRoom(): void
    {
        $this->manager->method('findPendingDuplicate')->willReturn(null);
        $this->manager->method('countPendingForUser')
            ->willReturn(RequestManager::MAX_PENDING_REQUESTS_PER_USER - 1);
        $this->manager->expects(self::once())->method('createRequest')->willReturn($this->row([]));

        $response = $this->controller(null)->createRequest($this->post($this->validBody()), []);
        self::assertSame(201, $response->statusCode);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'id' => 'default-id',
            'user_id' => 'default-user',
            'type' => 'movie',
            'tmdb_id' => 1,
            'title' => 'Default Title',
            'poster_url' => null,
            'season' => null,
            'episode' => null,
            'status' => 'pending',
            'rejection_reason' => null,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ], $overrides);
    }
}
