<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;
use Phlix\Hub\Requests\RequestTransitionResult;
use Phlix\Hub\Tests\Support\StubArrTransport;
use Phlix\Shared\Arr\ArrClientFactory;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RequestController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class RequestControllerTest extends TestCase
{
    /** @var RequestManager&\PHPUnit\Framework\MockObject\MockObject */
    private RequestManager $manager;
    /** @var RequestNotification&\PHPUnit\Framework\MockObject\MockObject */
    private RequestNotification $notification;
    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;
    /** @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogger $audit;
    private RequestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createMock(RequestManager::class);
        $this->notification = $this->createMock(RequestNotification::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);
        $this->controller = new RequestController($this->manager, $this->notification, $this->users, $this->audit);
    }

    public function testCreateRequestReturns401WhenNotAuthenticated(): void
    {
        $req = $this->request('POST', '/api/v1/me/requests');
        self::assertSame(401, $this->controller->createRequest($req, [])->statusCode);
    }

    public function testCreateRequestReturns400WhenTypeInvalid(): void
    {
        $req = $this->request('POST', '/api/v1/me/requests', userId: 'u1', body: ['type' => 'bogus']);
        self::assertSame(400, $this->controller->createRequest($req, [])->statusCode);
    }

    public function testCreateRequestReturns400WhenTmdbIdMissing(): void
    {
        $req = $this->request('POST', '/api/v1/me/requests', userId: 'u1', body: ['type' => 'movie', 'title' => 't']);
        self::assertSame(400, $this->controller->createRequest($req, [])->statusCode);
    }

    public function testCreateRequestReturns400WhenTitleMissing(): void
    {
        $req = $this->request('POST', '/api/v1/me/requests', userId: 'u1', body: ['type' => 'movie', 'tmdb_id' => 1]);
        self::assertSame(400, $this->controller->createRequest($req, [])->statusCode);
    }

    public function testCreateRequestReturns201OnSuccess(): void
    {
        $this->manager->expects(self::once())
            ->method('createRequest')
            ->with('u1', 'movie', 42, 'The Movie', null, null, null)
            ->willReturn($this->row(['id' => 'r1', 'user_id' => 'u1', 'title' => 'The Movie', 'tmdb_id' => 42]));
        $this->notification->expects(self::once())->method('notifySubmitted');

        $req = $this->request(
            'POST',
            '/api/v1/me/requests',
            userId: 'u1',
            body: ['type' => 'movie', 'tmdb_id' => 42, 'title' => 'The Movie']
        );
        $response = $this->controller->createRequest($req, []);

        self::assertSame(201, $response->statusCode);
    }

    public function testListMyRequestsReturnsUserRows(): void
    {
        $this->manager->expects(self::once())
            ->method('listUserRequests')
            ->with('u1')
            ->willReturn([$this->row(['id' => 'r1', 'user_id' => 'u1'])]);

        $req = $this->request('GET', '/api/v1/me/requests', userId: 'u1');
        $response = $this->controller->listMyRequests($req, []);

        self::assertSame(200, $response->statusCode);
    }

    public function testGetMyRequestReturns403WhenNotOwner(): void
    {
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1', 'user_id' => 'other']));
        $req = $this->request('GET', '/api/v1/me/requests/r1', userId: 'u1');
        self::assertSame(403, $this->controller->getMyRequest($req, ['id' => 'r1'])->statusCode);
    }

    public function testGetMyRequestReturns404WhenMissing(): void
    {
        $this->manager->method('getRequestById')->willReturn(null);
        $req = $this->request('GET', '/api/v1/me/requests/r1', userId: 'u1');
        self::assertSame(404, $this->controller->getMyRequest($req, ['id' => 'r1'])->statusCode);
    }

    public function testDeleteMyRequestReturns204WhenSuccessful(): void
    {
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1', 'user_id' => 'u1']));
        $this->manager->expects(self::once())->method('deleteRequest')->with('r1');
        $req = $this->request('DELETE', '/api/v1/me/requests/r1', userId: 'u1');
        $resp = $this->controller->deleteMyRequest($req, ['id' => 'r1']);
        self::assertSame(204, $resp->statusCode);
    }

    public function testAdminListReturns403WhenNotAdmin(): void
    {
        $this->users->method('findAdminById')->willReturn(null);
        $req = $this->request('GET', '/api/v1/admin/requests', userId: 'u1');
        self::assertSame(403, $this->controller->listAdminRequests($req, [])->statusCode);
    }

    public function testAdminListReturns200WhenAdmin(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('listPendingRequests')->willReturn([]);
        $req = $this->request('GET', '/api/v1/admin/requests', userId: 'u1');
        self::assertSame(200, $this->controller->listAdminRequests($req, [])->statusCode);
    }

    public function testApproveRequestReturns200OnSuccess(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row([
            'id' => 'r1',
            'user_id' => 'u2',
            'title' => 'X'
        ]));
        $this->manager->method('approveRequest')->willReturn(RequestTransitionResult::Applied);
        $this->notification->expects(self::once())->method('notifyApproved')->with('u2', 'X');
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');
        self::assertSame(200, $this->controller->approveRequest($req, ['id' => 'r1'])->statusCode);
    }

    /**
     * SH-075: only the real backend arm keeps the 500. The frame stays
     * byte-identical (the review asked to keep 500 for genuine failures, and
     * here the arr blame is true), the NotPending arm moved to a 409 pair.
     */
    public function testApproveReturns500OnlyForTheBackendArm(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1']));
        $this->manager->method('approveRequest')->willReturn(RequestTransitionResult::Failed);
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');

        $response = $this->controller->approveRequest($req, ['id' => 'r1']);
        self::assertSame(500, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame(
            ['error' => 'Internal Server Error', 'code' => 'approve_failed',
             'message' => 'Failed to approve. Check Radarr/Sonarr configuration.',],
            $body,
            'the genuine-failure 500 frame is unchanged from the pre-SH-075 wire',
        );
    }

    public function testApproveReturns409ForTheAlreadyActedArm(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1']));
        $this->manager->method('approveRequest')->willReturn(RequestTransitionResult::NotPending);
        $this->notification->expects(self::never())->method('notifyApproved');
        $this->audit->expects(self::never())->method('logAdminAction');
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');

        $response = $this->controller->approveRequest($req, ['id' => 'r1']);
        self::assertSame(409, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('This request was already approved or denied.', $body['error']);
        self::assertSame('approve_failed', $body['code']);
    }

    /**
     * Vanished-row race (manager NotFound) rides the same 404 frame as the
     * controller's own pre-read 404 — no oracle, no new code.
     */
    public function testApproveReturns404WhenTheRowVanishedMidFlight(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1']), null);
        $this->manager->method('approveRequest')->willReturn(RequestTransitionResult::NotFound);
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');

        $response = $this->controller->approveRequest($req, ['id' => 'r1']);
        self::assertSame(404, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('request_not_found', $body['code']);
    }

    public function testDenyRequestReturns200OnSuccess(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row([
            'id' => 'r1',
            'user_id' => 'u2',
            'title' => 'X'
        ]));
        $this->manager->method('rejectRequest')->willReturn(RequestTransitionResult::Applied);
        $this->notification->expects(self::once())->method('notifyRejected')->with('u2', 'X', 'nope');
        $req = $this->request('POST', '/api/v1/admin/requests/r1/deny', userId: 'u1', body: ['reason' => 'nope']);
        self::assertSame(200, $this->controller->denyRequest($req, ['id' => 'r1'])->statusCode);
    }

    // ------------------------------------------------ SH-075 wire law (round 2)

    /**
     * Review hub__webux #1 (SH-075, MAJOR): approving/denying a request that a
     * first click (or the other admin) already acted on must NOT answer 500
     * blaming the *arr backend. These tests drive the REAL handler chain —
     * real controller over a real RequestManager on a stateful fake DB — twice
     * against the same row, which is exactly the second-click the review
     * reproduces: first the arr-success approve (200), then the same call
     * again (must be a 409 conflict speaking human prose, riding only
     * REGISTERED codes — the @phlix/contracts registry is frozen, so the
     * review's `request_not_pending` suggestion cannot ship).
     */
    public function testSecondApproveAfterSuccessConflictsInsteadOfBlamingArr(): void
    {
        $state = ['status' => 'pending'];
        $controller = $this->wiredRealManagerController($state, failArr: false);

        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        // The admin acted exactly once across two clicks: the conflict arm
        // must neither re-audit nor re-notify the member.
        $this->audit->expects(self::once())->method('logAdminAction');
        $this->notification->expects(self::once())->method('notifyApproved');

        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');

        $first = $controller->approveRequest($req, ['id' => 'r1']);
        self::assertSame(200, $first->statusCode, 'first click approves for real');
        self::assertSame('approved', $state['status']);

        $second = $controller->approveRequest($req, ['id' => 'r1']);
        self::assertSame(409, $second->statusCode, 'second click is a state conflict, not a server crash');

        $body = $this->decodeBody($second->body);
        self::assertSame(
            ['error', 'code', 'message'],
            array_keys($body),
            'the conflict frame keeps the canonical key order',
        );
        self::assertSame('This request was already approved or denied.', $body['error']);
        self::assertSame($body['error'], $body['message'], 'message mirrors error (prose-law precedent)');
        self::assertSame('approve_failed', $body['code'], 'registered code only — no new wire codes');
        self::assertStringNotContainsString('Radarr', (string) $body['error'], 'the *arr blame stays off this arm');
        self::assertSame('approved', $state['status'], 'the conflict arm changes nothing');
    }

    public function testDenyAfterApproveConflictsWithHumanProse(): void
    {
        $state = ['status' => 'approved'];
        $controller = $this->wiredRealManagerController($state, failArr: false);

        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->audit->expects(self::never())->method('logAdminAction');
        $this->notification->expects(self::never())->method('notifyRejected');

        $req = $this->request('POST', '/api/v1/admin/requests/r1/deny', userId: 'u1', body: ['reason' => 'dup']);
        $response = $controller->denyRequest($req, ['id' => 'r1']);

        self::assertSame(409, $response->statusCode, 'denying an approved row is a conflict, not a crash');

        $body = $this->decodeBody($response->body);
        self::assertSame(
            ['error', 'code', 'message'],
            array_keys($body),
            'deny_failed gains the human message leg the review demanded',
        );
        self::assertNotSame('Internal Server Error', $body['error'], 'the bare HTTP phrase must no longer ship');
        self::assertSame('This request was already approved or denied.', $body['error']);
        self::assertSame('deny_failed', $body['code'], 'registered code only — no new wire codes');
        self::assertSame('approved', $state['status']);
    }

    /**
     * The other half of the split: a GENUINE backend failure keeps the old
     * 500 `approve_failed` frame (the arr blame is now true of it) and the
     * claim is released, so the admin can retry after fixing the backend.
     */
    public function testGenuineArrFailureKeepsTheFiftyHundredBlameAndReleasesTheClaim(): void
    {
        $state = ['status' => 'pending'];
        $controller = $this->wiredRealManagerController($state, failArr: true);

        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->audit->expects(self::never())->method('logAdminAction');
        $this->notification->expects(self::never())->method('notifyApproved');

        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');
        $response = $controller->approveRequest($req, ['id' => 'r1']);

        self::assertSame(500, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertSame('approve_failed', $body['code']);
        self::assertSame('Failed to approve. Check Radarr/Sonarr configuration.', $body['message']);
        self::assertSame('pending', $state['status'], 'the claim must be released for retry');
    }

    /**
     * Real controller over real RequestManager over a stateful fake DB with a
     * scripted *arr transport; `$state` is shared by reference so tests can
     * assert what the handler actually did to the row.
     *
     * @param array{status: string} $state
     */
    private function wiredRealManagerController(array &$state, bool $failArr): RequestController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(
                /** @param array<string, mixed>|null $params */
                static function (string $sql, ?array $params) use (&$state) {
                    if (str_contains($sql, 'FROM requests WHERE id')) {
                        return [RequestControllerTest::publicRow($state['status'])];
                    }
                    if (str_contains($sql, "UPDATE requests SET status = 'rejected'")) {
                        if ($state['status'] === 'pending') {
                            $state['status'] = 'rejected';
                            return 1;
                        }
                        return 0;
                    }
                    if (str_contains($sql, 'UPDATE requests SET status = :to')) {
                        $from = is_array($params) ? ($params['from'] ?? null) : null;
                        $to = is_array($params) ? ($params['to'] ?? null) : null;
                        if (is_string($from) && is_string($to) && $state['status'] === $from) {
                            $state['status'] = $to;
                            return 1;
                        }
                        return 0;
                    }
                    return [];
                },
            );

        $transport = new StubArrTransport();
        $transport->failAddMovie = $failArr;
        $manager = new RequestManager(
            $db,
            new ArrClientFactory(
                ['radarr' => ['enabled' => true, 'url' => 'http://stub.local', 'api_key' => 'k']],
                $transport,
            ),
            $this->createMock(StructuredLogger::class),
        );

        return new RequestController($manager, $this->notification, $this->users, $this->audit);
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicRow(string $status): array
    {
        return array_merge([
            'id' => 'r1',
            'user_id' => 'u2',
            'type' => 'movie',
            'tmdb_id' => 12345,
            'title' => 'The Movie',
            'poster_url' => null,
            'season' => null,
            'episode' => null,
            'status' => 'pending',
            'rejection_reason' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(mixed $body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

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
     * @param array<string, mixed> $overrides
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
