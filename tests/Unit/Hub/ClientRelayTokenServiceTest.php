<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\ClientRelayTokenService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function hash;
use function preg_match;
use function str_contains;
use function strlen;
use function strtoupper;
use function time;

/**
 * Unit tests for {@see ClientRelayTokenService}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ClientRelayTokenServiceTest extends TestCase
{
    public function testMintStoresOnlyAHashNeverThePlaintext(): void
    {
        $db = $this->createMock(Connection::class);

        /** @var array<string, mixed> $captured */
        $captured = [];
        // M-HUB-22: mint now issues the INSERT plus the scope-cap SELECT.
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
                if (str_contains($sql, 'INSERT INTO client_relay_tokens')) {
                    $captured = $params;
                    return [];
                }
                $this->assertStringContainsString('SELECT id FROM client_relay_tokens', $sql);
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $result = $service->mint('user-1', 'srv-1');

        // Plaintext returned to caller once.
        $this->assertArrayHasKey('token', $result);
        $this->assertIsString($result['token']);
        $this->assertNotSame('', $result['token']);

        // What was persisted is the SHA-256 hash, NOT the plaintext.
        $this->assertArrayHasKey('token_hash', $captured);
        $this->assertSame(hash('sha256', $result['token']), $captured['token_hash']);
        $this->assertNotSame($result['token'], $captured['token_hash']);
        $this->assertSame('user-1', $captured['user_id']);
        $this->assertSame('srv-1', $captured['server_id']);
    }

    public function testMintedTokenIsCsprngAndAtLeast128Bits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $service = new ClientRelayTokenService($db);
        $a = $service->mint('user-1', 'srv-1')['token'];
        $b = $service->mint('user-1', 'srv-1')['token'];

        // Hex token of >= 32 chars => >= 128 bits of entropy; lower-case hex.
        $this->assertGreaterThanOrEqual(32, strlen($a));
        $this->assertSame(1, preg_match('/^[0-9a-f]+$/', $a));
        // Two mints never collide.
        $this->assertNotSame($a, $b);
    }

    public function testValidateAcceptsAFreshTokenAndReturnsIdentity(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('FROM client_relay_tokens', $sql);
                $this->assertStringContainsString('revoked_at IS NULL', $sql);
                $this->assertStringContainsString('expires_at > NOW()', $sql);
                $this->assertSame(hash('sha256', 'plain-token'), $params['token_hash']);
                return [['user_id' => 'user-1', 'server_id' => 'srv-1']];
            });

        $service = new ClientRelayTokenService($db);
        $identity = $service->validate('plain-token');

        $this->assertSame(['user_id' => 'user-1', 'server_id' => 'srv-1'], $identity);
    }

    public function testValidateRejectsUnknownExpiredOrRevokedToken(): void
    {
        // The SQL filters out expired/revoked rows, so the DB returns nothing.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $service = new ClientRelayTokenService($db);
        $this->assertNull($service->validate('does-not-exist'));
    }

    public function testValidateRejectsEmptyTokenWithoutQuerying(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $service = new ClientRelayTokenService($db);
        $this->assertNull($service->validate(''));
    }

    public function testRevokeMarksTheTokenRevoked(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('UPDATE client_relay_tokens SET revoked_at = NOW()', $sql);
                $this->assertStringContainsString('revoked_at IS NULL', $sql);
                $this->assertSame(hash('sha256', 'plain-token'), $params['token_hash']);
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $service->revoke('plain-token');
    }

    public function testRevokeEmptyTokenIsANoop(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $service = new ClientRelayTokenService($db);
        $service->revoke('');
    }

    public function testRevokeForUserServerTargetsThePair(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('UPDATE client_relay_tokens SET revoked_at = NOW()', $sql);
                $this->assertSame('user-1', $params['user_id']);
                $this->assertSame('srv-1', $params['server_id']);
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $service->revokeForUserServer('user-1', 'srv-1');
    }

    public function testCustomTtlIsReflectedInExpiry(): void
    {
        $db = $this->createMock(Connection::class);
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
            // M-HUB-22: capture the INSERT params only (the cap SELECT follows).
            if (str_contains($sql, 'INSERT INTO client_relay_tokens')) {
                $captured = $params;
            }
            return [];
        });

        $before = time();
        $service = new ClientRelayTokenService($db, 7200);
        $result = $service->mint('user-1', 'srv-1');

        $this->assertIsInt($result['expires_at']);
        // ~2h ahead (allow a small execution skew).
        $this->assertGreaterThanOrEqual($before + 7200, $result['expires_at']);
        $this->assertLessThanOrEqual($before + 7200 + 5, $result['expires_at']);
        $this->assertSame($result['expires_at'], $captured['expires_at']);
    }

    public function testNonPositiveTtlFallsBackToDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $before = time();
        $service = new ClientRelayTokenService($db, 0);
        $result = $service->mint('user-1', 'srv-1');

        $this->assertGreaterThanOrEqual(
            $before + ClientRelayTokenService::DEFAULT_TTL_SECONDS,
            $result['expires_at'],
        );
    }

    public function testPruneExpiredTokensOrJoinsExpiryAndRevokedPredicates(): void
    {
        $db = $this->createMock(Connection::class);
        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 7;
            },
        );

        $service = new ClientRelayTokenService($db);
        $deleted = $service->pruneExpiredTokens();

        $this->assertSame(7, $deleted);
        $this->assertStringContainsString('DELETE FROM client_relay_tokens', $capturedSql);
        $this->assertStringContainsString('expires_at < NOW() - INTERVAL 1 DAY', $capturedSql);
        $this->assertStringContainsString('revoked_at IS NOT NULL', $capturedSql);

        // H-D2: the expiry and revoked predicates MUST be OR-joined, NOT AND.
        // With AND, only tokens that are BOTH expired-by->1-day AND revoked are
        // pruned, so the common expired-never-revoked row (tokens have a ~1 h TTL
        // and are rarely revoked) is never removed and the table grows forever.
        $matched = preg_match(
            '/INTERVAL\s+1\s+DAY\s+(AND|OR)\s+revoked_at\s+IS\s+NOT\s+NULL/i',
            $capturedSql,
            $operator,
        );
        $this->assertSame(
            1,
            $matched,
            'prune SQL must join the expiry and revoked predicates with a single AND/OR operator',
        );
        $this->assertSame(
            'OR',
            strtoupper($operator[1]),
            'expiry/revoked predicates must be OR-joined, not AND (H-D2)',
        );

        // No params needed for this query.
        $this->assertNull($capturedParams);
    }

    /**
     * Behavioral guard for H-D2: drive pruneExpiredTokens() against a fake DB
     * that interprets the emitted DELETE's WHERE clause over fixture rows, and
     * prove an expired-never-revoked row (revoked_at IS NULL) is removed. Under
     * the old AND bug that row survives — so a regression back to AND fails here.
     */
    public function testPruneDeletesExpiredNeverRevokedRowBehaviorally(): void
    {
        $now = time();
        $day = 86400;

        // expires_at / revoked_at are unix timestamps; revoked_at null = never revoked.
        $rows = [
            // The H-D2 growth case: simply expired long ago, never revoked.
            'expired_never_revoked' => ['expires_at' => $now - (2 * $day), 'revoked_at' => null],
            // Revoked but not yet expired — OR removes it; AND would keep it.
            'revoked_not_expired'   => ['expires_at' => $now + 3600,        'revoked_at' => $now - 60],
            // Fresh, active, un-revoked — must be kept under either operator.
            'fresh_active'          => ['expires_at' => $now + 3600,        'revoked_at' => null],
            // Both expired and revoked — removed under either operator.
            'expired_and_revoked'   => ['expires_at' => $now - (2 * $day),  'revoked_at' => $now - 120],
        ];

        $db = $this->createMock(Connection::class);
        $survivors = $rows;
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$survivors, $now, $day): int {
                // Extract the operator that joins the two predicates so a
                // regression back to AND is evaluated as AND (and thus caught).
                if (
                    preg_match(
                        '/INTERVAL\s+1\s+DAY\s+(AND|OR)\s+revoked_at\s+IS\s+NOT\s+NULL/i',
                        $sql,
                        $operator,
                    ) !== 1
                ) {
                    self::fail('prune SQL must join the expiry and revoked predicates with a single AND/OR');
                }
                $usesOr    = strtoupper($operator[1]) === 'OR';
                $threshold = $now - $day; // NOW() - INTERVAL 1 DAY

                $deleted = 0;
                $kept    = [];
                foreach ($survivors as $name => $row) {
                    $expiredBeyondGrace = $row['expires_at'] < $threshold;
                    $revoked            = $row['revoked_at'] !== null;
                    $matches            = $usesOr
                        ? ($expiredBeyondGrace || $revoked)
                        : ($expiredBeyondGrace && $revoked);
                    if ($matches) {
                        $deleted++;
                    } else {
                        $kept[$name] = $row;
                    }
                }
                $survivors = $kept;

                return $deleted;
            },
        );

        $service = new ClientRelayTokenService($db);
        $deleted = $service->pruneExpiredTokens();

        // OR semantics: 3 of 4 rows pruned. The fresh active token is the only survivor.
        $this->assertSame(3, $deleted, 'expected 3 of 4 rows pruned under OR semantics');
        $this->assertArrayNotHasKey(
            'expired_never_revoked',
            $survivors,
            'expired-never-revoked token MUST be pruned (the H-D2 unbounded-growth case)',
        );
        $this->assertArrayNotHasKey('revoked_not_expired', $survivors);
        $this->assertArrayNotHasKey('expired_and_revoked', $survivors);
        $this->assertArrayHasKey(
            'fresh_active',
            $survivors,
            'a fresh, un-revoked token must NOT be pruned',
        );
    }

    public function testPruneExpiredTokensReturnsZeroWhenResultNotInt(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $service = new ClientRelayTokenService($db);
        $this->assertSame(0, $service->pruneExpiredTokens());
    }

    /**
     * M-HUB-22: the standing credential population per (user, server) is
     * bounded at mint time. Pin the cap query's shape: active rows only
     * (unrevoked AND unexpired), the just-minted row excluded, newest-first
     * ordering, and the batch/OFFSET arithmetic that keeps exactly
     * MAX_ACTIVE_TOKENS_PER_SCOPE alive.
     */
    public function testMintIssuesScopeCapSelectWithExpectedShape(): void
    {
        $db = $this->createMock(Connection::class);
        $selectSql = null;
        $selectParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$selectSql, &$selectParams): array {
                if (str_contains($sql, 'SELECT id FROM client_relay_tokens')) {
                    $selectSql = $sql;
                    $selectParams = $params;
                }
                return [];
            },
        );

        $service = new ClientRelayTokenService($db);
        $result = $service->mint('user-1', 'srv-1');

        self::assertSame(0, $result['pruned'], 'no overflow rows => nothing revoked');
        self::assertIsString($selectSql);
        self::assertStringContainsString('revoked_at IS NULL', $selectSql);
        self::assertStringContainsString('expires_at > NOW()', $selectSql);
        self::assertStringContainsString('id != :fresh', $selectSql);
        self::assertStringContainsString('ORDER BY created_at DESC, id DESC', $selectSql);
        self::assertStringContainsString(
            'LIMIT 1000 OFFSET ' . (ClientRelayTokenService::MAX_ACTIVE_TOKENS_PER_SCOPE - 1),
            $selectSql,
        );
        self::assertIsArray($selectParams);
        self::assertSame('user-1', $selectParams['uid']);
        self::assertSame('srv-1', $selectParams['sid']);
    }

    /**
     * M-HUB-22: overflow rows are REVOKED (set revoked_at), not deleted, with
     * each id bound individually; the count surfaces in the mint result for
     * the controller's audit trail.
     */
    public function testMintRevokesOverflowRowsAndReportsPrunedCount(): void
    {
        $db = $this->createMock(Connection::class);
        $updateSql = null;
        $updateParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$updateSql, &$updateParams) {
                if (str_contains($sql, 'SELECT id FROM client_relay_tokens')) {
                    return [['id' => 'old-1'], ['id' => 'old-2']];
                }
                if (str_contains($sql, 'UPDATE client_relay_tokens SET revoked_at = NOW()')) {
                    $updateSql = $sql;
                    $updateParams = $params;
                    return 2;
                }
                return [];
            },
        );

        $service = new ClientRelayTokenService($db);
        $result = $service->mint('user-1', 'srv-1');

        self::assertSame(2, $result['pruned']);
        self::assertIsString($updateSql);
        self::assertStringContainsString('WHERE id IN (:id_0, :id_1)', $updateSql);
        self::assertIsArray($updateParams);
        self::assertSame('old-1', $updateParams['id_0']);
        self::assertSame('old-2', $updateParams['id_1']);
    }

    /**
     * M-HUB-22: with no overflow rows the cap issues NO UPDATE — a normal
     * mint must stay at exactly two statements (INSERT + SELECT).
     */
    public function testMintWithoutOverflowIssuesNoRevokeUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $statements = [];
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$statements): array {
                $statements[] = $sql;
                return [];
            },
        );

        $service = new ClientRelayTokenService($db);
        $service->mint('user-1', 'srv-1');

        self::assertCount(2, $statements);
        self::assertStringContainsString('INSERT INTO client_relay_tokens', $statements[0]);
        self::assertStringContainsString('SELECT id FROM client_relay_tokens', $statements[1]);
    }
}
