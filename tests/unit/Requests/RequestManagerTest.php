<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Requests\RequestManager;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Shared\Arr\ArrClientFactory;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RequestManager}.
 *
 * @package Phlex\Hub\Tests\unit\Requests
 * @since 0.6.0
 *
 * @covers \Phlex\Hub\Requests\RequestManager
 */
final class RequestManagerTest extends TestCase
{
    private RequestManager $manager;
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private Connection $db;
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(Connection::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->manager = new RequestManager($this->db, new ArrClientFactory([]), $this->logger);
    }

    public function testCreateRequestStoresPending(): void
    {
        $this->db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'INSERT INTO requests')) {
                    return [];
                }
                return [self::row(['id' => 'test-uuid', 'user_id' => 'user-123', 'type' => 'movie', 'tmdb_id' => 12345, 'title' => 'Test Movie', 'poster_url' => 'https://poster.url'])];
            });

        $result = $this->manager->createRequest('user-123', 'movie', 12345, 'Test Movie', 'https://poster.url');

        self::assertSame('user-123', $result['user_id']);
        self::assertSame('movie', $result['type']);
        self::assertSame(12345, $result['tmdb_id']);
        self::assertSame('Test Movie', $result['title']);
        self::assertSame('pending', $result['status']);
    }

    public function testCreateSeriesRequestWithSeasonAndEpisode(): void
    {
        $this->db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'INSERT INTO requests')) {
                    return [];
                }
                return [self::row(['id' => 'test-uuid', 'user_id' => 'user-456', 'type' => 'series', 'tmdb_id' => 54321, 'title' => 'Test Series', 'season' => 2, 'episode' => 5])];
            });

        $result = $this->manager->createRequest('user-456', 'series', 54321, 'Test Series', null, 2, 5);

        self::assertSame('series', $result['type']);
        self::assertSame(2, $result['season']);
        self::assertSame(5, $result['episode']);
    }

    public function testGetRequestByIdReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);
        self::assertNull($this->manager->getRequestById('nope'));
    }

    public function testGetRequestByIdReturnsHydratedRequest(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'tmdb_id' => 12345])]);
        $result = $this->manager->getRequestById('test-id');
        self::assertIsArray($result);
        self::assertSame('test-id', $result['id']);
        self::assertSame(12345, $result['tmdb_id']);
    }

    public function testRejectRequestSetsStatusToRejected(): void
    {
        $this->db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'SELECT * FROM requests WHERE id')) {
                    return [self::row(['id' => 'test-id', 'status' => 'pending'])];
                }
                return [];
            });

        self::assertTrue($this->manager->rejectRequest('test-id', 'Too controversial'));
    }

    public function testRejectRequestReturnsFalseForNonPending(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'status' => 'approved'])]);
        self::assertFalse($this->manager->rejectRequest('test-id', 'reason'));
    }

    public function testRejectRequestReturnsFalseForNonExistent(): void
    {
        $this->db->method('query')->willReturn([]);
        self::assertFalse($this->manager->rejectRequest('nope', 'reason'));
    }

    public function testGetRequestStatusReturnsCorrectStatus(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'status' => 'approved'])]);
        self::assertSame('approved', $this->manager->getRequestStatus('test-id'));
    }

    public function testGetRequestStatusReturnsUnknownForNonExistent(): void
    {
        $this->db->method('query')->willReturn([]);
        self::assertSame('unknown', $this->manager->getRequestStatus('nope'));
    }

    public function testListPendingRequestsForUser(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains("WHERE user_id = :user_id AND status = 'pending'"),
                ['user_id' => 'user-123'],
            )
            ->willReturn([
                self::row(['id' => 'req-1', 'user_id' => 'user-123', 'tmdb_id' => 111, 'title' => 'Movie 1', 'status' => 'pending']),
                self::row(['id' => 'req-2', 'user_id' => 'user-123', 'type' => 'series', 'tmdb_id' => 222, 'title' => 'Series 1', 'season' => 1, 'status' => 'pending']),
            ]);

        $result = $this->manager->listPendingRequests('user-123');
        self::assertCount(2, $result);
        self::assertSame('Movie 1', $result[0]['title']);
        self::assertSame('Series 1', $result[1]['title']);
    }

    public function testListPendingRequestsAllUsers(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'pending'"))
            ->willReturn([self::row(['id' => 'req-1', 'tmdb_id' => 111, 'title' => 'Movie 1', 'status' => 'pending'])]);
        self::assertCount(1, $this->manager->listPendingRequests());
    }

    public function testListAvailableRequests(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'req-1', 'title' => 'Available', 'status' => 'available'])]);
        $result = $this->manager->listAvailableRequests();
        self::assertCount(1, $result);
        self::assertSame('available', $result[0]['status']);
    }

    public function testListUserRequests(): void
    {
        $this->db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('WHERE user_id = :user_id ORDER BY created_at DESC'),
                ['user_id' => 'user-1'],
            )
            ->willReturn([self::row(['id' => 'r1', 'user_id' => 'user-1'])]);
        $result = $this->manager->listUserRequests('user-1');
        self::assertCount(1, $result);
    }

    public function testDeleteRequest(): void
    {
        $this->db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'SELECT * FROM requests WHERE id')) {
                    return [self::row(['id' => 'test-id', 'status' => 'pending'])];
                }
                return [];
            });
        self::assertTrue($this->manager->deleteRequest('test-id'));
    }

    public function testDeleteRequestReturnsFalseForNonExistent(): void
    {
        $this->db->method('query')->willReturn([]);
        self::assertFalse($this->manager->deleteRequest('nope'));
    }

    public function testApproveMovieReturnsFalseWhenRadarrNotConfigured(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'type' => 'movie', 'status' => 'pending'])]);
        self::assertFalse($this->manager->approveRequest('test-id'));
    }

    public function testApproveSeriesReturnsFalseWhenSonarrNotConfigured(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'type' => 'series', 'status' => 'pending'])]);
        self::assertFalse($this->manager->approveRequest('test-id'));
    }

    public function testApproveRequestReturnsFalseForNonPending(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'status' => 'approved'])]);
        self::assertFalse($this->manager->approveRequest('test-id'));
    }

    public function testMarkAvailable(): void
    {
        $this->db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'SELECT * FROM requests WHERE id')) {
                    return [self::row(['id' => 'test-id', 'status' => 'approved'])];
                }
                return [];
            });
        self::assertTrue($this->manager->markAvailable('test-id'));
    }

    public function testMarkAvailableReturnsFalseForNonApproved(): void
    {
        $this->db->method('query')->willReturn([self::row(['id' => 'test-id', 'status' => 'pending'])]);
        self::assertFalse($this->manager->markAvailable('test-id'));
    }

    /**
     * Build a row with sensible defaults so individual tests stay minimal.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(array $overrides): array
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
