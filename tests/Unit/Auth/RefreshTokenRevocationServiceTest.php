<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use Phlix\Hub\Auth\RefreshTokenRevocationService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Behaviour tests for {@see RefreshTokenRevocationService} — the jti registry
 * behind the auth audit's logout-revocation fix (migration 047, table
 * `auth_revoked_refresh_tokens`).
 *
 * Modelled on {@see \Phlix\Hub\Tests\Unit\Common\RateLimit\DbRateLimiterTest}:
 * a mock {@see Connection} whose `query()` callback models the table's
 * semantics from the SQL keywords, so the tests pin behaviour (idempotency,
 * the expiry cutoff, the prune grace window) and not just query strings.
 * Time is injected so the cutoffs advance without sleeping.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 */
final class RefreshTokenRevocationServiceTest extends TestCase
{
    /**
     * @var list<array{sql: string, params: array<string, mixed>|null}> Captured queries.
     */
    private array $log = [];

    /**
     * @var array<string, array{user_id: string, expires_at: int}> In-memory table.
     */
    private array $table = [];

    private int $now = 10_000_000;

    protected function setUp(): void
    {
        $this->log = [];
        $this->table = [];
    }

    public function testRevokeRejectsEmptyJtiWithoutTouchingTheDb(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        self::assertFalse($service->revoke('', 'u-1', $this->now + 3600));
        self::assertSame([], $this->log);
    }

    public function testIsRevokedRejectsEmptyJtiWithoutTouchingTheDb(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        self::assertFalse($service->isRevoked(''));
        self::assertSame([], $this->log);
    }

    public function testRevokePrunesFirstThenInsertsBoundRow(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        self::assertTrue($service->revoke('abc123', 'u-9', $this->now + 7200));

        self::assertCount(2, $this->log, 'revoke() must sweep stale rows before writing');
        self::assertStringContainsString('DELETE FROM auth_revoked_refresh_tokens', $this->log[0]['sql']);

        $insert = $this->log[1];
        self::assertStringContainsString('INSERT IGNORE INTO auth_revoked_refresh_tokens', $insert['sql']);
        // Never interpolate the values into SQL: expiry travels as a bound
        // unix int rendered by FROM_UNIXTIME.
        self::assertStringContainsString('FROM_UNIXTIME(:expires_at)', $insert['sql']);
        self::assertStringNotContainsString((string) ($this->now + 7200), $insert['sql']);
        self::assertSame(
            ['jti' => 'abc123', 'user_id' => 'u-9', 'expires_at' => $this->now + 7200],
            $insert['params'],
        );
    }

    public function testIsRevokedAnswersForRegisteredJtiOnly(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        self::assertFalse($service->isRevoked('never-seen'));

        $service->revoke('burned-jti', 'u-1', $this->now + 3600);

        self::assertTrue($service->isRevoked('burned-jti'));
        self::assertFalse($service->isRevoked('sibling-jti'));
    }

    public function testRevokeIsIdempotentAndKeepsSingleRow(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        self::assertTrue($service->revoke('twice', 'u-1', $this->now + 3600));
        self::assertTrue(
            $service->revoke('twice', 'u-1', $this->now + 3600),
            're-logout of an already-revoked jti must still report success',
        );

        self::assertCount(1, $this->table);
    }

    /**
     * A revoked row whose token has ALREADY expired is answered `false`: the
     * token is dead either way, and the cutoff is what keeps the table — and
     * every `refresh()` consult — from growing past the tokens it can affect.
     */
    public function testIsRevokedHonoursTheTokenExpiryCutoff(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        // Seed a row directly (bypassing revoke's own pre-prune) that is
        // expired but inside the 1-day prune grace: invisible to isRevoked,
        // still on disk until the sweep.
        $this->table['expired-but-unpruned'] = [
            'user_id'    => 'u-1',
            'expires_at' => $this->now - 60,
        ];

        self::assertFalse($service->isRevoked('expired-but-unpruned'));
    }

    public function testPruneExpiredDeletesOnlyRowsPastTheGrace(): void
    {
        $service = new RefreshTokenRevocationService($this->makeFakeDb());

        $this->table['ancient']  = ['user_id' => 'u-1', 'expires_at' => $this->now - 86_400 - 10];
        $this->table['recent']   = ['user_id' => 'u-1', 'expires_at' => $this->now - 3_600];
        $this->table['alive']    = ['user_id' => 'u-1', 'expires_at' => $this->now + 3_600];

        self::assertSame(1, $service->pruneExpired());
        self::assertArrayNotHasKey('ancient', $this->table);
        self::assertArrayHasKey('recent', $this->table, 'the 1-day grace must keep recently-expired rows');
        self::assertArrayHasKey('alive', $this->table);
    }

    // ------------------------------------------------------------------
    // Fake Connection: models `auth_revoked_refresh_tokens` from SQL keywords.
    // ------------------------------------------------------------------

    private function makeFakeDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<string, mixed>|null $params
             * @return list<array<string, mixed>>|int
             */
            function (string $sql, ?array $params = null): array|int {
                $params ??= [];
                /** @var array<string, mixed> $params */
                $this->log[] = ['sql' => $sql, 'params' => $params];

                if (str_starts_with(ltrim($sql), 'DELETE')) {
                    return $this->applyPrune($sql);
                }
                if (str_starts_with(ltrim($sql), 'INSERT')) {
                    return $this->applyInsert($params);
                }
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return $this->applySelect($params);
                }

                $this->fail('unexpected SQL in fake: ' . $sql);
            }
        );

        return $db;
    }

    /**
     * Bound-parameter values arrive as `mixed` (exactly what a fake of
     * `Connection::query()` must tolerate); parse them into the table's row
     * shape at this boundary and trust them internally.
     */
    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyInsert(array $params): int
    {
        $jti = self::asString($params['jti'] ?? '');
        if ($jti === '' || isset($this->table[$jti])) {
            return 0; // INSERT IGNORE on an existing PK: affected rows 0.
        }
        $this->table[$jti] = [
            'user_id'    => self::asString($params['user_id'] ?? ''),
            'expires_at' => self::asInt($params['expires_at'] ?? 0),
        ];
        return 1;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{revoked: int}>
     */
    private function applySelect(array $params): array
    {
        $row = $this->table[self::asString($params['jti'] ?? '')] ?? null;
        if ($row === null || $row['expires_at'] <= $this->now) {
            return [];
        }
        return [['revoked' => 1]];
    }

    /**
     * The DELETE literal embeds the grace as an `INTERVAL 86400 SECOND`
     * constant; parse it back out rather than re-hardcoding it, so a service
     * change to the interval is honoured by the fake instead of contradicted.
     */
    private function applyPrune(string $sql): int
    {
        preg_match('/INTERVAL (\d+) SECOND/', $sql, $m);
        $grace = isset($m[1]) ? (int) $m[1] : 0;

        $removed = 0;
        foreach ($this->table as $jti => $row) {
            if ($row['expires_at'] < $this->now - $grace) {
                unset($this->table[$jti]);
                $removed++;
            }
        }
        return $removed;
    }
}
