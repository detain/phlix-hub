<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Requests;

use Closure;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Tests\Support\StubArrTransport;
use Phlix\Shared\Arr\ArrClientFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * W5 enforcement tests for `requests.auto_approve` at
 * {@see RequestManager::createRequest()}.
 *
 * The law under test: the row ALWAYS lands 'pending' first and auto-approve
 * rides the existing claim/arr/revert machinery on top — best-effort, never
 * failing the create. With no resolver wired the statement set is byte-for-byte
 * the pre-W5 pair (INSERT + re-read).
 *
 * @package Phlix\Hub\Tests\Unit\Requests
 */
final class RequestManagerAutoApproveTest extends TestCase
{
    private Connection&MockObject $db;

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->statements = [];
        $this->db         = $this->createMock(Connection::class);
    }

    /**
     * @param array<string, mixed> $overrides Row fields for the stored request.
     * @param callable(string, array<string, mixed>, int): mixed|null $extra
     */
    private function scriptDb(array $overrides = [], ?callable $extra = null): void
    {
        $selects = 0;
        $this->db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$selects, $overrides, $extra) {
                $this->statements[] = ['sql' => $sql, 'params' => (array) $params];
                $index = count($this->statements);
                if ($extra !== null) {
                    $verdict = $extra($sql, (array) $params, $index);
                    if ($verdict !== null) {
                        return $verdict;
                    }
                }
                if (str_contains($sql, 'INSERT INTO requests')) {
                    return [];
                }
                if (str_contains($sql, 'SELECT * FROM requests WHERE id')) {
                    $selects++;
                    // Status flips to approved once the claim UPDATE has run.
                    $claimed = $this->saw('UPDATE requests SET status = :to', 'approved');

                    return [self::row($overrides + ['status' => $claimed && $selects >= 3 ? 'approved' : 'pending'])];
                }
                if (str_contains($sql, 'UPDATE requests SET status = :to')) {
                    return 1;
                }

                return [];
            },
        );
    }

    private function saw(string $sqlNeedle, string $withTo): bool
    {
        foreach ($this->statements as $entry) {
            if (str_contains($entry['sql'], $sqlNeedle) && ($entry['params']['to'] ?? null) === $withTo) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides): array
    {
        return array_merge([
            'id'               => 'req-1',
            'user_id'          => 'user-1',
            'type'             => 'movie',
            'tmdb_id'          => 555,
            'title'            => 'Auto Movie',
            'poster_url'       => null,
            'season'           => null,
            'episode'          => null,
            'status'           => 'pending',
            'rejection_reason' => null,
            'created_at'       => '2026-01-01 00:00:00',
            'updated_at'       => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function manager(
        ?callable $autoApproveResolver,
        ?callable $arrConfigResolver = null,
        ?ArrClientFactory $factory = null,
        ?StructuredLogger $logger = null,
    ): RequestManager {
        return new RequestManager(
            $this->db,
            $factory ?? new ArrClientFactory([]),
            $logger ?? $this->createMock(StructuredLogger::class),
            $autoApproveResolver,
            $arrConfigResolver,
        );
    }

    /**
     * @return Closure(string): bool
     */
    private static function messageIs(string $expected): Closure
    {
        return static fn (string $message): bool => $message === $expected;
    }

    private function statementCount(): int
    {
        return count($this->statements);
    }

    // ------------------------------------------------------------- defaults

    public function testNoResolverKeepsThePreW5StatementPair(): void
    {
        $this->scriptDb([]);

        $result = $this->manager(null)->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertSame(2, $this->statementCount(), 'INSERT + re-read only — approve machinery untouched');
        self::assertSame('pending', $result['status']);
    }

    public function testResolverFalseKeepsThePreW5StatementPair(): void
    {
        $this->scriptDb([]);

        $result = $this->manager(static fn (): bool => false)->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertSame(2, $this->statementCount());
        self::assertSame('pending', $result['status']);
    }

    // -------------------------------------------------------- approve flows

    public function testResolverTrueApprovesAndResponseCarriesApprovedRow(): void
    {
        $this->scriptDb([]);
        $transport = new StubArrTransport();
        $factory   = new ArrClientFactory(
            ['radarr' => ['enabled' => true, 'url' => 'http://stub.local', 'api_key' => 'k']],
            $transport,
        );

        $result = $this->manager(static fn (): bool => true, factory: $factory)
            ->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertTrue($transport->addMovieCalled(), 'approve rode the real arr path');
        self::assertSame('approved', $result['status'], 're-read makes the create response honest');

        // Statement set: INSERT, re-read(pending), getRequestById, claim UPDATE, final re-read.
        self::assertSame(5, $this->statementCount());
    }

    public function testApproveFailureLeavesRowPendingAndNeverThrows(): void
    {
        // Arr disabled at boot factory: claim lands, arr add can't run, revert
        // releases the claim, create still succeeds with the pending row.
        $this->scriptDb([]);
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(self::messageIs('Cannot approve movie request: Radarr not configured')));

        $result = $this->manager(static fn (): bool => true, logger: $logger)
            ->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertSame('pending', $result['status']);

        $transitions = array_values(array_filter(
            $this->statements,
            static fn (array $e): bool => str_contains($e['sql'], 'UPDATE requests SET status = :to'),
        ));
        self::assertCount(2, $transitions, 'claim pending->approved then revert approved->pending');
        self::assertSame('approved', $transitions[0]['params']['to']);
        self::assertSame('pending', $transitions[1]['params']['to']);
    }

    public function testApprovalThrowingMidFlightStillCreates(): void
    {
        // The claim UPDATE explodes: approveRequest propagates, createRequest's
        // catch keeps the create honest — pending row + warning.
        $this->scriptDb([], static function (string $sql): mixed {
            if (str_contains($sql, 'UPDATE requests SET status = :to')) {
                throw new RuntimeException('dead lock');
            }

            return null;
        });

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(self::messageIs('Auto-approve failed; request stays pending')));

        $result = $this->manager(static fn (): bool => true, logger: $logger)
            ->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertSame('pending', $result['status']);
    }

    // ------------------------------------------------------- arr override

    public function testArrConfigResolverGovernsTheApproval(): void
    {
        // Boot factory WOULD approve (stub transport, radarr enabled); the
        // effective resolver answers 'disabled' — proving the live override,
        // not the boot config, drives this approval.
        $this->scriptDb([]);
        $transport = new StubArrTransport();
        $factory   = new ArrClientFactory(
            ['radarr' => ['enabled' => true, 'url' => 'http://stub.local', 'api_key' => 'k']],
            $transport,
        );

        $resolverCalls = 0;
        $arrResolver   = static function () use (&$resolverCalls): array {
            $resolverCalls++;

            return ['radarr' => ['enabled' => false, 'url' => 'http://stub.local', 'api_key' => 'k']];
        };

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(self::messageIs('Cannot approve movie request: Radarr not configured')));

        $result = $this->manager(static fn (): bool => true, $arrResolver, $factory, $logger)
            ->createRequest('user-1', 'movie', 555, 'Auto Movie');

        self::assertGreaterThan(0, $resolverCalls, 'effective arr config read per approval');
        self::assertFalse($transport->addMovieCalled(), 'resolver-disabled arr must not be dialed');
        self::assertSame('pending', $result['status']);
    }

    public function testAutoApproveResolverIsLivePerCreate(): void
    {
        $this->scriptDb([]);

        $calls = 0;
        $manager = $this->manager(static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        });

        $manager->createRequest('user-1', 'movie', 555, 'First');
        self::assertSame(2, $this->statementCount(), 'first create: flag off, pre-W5 pair');

        $manager->createRequest('user-1', 'movie', 556, 'Second');
        self::assertGreaterThan(4, $this->statementCount(), 'second create observes the flipped flag immediately');
    }
}
