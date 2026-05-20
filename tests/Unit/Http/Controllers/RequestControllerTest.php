<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;

/**
 * Unit tests for {@see RequestController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 * @since 0.6.0
 *
 * @covers \Phlix\Hub\Http\Controllers\RequestController
 */
final class RequestControllerTest extends TestCase
{
    /** @var RequestManager&\PHPUnit\Framework\MockObject\MockObject */
    private RequestManager $manager;
    /** @var RequestNotification&\PHPUnit\Framework\MockObject\MockObject */
    private RequestNotification $notification;
    /** @var UserRepository&\PHPUnit\Framework\MockObject\MockObject */
    private UserRepository $users;
    private RequestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createMock(RequestManager::class);
        $this->notification = $this->createMock(RequestNotification::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->controller = new RequestController($this->manager, $this->notification, $this->users);
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

        $req = $this->request('POST', '/api/v1/me/requests', userId: 'u1', body: ['type' => 'movie', 'tmdb_id' => 42, 'title' => 'The Movie']);
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
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1', 'user_id' => 'u2', 'title' => 'X']));
        $this->manager->method('approveRequest')->willReturn(true);
        $this->notification->expects(self::once())->method('notifyApproved')->with('u2', 'X');
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');
        self::assertSame(200, $this->controller->approveRequest($req, ['id' => 'r1'])->statusCode);
    }

    public function testApproveReturns500WhenManagerFails(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1']));
        $this->manager->method('approveRequest')->willReturn(false);
        $req = $this->request('POST', '/api/v1/admin/requests/r1/approve', userId: 'u1');
        self::assertSame(500, $this->controller->approveRequest($req, ['id' => 'r1'])->statusCode);
    }

    public function testDenyRequestReturns200OnSuccess(): void
    {
        $this->users->method('findAdminById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $this->manager->method('getRequestById')->willReturn($this->row(['id' => 'r1', 'user_id' => 'u2', 'title' => 'X']));
        $this->manager->method('rejectRequest')->willReturn(true);
        $this->notification->expects(self::once())->method('notifyRejected')->with('u2', 'X', 'nope');
        $req = $this->request('POST', '/api/v1/admin/requests/r1/deny', userId: 'u1', body: ['reason' => 'nope']);
        self::assertSame(200, $this->controller->denyRequest($req, ['id' => 'r1'])->statusCode);
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
