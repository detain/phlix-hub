<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use PDO;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Migration 049 against the REAL MySQL venue (R19-08).
 *
 * Pins the shipped shape of `invite_links.token_hash`:
 *  - CHAR(64) NOT NULL (exactly one SHA-256 hex digest fits);
 *  - a UNIQUE index over precisely the token_hash column, which is what makes
 *    the atomic-claim UPDATE (`WHERE token_hash = ? AND use_count < max_uses`)
 *    unable to fan out over duplicate-hash rows and over-consume uses;
 *  - the old plain (non-unique) single-column index is GONE — if it survived,
 *    the planner could still pick the non-unique access path.
 *
 * Also proves live that a second row with the same token_hash is rejected with
 * errno 1062 naming the new key, and that the production creation path still
 * round-trips through the narrowed column.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set.
 *
 * @group integration
 */
final class InviteTokenHashUniqueRealDbTest extends RealDatabaseTestCase
{
    public function testTokenHashColumnIsChar64NotNull(): void
    {
        // PDO::query() yields PDOStatement|false and fetch() yields mixed —
        // both are parsed at this boundary (estate precedent: phlix-plugin-trakt
        // d477b85 typed boundary parsers) so every assertion below reads a
        // trusted scalar instead of re-casting driver output.
        $statement = $this->pdo()->query(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE"
                . " FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invite_links'"
                . " AND COLUMN_NAME = 'token_hash'",
        );
        self::assertNotFalse($statement, 'the information_schema probe must execute');

        $row = self::parseRow($statement->fetch(PDO::FETCH_ASSOC));
        self::assertSame('char', strtolower(self::stringField($row, 'DATA_TYPE')));
        self::assertSame(64, self::intField($row, 'CHARACTER_MAXIMUM_LENGTH'));
        self::assertSame(
            'NO',
            self::stringField($row, 'IS_NULLABLE'),
            'token_hash must stay NOT NULL for the unique key',
        );
    }

    public function testUniqueIndexOnTokenHashAloneAndNoPlainIndexRemains(): void
    {
        $statement = $this->pdo()->query(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols"
                . " FROM information_schema.STATISTICS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invite_links'"
                . " GROUP BY INDEX_NAME, NON_UNIQUE",
        );
        self::assertNotFalse($statement, 'the STATISTICS probe must execute');

        $rows = self::parseRows($statement->fetchAll(PDO::FETCH_ASSOC));

        $unique = [];
        $plain = [];
        foreach ($rows as $row) {
            $shape = strtolower(self::stringField($row, 'cols'));
            if ($shape === 'token_hash') {
                if (self::intField($row, 'NON_UNIQUE') === 0) {
                    $unique[] = self::stringField($row, 'INDEX_NAME');
                } else {
                    $plain[] = self::stringField($row, 'INDEX_NAME');
                }
            }
        }

        self::assertSame(['uk_invite_links_token_hash'], $unique);
        self::assertSame([], $plain, 'the pre-049 plain token_hash index must be dropped');
    }

    public function testDuplicateTokenHashInsertIsRejectedByTheUniqueKey(): void
    {
        $userId = $this->insertUser();
        $serverId = $this->insertServer($userId);
        $hash = hash('sha256', 'collision-token');

        $this->insertInviteRow('inv-dup-a', $userId, $serverId, $hash);

        try {
            $this->insertInviteRow('inv-dup-b', $userId, $serverId, $hash);
        } catch (\Throwable $throwable) {
            $message = $throwable->getMessage();
            self::assertStringContainsString('1062', $message);
            self::assertStringContainsString('uk_invite_links_token_hash', $message);

            return;
        }

        self::fail('a duplicate token_hash row must violate uk_invite_links_token_hash');
    }

    public function testCreateInviteLinkRoundTripsThroughTheNarrowedColumn(): void
    {
        $userId = $this->insertUser();
        $serverId = $this->insertServer($userId);

        /** @var LibrarySharingHandler&MockObject $sharing */
        $sharing = $this->createMock(LibrarySharingHandler::class);
        $sharing->method('isServerOwnedByUser')->willReturn(true);

        $handler = new InviteLinkHandler(
            $this->db,
            $sharing,
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
            'https://hub.example.com',
        );

        $invite = $handler->createInviteLink($userId, $serverId, null, 'read', 3, null);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT token_hash, max_uses FROM invite_links WHERE id = :id',
            ['id' => $invite->id],
        );
        self::assertCount(1, $rows);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', self::stringField($rows[0], 'token_hash'));
        self::assertSame(3, self::intField($rows[0], 'max_uses'));
        self::assertSame(
            hash('sha256', (string) $invite->token),
            self::stringField($rows[0], 'token_hash'),
            'the stored hash must derive from the opaque token the URL carries',
        );
    }

    /**
     * Parse one raw PDO fetch result into a trusted associative row.
     *
     * PDOStatement::fetch() is `mixed` to PHPStan (and literally `false` when
     * the result set is empty) — a missing row here means the schema object
     * under test is absent, which must fail LOUD by name, never coerce.
     *
     * @return array<array-key, mixed>
     */
    private static function parseRow(mixed $row): array
    {
        if (!is_array($row)) {
            throw new \RuntimeException(
                'Expected one associative row from PDO, got ' . get_debug_type($row)
                . ' — the schema object this test pins is missing.',
            );
        }

        return $row;
    }

    /**
     * Parse a raw PDO fetchAll result into trusted associative rows.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function parseRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new \RuntimeException(
                'Expected a result set from PDO, got ' . get_debug_type($rows),
            );
        }

        $parsed = [];
        foreach ($rows as $row) {
            $parsed[] = self::parseRow($row);
        }

        return $parsed;
    }

    /**
     * Typed boundary parse for a string column off a raw DB row (estate
     * precedent: phlix-plugin-trakt d477b85). Scalars normalise exactly as
     * the previous `(string)` casts did; non-scalars fail loud.
     *
     * @param array<array-key, mixed> $row
     */
    private static function stringField(array $row, string $field): string
    {
        $value = $row[$field];

        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new \RuntimeException(sprintf(
            'Expected a scalar string column %s, got %s.',
            $field,
            get_debug_type($value),
        ));
    }

    /**
     * Typed boundary parse for an integer column off a raw DB row: MySQL
     * delivers numerics as strings under emulated prepares and ints under
     * native ones; anything else fails loud.
     *
     * @param array<array-key, mixed> $row
     */
    private static function intField(array $row, string $field): int
    {
        $value = $row[$field];

        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \RuntimeException(sprintf(
            'Expected a numeric column %s, got %s.',
            $field,
            get_debug_type($value),
        ));
    }

    private function insertInviteRow(string $id, string $userId, string $serverId, string $hash): void
    {
        $this->db->query(
            'INSERT INTO invite_links'
                . ' (id, owner_user_id, server_id, library_id, permission, token_hash,'
                . '  max_uses, use_count, created_at)'
                . ' VALUES'
                . ' (:id, :owner_user_id, :server_id, NULL, :permission, :token_hash, 1, 0, :created_at)',
            [
                'id' => $id,
                'owner_user_id' => $userId,
                'server_id' => $serverId,
                'permission' => 'read',
                'token_hash' => $hash,
                'created_at' => time(),
            ],
        );
    }

    private function insertUser(): string
    {
        $id = $this->uuid();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash)'
                . ' VALUES (:id, :username, :email, :pwd)',
            [
                'id' => $id,
                'username' => 'invite-user-' . substr($id, 0, 8),
                'email' => 'invite-' . substr($id, 0, 8) . '@example.com',
                'pwd' => password_hash('irrelevant', PASSWORD_ARGON2ID),
            ],
        );

        return $id;
    }

    private function insertServer(string $userId): string
    {
        $id = $this->uuid();
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO servers'
                . ' (id, user_id, server_name, version, public_key_jwk, last_seen_at, status,'
                . '  hostname_candidates_json, created_at)'
                . ' VALUES'
                . ' (:id, :user_id, :server_name, :version, :public_key_jwk, :last_seen_at,'
                . '  :status, :hostname_candidates_json, :created_at)',
            [
                'id' => $id,
                'user_id' => $userId,
                'server_name' => 'invite-server',
                'version' => '1.0.0',
                'public_key_jwk' => json_encode([
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '='),
                ], JSON_THROW_ON_ERROR),
                'last_seen_at' => $now,
                'status' => 'online',
                'hostname_candidates_json' => json_encode(['invite.example.com'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ],
        );

        return $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function pdo(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) getenv('HUB_TEST_DB_HOST'),
                (int) getenv('HUB_TEST_DB_PORT'),
                (string) getenv('HUB_TEST_DB_NAME'),
            ),
            (string) getenv('HUB_TEST_DB_USER'),
            (string) getenv('HUB_TEST_DB_PASSWORD') ?: null,
        );
    }
}
