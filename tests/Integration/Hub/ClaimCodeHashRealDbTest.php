<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Phlix\Shared\Hub\ClaimRequest;
use PDO;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * SH-049 live proof for the `claim_code_hash` regime (migration 052) against
 * the REAL MySQL venue — the successor to the 048-era `ClaimCodeNormRealDbTest`
 * (historical name referenced by CHANGELOG entries kept verbatim).
 *
 * Mocks cannot see what this file pins: whether the engine actually refuses a
 * display-variant twin on the hash key, whether the handler's locking
 * predicate remains a `const` access after the column swap, and whether the
 * end-to-end pair survives with the plaintext physically absent from the
 * schema. Cases:
 *
 *  1. the schema: `claim_code_hash CHAR(64)` ascii_bin with its UNIQUE index,
 *     and the legacy `claim_code` / `claim_code_norm` columns GONE;
 *  2. the plan: the handler's exact locking predicate is a `const` access on
 *     `uk_server_claims_code_hash` — the R19-01 single-row lock survives the
 *     hash swap;
 *  3. the collapse law: two display variants of one code hash identically,
 *     and the UNIQUE key rejects the twin with 1062 on its own name;
 *  4. the end-to-end pairing through the real schema: mint via
 *     handleNewClaim(), claim through a lenient variant of the delivered
 *     code, and the servers row freshness law (R19-04) rides along;
 *  5. M-HUB-12 unchanged: exactly one audit row on success, zero on refusals,
 *     carrying the fingerprint but never the code.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 * @group integration
 */
final class ClaimCodeHashRealDbTest extends RealDatabaseTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claimhash-int-' . uniqid();
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

    public function testHashCodeColumnAndUniqueIndexExistAndLegacyColumnsAreGone(): void
    {
        /** @var list<array<string, mixed>> $column */
        $column = $this->db->query(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t1 AND COLUMN_NAME = :c',
            ['t1' => 'server_claims', 'c' => 'claim_code_hash'],
        );

        self::assertCount(1, $column);
        self::assertSame('char', self::stringField($column[0], 'DATA_TYPE'));
        self::assertSame(64, self::intField($column[0], 'CHARACTER_MAXIMUM_LENGTH'));
        // BINARY semantics: no table collation may ever fold or pad-compare
        // two hashes (the migration header pins this deliberately).
        self::assertSame('ascii', self::stringField($column[0], 'CHARACTER_SET_NAME'));
        self::assertSame('ascii_bin', self::stringField($column[0], 'COLLATION_NAME'));
        // A plain stored column — the app hashes, MySQL never derives.
        self::assertStringNotContainsString('GENERATED', self::stringField($column[0], 'EXTRA'));

        /** @var list<array<string, mixed>> $index */
        $index = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => 'server_claims', 'c' => 'claim_code_hash'],
        );

        self::assertCount(1, $index, 'exactly one index covers claim_code_hash');
        self::assertSame('uk_server_claims_code_hash', self::stringField($index[0], 'INDEX_NAME'));
        // A non-unique imposter under any name keeps the dup-mint hole open.
        self::assertSame(0, self::intField($index[0], 'NON_UNIQUE'));

        /** @var list<array<string, mixed>> $legacy */
        $legacy = $this->db->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                AND COLUMN_NAME IN (:c1, :c2)',
            ['t' => 'server_claims', 'c1' => 'claim_code', 'c2' => 'claim_code_norm'],
        );
        self::assertSame([], $legacy, 'migration 052 must leave no plaintext home behind');
    }

    public function testHandlerLockingPredicateIsAConstAccessOnTheUniqueHashIndex(): void
    {
        // The EXPLAIN of the handler's own statement shape: `type=const` on
        // the unique hash key is the R19-01 law carried across the SH-049
        // column swap. Anything else (ALL / range) means the lock storm is
        // back. A row must exist: on an EMPTY InnoDB table the optimiser
        // collapses the unique lookup before index selection and reports
        // key=NULL — the dev-box probe that shaped this pin had data in it.
        $this->insertClaimRow('claim-hash-explain', 'DR4Q-7AXB');

        // Read through a plain PDO, NOT the shared workerman Connection: its
        // query() reports rowCount (null) for EXPLAIN result sets — vendored
        // behaviour, so the plan probe needs the native driver. Same DSN and
        // credentials the harness connection uses.
        $pdo = new PDO(
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
            "EXPLAIN SELECT * FROM server_claims WHERE claim_code_hash = '"
                . self::hashOf('DR4Q-7AXB') . "' FOR UPDATE",
        );
        self::assertNotFalse($statement);
        /** @var list<array<string, mixed>> $plan */
        $plan = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($plan);
        self::assertSame('uk_server_claims_code_hash', self::stringField($plan[0], 'key'));
        self::assertSame('const', self::stringField($plan[0], 'type'));
    }

    public function testDisplayVariantsCollapseToOneHashAndTheUniqueKeyRejectsTheTwin(): void
    {
        $this->insertClaimRow('claim-hash-a', 'BBBB-3333');

        // The collapse law (app-side, pinned here at the wire boundary): the
        // dashed and undashed forms share ONE hash, which is why lookup stays
        // variant-tolerant without a generated column or collation trick.
        self::assertSame(self::hashOf('BBBB-3333'), self::hashOf('bbbb3333'));

        // The old norm key rejected display twins through MySQL-derived
        // equality; the hash key rejects them the same way, 1062 on its own
        // name — and the second form can only arrive as the SAME hash.
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('uk_server_claims_code_hash');
        $this->insertClaimRow('claim-hash-b', 'BBBB3333');
    }

    public function testSelectingTheLegacyPlaintextColumnIsAnUnknownColumn(): void
    {
        // Fail-loud companion to test 1's information_schema probe: not just
        // "absent from the catalogue" but the engine rejecting any read that
        // still expects plaintext at rest (a surviving code path cannot hide).
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('claim_code');
        $this->db->query('SELECT claim_code FROM server_claims LIMIT 1');
    }

    public function testHandleNewClaimThroughRealSchemaPairsWithLenientVariantInput(): void
    {
        $userId = $this->insertUser();
        $mine = $this->validEd25519Jwk();
        $handler = $this->handler($userId);

        // The mint leg now runs on the REAL table: the INSERT lands the hash.
        $issued = $handler->handleNewClaim(new ClaimRequest(
            serverName: 'Hash Flow',
            version: '0.12.0',
            publicKeysJwk: $mine,
            hostnameCandidates: [],
            protocolVersion: 'v1',
        ));

        /** @var list<array<string, mixed>> $stored */
        $stored = $this->db->query(
            'SELECT claim_code_hash FROM server_claims WHERE id = :id',
            ['id' => $issued->claimId],
        );
        self::assertCount(1, $stored);
        self::assertSame(
            self::hashOf($issued->claimCode),
            self::stringField($stored[0], 'claim_code_hash'),
        );

        // Lenient display input (lowercase, dash replaced by space) must pair
        // the row — proving the app-side normalise-then-hash round-trip end to
        // end against the real engine.
        $lenient = strtolower(str_replace('-', ' ', $issued->claimCode));
        $result = $handler->handleClaimCode($lenient, $userId);

        self::assertArrayHasKey('enrollment_jwt', $result);
        self::assertSame(2, substr_count((string) $result['enrollment_jwt'], '.'));

        /** @var list<array<string, mixed>> $row */
        $row = $this->db->query(
            'SELECT claimed_by, status FROM server_claims WHERE id = :id',
            ['id' => $issued->claimId],
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
     * M-HUB-12 (review hub__exploiter M1) through the REAL engine under the
     * hash regime: the success transition persists exactly one `server_claim`
     * row in audit_logs (carrying only the R20-04 fingerprint of the code the
     * console actually received), while failed guesses — before and after —
     * persist NOTHING. N guesses cannot multiply permanent rows.
     */
    public function testSuccessfulClaimPersistsOneAuditRowAndRefusalsPersistNone(): void
    {
        $userId = $this->insertUser();
        $handler = $this->handler($userId, memoryLogger: true);
        $issued = $handler->handleNewClaim(new ClaimRequest(
            serverName: 'Hash Audit',
            version: '0.12.0',
            publicKeysJwk: $this->validEd25519Jwk(),
            hostnameCandidates: [],
            protocolVersion: 'v1',
        ));

        // A refusal before the success: no DB row.
        try {
            $handler->handleClaimCode('QQQQ-9999', $userId);
            self::fail('expected unknown claim code refusal');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('CLAIM_CODE_NOT_FOUND', $refusal->getMessage());
        }
        $afterRefusal = $this->serverClaimAuditRows();
        self::assertSame([], $afterRefusal);

        // The transition: exactly one row, fingerprint-only context.
        $result = $handler->handleClaimCode($issued->claimCode, $userId);
        $rows = $this->serverClaimAuditRows();
        if (!isset($rows[0])) {
            self::fail('expected exactly one server_claim audit row after the success');
        }
        self::assertCount(1, $rows);
        self::assertSame($userId, self::stringField($rows[0], 'user_id'));
        self::assertSame((string) $result['server_id'], self::stringField($rows[0], 'resource'));
        self::assertSame(1, self::intField($rows[0], 'success'));
        $context = (string) self::stringField($rows[0], 'context_json');
        self::assertStringContainsString(
            ClaimRequestHandler::claimCodeFingerprint($issued->claimCode),
            $context,
        );
        self::assertStringNotContainsString($issued->claimCode, $context);

        // Refusals after the success keep the count at one (redeemed code →
        // not-found arm after consumption, still file-log-only).
        try {
            $handler->handleClaimCode('ZZZZ-1111', $userId);
            self::fail('expected unknown claim code refusal');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('CLAIM_CODE_NOT_FOUND', $refusal->getMessage());
        }
        $afterRefusals = $this->serverClaimAuditRows();
        self::assertCount(1, $afterRefusals);
    }

    private function handler(string $userId, bool $memoryLogger = false): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $this->db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
            $memoryLogger ? $this->realAuditLogger() : $this->createAuditStub(),
            'https://hub.example.com',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serverClaimAuditRows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT user_id, resource, success, context_json FROM audit_logs'
                . " WHERE event = 'server_claim' ORDER BY id",
        );
        return $rows;
    }

    /**
     * The canonical at-rest form: sha256 over the normalised code (non-
     * alphanumerics stripped, uppercased) — the same computation the handler
     * performs; re-expressed here so the DB-side pins never trust the app to
     * grade its own homework on the mint legs, only on storage identity.
     */
    private static function hashOf(string $claimCode): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $claimCode)));
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

        throw new RuntimeException(sprintf(
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

        throw new RuntimeException(sprintf(
            'Expected a numeric column %s, got %s.',
            $field,
            get_debug_type($value),
        ));
    }

    private function insertClaimRow(string $id, string $claimCode): void
    {
        $this->db->query(
            'INSERT INTO server_claims
                (id, claim_code_hash, server_name, version, jwks_json, hostname_candidates_json,
                 public_key_jwk, protocol_version, expires_at, created_at)
             VALUES (:id, :claim_code_hash, :server_name, :version, :jwks, :hosts, :jwk, :pv, :exp, :now)',
            [
                'id' => $id,
                'claim_code_hash' => self::hashOf($claimCode),
                'server_name' => 'hash-flow',
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
                'u' => 'hash-' . substr($id, 0, 8),
                'e' => 'hash-' . substr($id, 0, 8) . '@example.com',
                'p' => password_hash('irrelevant', PASSWORD_ARGON2ID),
            ],
        );

        return $id;
    }

    /**
     * Valid Ed25519 OKP JWK — handleNewClaim refuses non-conforming keys.
     *
     * @return array<string, mixed>
     */
    private function validEd25519Jwk(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = substr($keyPair, 64);
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'kid' => date('c'),
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    private function realAuditLogger(): AuditLogger
    {
        return new AuditLogger(
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
            new AuditLogRepository($this->db),
        );
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
