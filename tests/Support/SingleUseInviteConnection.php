<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PDO;
use Workerman\MySQL\Connection;

/**
 * Test double for {@see Connection} that models the atomic single-use semantics
 * of the `invite_links` redemption UPDATE.
 *
 * The B5 fix replaces the old check-then-act redemption (read `use_count`, then
 * a separate `UPDATE … use_count + 1`) with ONE conditional UPDATE:
 *
 * ```sql
 * UPDATE invite_links SET use_count = use_count + 1
 *  WHERE token_hash = :token_hash
 *    AND use_count < max_uses
 *    AND (expires_at IS NULL OR expires_at > :now)
 * ```
 *
 * A real database evaluates that WHERE atomically per row, so of N concurrent
 * redemptions exactly `max_uses - use_count` of them affect a row and the rest
 * affect zero. This double replays that rule in process: it owns the invite's
 * mutable `use_count`/`max_uses`/`expires_at` and, on each matching UPDATE,
 * either increments and returns `1` (a row was affected) or returns `0` (the
 * predicate failed — exhausted/expired). SELECTs return the current row snapshot
 * so the handler's pre-checks see live state.
 *
 * Every issued query is recorded in {@see $calls} for post-hoc assertions (e.g.
 * that exactly one conditional UPDATE was issued per redemption attempt).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 *   The parent {@see Connection} lazily initialises its query-builder properties
 *   on first use; this double overrides {@see query()} and never touches them,
 *   so it deliberately skips `parent::__construct()` (no socket is opened).
 */
final class SingleUseInviteConnection extends Connection
{
    /**
     * Every query issued against this double, in order.
     *
     * @var list<array{sql: string, params: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param int      $maxUses    The invite's `max_uses` column value.
     * @param int      $useCount   The invite's starting `use_count` column value.
     * @param int|null $expiresAt  The invite's `expires_at` column (UNIX ts) or null.
     * @param string   $ownerId    The invite's `owner_user_id` column value.
     * @param string   $serverId   The invite's `server_id` column value.
     * @param string|null $libraryId The invite's `library_id` column value or null.
     * @param string   $permission The invite's `permission` column value.
     * @param bool     $exists     Whether the token_hash SELECT finds a row.
     * @param string   $userEmail  Email returned for the redeemer user lookup.
     */
    public function __construct(
        private int $maxUses = 1,
        private int $useCount = 0,
        private readonly ?int $expiresAt = null,
        private readonly string $ownerId = 'owner-1',
        private readonly string $serverId = 'server-1',
        private readonly ?string $libraryId = 'lib-1',
        private readonly string $permission = 'read',
        private readonly bool $exists = true,
        private readonly string $userEmail = 'redeemer@example.com',
    ) {
        // Intentionally no parent::__construct(): this double never opens a real
        // MySQL socket — it only models the single-use redemption semantics.
    }

    /** Current persisted use_count (for assertions). */
    public function useCount(): int
    {
        return $this->useCount;
    }

    /**
     * Replay the invite_links query semantics.
     *
     * @param array<int|string, mixed>|string|null $params
     * @param int                                  $fetchmode
     *
     * @return list<array<string, mixed>>|int
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $sql = (string) $query;
        /** @var array<string, mixed> $bind */
        $bind = is_array($params) ? $params : [];
        $this->calls[] = ['sql' => $sql, 'params' => $bind];

        // Conditional single-use UPDATE: increment iff predicate holds, returning
        // affected-row count exactly as workerman's Connection::query() does.
        if (str_contains($sql, 'UPDATE invite_links') && str_contains($sql, 'use_count < max_uses')) {
            $now = is_numeric($bind['now'] ?? null) ? (int) $bind['now'] : time();
            $notExpired = $this->expiresAt === null || $this->expiresAt > $now;
            if ($this->useCount < $this->maxUses && $notExpired) {
                $this->useCount++;
                return 1;
            }
            return 0;
        }

        if (str_contains($sql, 'SELECT * FROM invite_links')) {
            if (!$this->exists) {
                return [];
            }
            return [$this->currentRow()];
        }

        if (str_contains($sql, 'FROM servers')) {
            return [['server_name' => 'Test Server']];
        }

        if (str_contains($sql, 'FROM library_shares')) {
            return [['library_name' => 'Test Library']];
        }

        if (str_contains($sql, 'FROM users')) {
            return [['email' => $this->userEmail]];
        }

        return [];
    }

    /**
     * Snapshot of the invite_links row as the handler's SELECT would see it.
     *
     * @return array<string, mixed>
     */
    private function currentRow(): array
    {
        return [
            'id' => 'link-1',
            'owner_user_id' => $this->ownerId,
            'server_id' => $this->serverId,
            'library_id' => $this->libraryId,
            'permission' => $this->permission,
            'max_uses' => $this->maxUses,
            'use_count' => $this->useCount,
            'expires_at' => $this->expiresAt,
            'created_at' => time(),
            'token_hash' => 'hash-1',
        ];
    }
}
