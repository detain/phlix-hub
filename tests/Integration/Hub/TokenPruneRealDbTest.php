<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Review hub Minor 42 against a real InnoDB with the real 032/044 schemas and
 * the 051 indexes.
 *
 * The unit twins fake the driver; this test is the semantic proof that the
 * two-arm rewrite prunes EXACTLY the right rows:
 *
 *  - expired-BEYOND-grace rows go (arm A),
 *  - a row expired only INSIDE the grace window survives (the 1-day / 30-day
 *    grace is real, not decorative),
 *  - revoked-but-unexpired rows go (arm B — the arm that previously had NO
 *    index at all),
 *  - fresh active rows survive,
 *  - a row matched by BOTH arms is deleted once, and the second tick is a
 *    clean zero,
 *  - migration 051's indexes actually exist on the migrated schema.
 *
 * Deliberately NOT asserted: EXPLAIN index-choice — at these row counts the
 * optimizer may legitimately prefer a PRIMARY order-scan for
 * `ORDER BY id LIMIT n`, which says nothing about the index's production value.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 */
#[Group('integration')]
final class TokenPruneRealDbTest extends RealDatabaseTestCase
{
    private const USER_ID = '11111111-2222-4333-8444-aaaaaaaaaaaa';
    private const SERVER_ID = '11111111-2222-4333-8444-bbbbbbbbbbbb';

    public function testClientRelayPruneArmsDeleteExactlyTheExpiredAndRevokedRows(): void
    {
        // a: expired 2 days ago (beyond the 1-day grace)   → arm A deletes
        // b: expired 12 h ago  (INSIDE the grace)          → must survive
        // c: revoked 1 min ago, expiry in the future       → arm B deletes
        // d: fresh and active                              → must survive
        // e: expired 2 days ago AND revoked                → arm A deletes (once)
        $this->insertClientToken('aaaaaaaa-0000-4000-8000-00000000000a', -2 * 86400, null);
        $this->insertClientToken('aaaaaaaa-0000-4000-8000-00000000000b', -12 * 3600, null);
        $this->insertClientToken('aaaaaaaa-0000-4000-8000-00000000000c', 3600, -60);
        $this->insertClientToken('aaaaaaaa-0000-4000-8000-00000000000d', 3600, null);
        $this->insertClientToken('aaaaaaaa-0000-4000-8000-00000000000e', -2 * 86400, -60);

        $service = new ClientRelayTokenService($this->db);

        self::assertSame(3, $service->pruneExpiredTokens(), 'arms A+B must delete a, c, e — once each');
        self::assertSame([
            'aaaaaaaa-0000-4000-8000-00000000000b',
            'aaaaaaaa-0000-4000-8000-00000000000d',
        ], $this->remainingClientTokenIds());

        self::assertSame(0, $service->pruneExpiredTokens(), 'second tick must be a clean zero');
    }

    public function testMcpPruneArmsHonourTheThirtyDayGrace(): void
    {
        $this->insertMcpToken('bbbbbbbb-0000-4000-8000-00000000000a', -31 * 86400, null);
        $this->insertMcpToken('bbbbbbbb-0000-4000-8000-00000000000b', -29 * 86400, null);
        $this->insertMcpToken('bbbbbbbb-0000-4000-8000-00000000000c', 86400, -3600);

        $service = new McpTokenService($this->db);

        self::assertSame(
            2,
            $service->pruneExpiredTokens(),
            'a (31-day-expired) and c (revoked) go; b (29-day) keeps its grace',
        );
        self::assertSame(['bbbbbbbb-0000-4000-8000-00000000000b'], $this->remainingMcpTokenIds());
    }

    public function testMigration051CreatesBothRevokedAtIndexes(): void
    {
        foreach (
            [
                'client_relay_tokens' => 'idx_client_relay_tokens_revoked_at',
                'mcp_tokens' => 'idx_mcp_tokens_revoked_at',
            ] as $table => $index
        ) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->db->query(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
                ['t' => $table, 'i' => $index],
            );
            self::assertNotEmpty($rows, "{$table} must carry {$index} after migration 051");
        }
    }

    private function insertClientToken(string $id, int $expiresOffset, ?int $revokedOffset): void
    {
        // Two statement shapes because the vendored workerman/mysql driver
        // maps named params POSITIONALLY: a placeholder name may appear only
        // once per SQL (a CASE-reuse of the same name dies HY093).
        $params = [
            'id' => $id,
            'hash' => hash('sha256', 'prune-test-' . $id),
            'uid' => self::USER_ID,
            'sid' => self::SERVER_ID,
            'exp' => $expiresOffset,
        ];

        if ($revokedOffset === null) {
            $this->db->query(
                'INSERT INTO client_relay_tokens (id, token_hash, user_id, server_id, expires_at)
                 VALUES (:id, :hash, :uid, :sid, NOW() + INTERVAL :exp SECOND)',
                $params,
            );
            return;
        }

        $this->db->query(
            'INSERT INTO client_relay_tokens (id, token_hash, user_id, server_id, expires_at, revoked_at)
             VALUES (:id, :hash, :uid, :sid, NOW() + INTERVAL :exp SECOND, NOW() + INTERVAL :revoked SECOND)',
            $params + ['revoked' => $revokedOffset],
        );
    }

    /**
     * @return list<string>
     */
    private function remainingClientTokenIds(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query('SELECT id FROM client_relay_tokens ORDER BY id');
        return self::idColumn($rows);
    }

    private function insertMcpToken(string $id, int $expiresOffset, ?int $revokedOffset): void
    {
        // Shape-split law as in insertClientToken() (positional named params).
        $params = [
            'id' => $id,
            'hash' => hash('sha256', 'prune-test-' . $id),
            'uid' => self::USER_ID,
            'exp' => $expiresOffset,
        ];

        if ($revokedOffset === null) {
            $this->db->query(
                'INSERT INTO mcp_tokens (id, token_hash, user_id, expires_at)
                 VALUES (:id, :hash, :uid, NOW() + INTERVAL :exp SECOND)',
                $params,
            );
            return;
        }

        $this->db->query(
            'INSERT INTO mcp_tokens (id, token_hash, user_id, expires_at, revoked_at)
             VALUES (:id, :hash, :uid, NOW() + INTERVAL :exp SECOND, NOW() + INTERVAL :revoked SECOND)',
            $params + ['revoked' => $revokedOffset],
        );
    }

    /**
     * @return list<string>
     */
    private function remainingMcpTokenIds(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query('SELECT id FROM mcp_tokens ORDER BY id');
        return self::idColumn($rows);
    }

    /**
     * Boundary narrowing (hub test doctrine: no mixed casts): an `id` column
     * that is not a string is a schema break — fail loud here instead of
     * coercing garbage into the comparison.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function idColumn(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $raw = $row['id'];
            if (!is_string($raw)) {
                throw new \RuntimeException('token id column must be a string, got: ' . gettype($raw));
            }
            $ids[] = $raw;
        }

        return $ids;
    }
}
