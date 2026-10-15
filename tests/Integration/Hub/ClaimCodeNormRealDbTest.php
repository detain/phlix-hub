<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\MySQL\Connection;

/**
 * R19-01 live proof for the `claim_code_norm` generated column (migration 048)
 * against the REAL MySQL venue.
 *
 * The defect was invisible to mocks by construction: `REPLACE(claim_code,'-','')
 * = ? FOR UPDATE` returns the same rows as `claim_code_norm = ? FOR UPDATE` —
 * only InnoDB knows the first one full-scans and next-key-locks the whole
 * table. These cases pin what only a live engine can answer:
 *
 *  1. the schema: a STORED generated column + its UNIQUE index, derived by
 *     MySQL itself (no application-side double-write);
 *  2. the plan: the handler's exact locking predicate is a `const` access on
 *     `uk_server_claims_code_norm` — a single-row lock, not a scan;
 *  3. the derivation + collision law: a dashed row and an undashed twin of the
 *     same code are rejected with 1062 on the new key (the display-form UNIQUE
 *     alone let them coexist);
 *  4. the end-to-end pairing: handleClaimCode() through the real schema, from
 *     lenient lowercase input to a claimed row and an issued enrollment JWT.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 * @group integration
 */
final class ClaimCodeNormRealDbTest extends RealDatabaseTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claimnorm-int-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testGeneratedColumnAndUniqueIndexExistWithTheContractedShape(): void
    {
        /** @var list<array<string, mixed>> $column */
        $column = $this->db->query(
            'SELECT EXTRA, GENERATION_EXPRESSION, CHARACTER_MAXIMUM_LENGTH, DATA_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t1 AND COLUMN_NAME = :c',
            ['t1' => 'server_claims', 'c' => 'claim_code_norm'],
        );

        self::assertCount(1, $column);
        self::assertSame('char', self::stringField($column[0], 'DATA_TYPE'));
        self::assertSame(8, self::intField($column[0], 'CHARACTER_MAXIMUM_LENGTH'));
        self::assertStringContainsString('STORED GENERATED', self::stringField($column[0], 'EXTRA'));
        self::assertStringContainsString('replace', strtolower(self::stringField($column[0], 'GENERATION_EXPRESSION')));
        self::assertStringContainsString('claim_code', self::stringField($column[0], 'GENERATION_EXPRESSION'));

        /** @var list<array<string, mixed>> $index */
        $index = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => 'server_claims', 'c' => 'claim_code_norm'],
        );

        self::assertCount(1, $index, 'exactly one index covers claim_code_norm');
        self::assertSame('uk_server_claims_code_norm', self::stringField($index[0], 'INDEX_NAME'));
        // A non-unique imposter under any name keeps the dup-mint hole open.
        self::assertSame(0, self::intField($index[0], 'NON_UNIQUE'));
    }

    public function testHandlerLockingPredicateIsAConstAccessOnTheUniqueIndex(): void
    {
        // The EXPLAIN of the handler's own statement (dev-box evidence from the
        // lane, promoted to a permanent pin): `type=const` on the unique norm
        // key is the whole point of migration 048. Anything else (ALL / range
        // over the old functional predicate) means the lock storm is back.
        //
        // A row must exist: on an EMPTY InnoDB table the optimiser collapses
        // the unique lookup before index selection and reports key=NULL —
        // the dev-box probe that shaped this pin had data in it.
        $this->insertClaimRow('claim-norm-explain', 'DR4Q-7AXB');
        //
        // Read through a plain PDO, NOT the shared workerman Connection: its
        // query() reports rowCount (null) for EXPLAIN result sets — vendored
        // behaviour, so the plan probe needs the native driver. Same DSN and
        // credentials the harness connection uses.
        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) getenv('HUB_TEST_DB_HOST'),
                (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
                (string) getenv('HUB_TEST_DB_NAME'),
            ),
            (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
        );
        $statement = $pdo->query(
            "EXPLAIN SELECT * FROM server_claims WHERE claim_code_norm = 'DR4Q7AXB' FOR UPDATE",
        );
        self::assertNotFalse($statement);
        /** @var list<array<string, mixed>> $plan */
        $plan = $statement->fetchAll(\PDO::FETCH_ASSOC);

        self::assertNotEmpty($plan);
        self::assertSame('uk_server_claims_code_norm', self::stringField($plan[0], 'key'));
        self::assertSame('const', self::stringField($plan[0], 'type'));
    }

    public function testMySQLDerivesTheNormValueAndRejectsDisplayVariantDuplicates(): void
    {
        $this->insertClaimRow('claim-norm-a', 'BBBB-3333');

        /** @var list<array<string, mixed>> $derived */
        $derived = $this->db->query(
            'SELECT claim_code_norm FROM server_claims WHERE id = :id',
            ['id' => 'claim-norm-a'],
        );
        // MySQL derives the de-dashed value at INSERT (no app-side write).
        self::assertSame('BBBB3333', self::stringField($derived[0], 'claim_code_norm'));

        // The OLD unique key (claim_code, display form) would happily accept
        // this row; the norm key must reject it with 1062 on its own name.
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('uk_server_claims_code_norm');
        $this->insertClaimRow('claim-norm-b', 'BBBB3333');
    }

    public function testHandleClaimCodePairsThroughTheRealSchema(): void
    {
        $userId = $this->insertUser();
        $this->insertClaimRow('claim-norm-flow', 'DR4Q-7AXB');

        $handler = new ClaimRequestHandler(
            $this->db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
            $this->createAuditStub(),
            'https://hub.example.com',
        );

        // Lenient display input (lowercase, wrong spacing) must pair the row —
        // proving the generated column + PAD SPACE/CI collation round-trip.
        $result = $handler->handleClaimCode(' dr4q-7axb ', $userId);

        self::assertArrayHasKey('enrollment_jwt', $result);
        self::assertSame(2, substr_count((string) $result['enrollment_jwt'], '.'));

        /** @var list<array<string, mixed>> $row */
        $row = $this->db->query(
            'SELECT claimed_by, status FROM server_claims WHERE id = :id',
            ['id' => 'claim-norm-flow'],
        );
        self::assertSame($userId, self::stringField($row[0], 'claimed_by'));
        self::assertSame('paired', self::stringField($row[0], 'status'));

        // R19-04 rides the same INSERT: servers.last_seen_at must now carry the
        // DB clock — within a few seconds of MySQL NOW(), not a PHP-formatted
        // value (on a co-located box both clocks agree; the law is the column
        // was written by the server, and TIMESTAMPDIFF proves the row is fresh).
        /** @var list<array<string, mixed>> $server */
        $server = $this->db->query(
            'SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS drift,'
                . ' TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age'
                . ' FROM servers WHERE id = :id',
            ['id' => (string) $result['server_id']],
        );
        self::assertLessThan(5, abs(self::intField($server[0], 'drift')));
        self::assertLessThan(5, abs(self::intField($server[0], 'age')));
    }

    /**
     * Typed boundary parse for a string column off a raw DB row (estate
     * precedent: phlix-plugin-trakt d477b85 — PHPStan level 9 hands every
     * driver value as `mixed`; parsing once at the boundary keeps the
     * assertions themselves cast-free and trusted).
     *
     * Scalars are normalised exactly as the previous `(string)` casts did;
     * anything else (null/array/object) fails LOUD with the column name —
     * a NULL here would mean the very schema contract under test is broken,
     * which must never coerce silently into a green run.
     *
     * @param array<string, mixed> $row
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
     * Typed boundary parse for an integer column off a raw DB row. MySQL
     * delivers numerics as strings under emulated prepares (and ints under
     * native ones), so both arrive; anything non-numeric fails loud for the
     * same reason {@see self::stringField()} rejects non-scalars.
     *
     * @param array<string, mixed> $row
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

    private function insertClaimRow(string $id, string $claimCode): void
    {
        $this->db->query(
            'INSERT INTO server_claims
                (id, claim_code, server_name, version, jwks_json, hostname_candidates_json,
                 public_key_jwk, protocol_version, expires_at, created_at)
             VALUES (:id, :claim_code, :server_name, :version, :jwks, :hosts, :jwk, :pv, :exp, :now)',
            [
                'id' => $id,
                'claim_code' => $claimCode,
                'server_name' => 'norm-flow',
                'version' => '0.12.0',
                'jwks' => '{}',
                'hosts' => '[]',
                'jwk' => json_encode(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'aaa'], JSON_THROW_ON_ERROR),
                'pv' => 'v1',
                'exp' => time() + 600,
                'now' => time(),
            ],
        );
    }

    private function insertUser(): string
    {
        $id = $this->uuid();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (:id, :u, :e, :p)',
            [
                'id' => $id,
                'u' => 'norm-' . substr($id, 0, 8),
                'e' => 'norm-' . substr($id, 0, 8) . '@example.com',
                'p' => password_hash('irrelevant', PASSWORD_ARGON2ID),
            ],
        );

        return $id;
    }

    /**
     * Audit logger that tolerates every call — this suite is about the DB
     * contract, and the plaintext-scrub law is pinned in the unit venue.
     */
    private function createAuditStub(): AuditLogger&MockObject
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')
            ->willReturnCallback(static function (string $reason, array $context = []): void {
            });

        return $audit;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
