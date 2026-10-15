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
        $row = $this->pdo()->query(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE"
                . " FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invite_links'"
                . " AND COLUMN_NAME = 'token_hash'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('char', strtolower((string) $row['DATA_TYPE']));
        self::assertSame(64, (int) $row['CHARACTER_MAXIMUM_LENGTH']);
        self::assertSame('NO', $row['IS_NULLABLE'], 'token_hash must stay NOT NULL for the unique key');
    }

    public function testUniqueIndexOnTokenHashAloneAndNoPlainIndexRemains(): void
    {
        $rows = $this->pdo()->query(
            "SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols"
                . " FROM information_schema.STATISTICS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invite_links'"
                . " GROUP BY INDEX_NAME, NON_UNIQUE"
        )->fetchAll(PDO::FETCH_ASSOC);

        $unique = [];
        $plain = [];
        foreach ($rows as $row) {
            $shape = strtolower((string) $row['cols']);
            if ($shape === 'token_hash') {
                if ((int) $row['NON_UNIQUE'] === 0) {
                    $unique[] = (string) $row['INDEX_NAME'];
                } else {
                    $plain[] = (string) $row['INDEX_NAME'];
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

        $rows = $this->db->query(
            'SELECT token_hash, max_uses FROM invite_links WHERE id = :id',
            ['id' => $invite->id],
        );
        self::assertCount(1, $rows);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $rows[0]['token_hash']);
        self::assertSame(3, (int) $rows[0]['max_uses']);
        self::assertSame(
            hash('sha256', (string) $invite->token),
            (string) $rows[0]['token_hash'],
            'the stored hash must derive from the opaque token the URL carries',
        );
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
