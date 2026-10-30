<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * M-HUB-24 (review hub__exploiter minor #4): the per-user server cap must be
 * serialized on the USER, not on the claim row.
 *
 * The unit venue (ClaimServerCapTest) pins the statement ORDER; this file
 * proves what only a real InnoDB can answer: with claim A holding an open
 * transaction that has locked the user row and inserted a server row, a
 * concurrent claim of a DIFFERENT code by the SAME user
 *   1. blocks on the users-PK serialization lock (it cannot count a stale
 *      set and slide its own row in), and
 *   2. after A commits, counts the committed row and refuses (cap reached),
 *      so the cap survives the exact race the review described.
 *
 * The blocking leg uses a deliberately short session `innodb_lock_wait_timeout`
 * as the SYNCHRONIZATION PROBE (no sleeps): A holds the lock, B must fail with
 * 1205 rather than complete. The handler's catch → rollBackTrans arm is
 * exercised for real (an un-rolled-back B would strand the table against
 * tearDown's truncate — the whole suite hangs, which is itself the proof).
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 * @group integration
 */
final class ServerClaimCapLockRealDbTest extends RealDatabaseTestCase
{
    private string $tmpDir;

    private ?PDO $holder = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claimcap-int-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->holder !== null) {
            if ($this->holder->inTransaction()) {
                $this->holder->rollBack();
            }
            $this->holder = null;
        }
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

    public function testDifferentCodeClaimBySameUserBlocksOnUserLockAndRefusesAfterCommit(): void
    {
        $userId   = $this->insertUser();
        $this->insertClaimRow('claim-a', 'AAAA-1111');
        $this->insertClaimRow('claim-b', 'BBBB-2222');

        // Session-level probe timeout: B must NOT wait out A — it must hit
        // 1205, proving the user-row lock actually blocks the second claim.
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');

        $this->holder = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) getenv('HUB_TEST_DB_HOST'),
                (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
                (string) getenv('HUB_TEST_DB_NAME'),
            ),
            (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
        );
        $this->holder->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Leg A: a claim transaction in flight — user row locked, server row
        // inserted but UNCOMMITTED (exactly the mid-claim state).
        $this->holder->beginTransaction();
        $lock = $this->holder->prepare('SELECT id FROM users WHERE id = :uid FOR UPDATE');
        $lock->execute(['uid' => $userId]);
        $lock->fetch(PDO::FETCH_ASSOC);
        $ins = $this->holder->prepare(
            'INSERT INTO servers
                (id, user_id, server_name, version, jwks_json, public_key_jwk,
                 hostname_candidates_json, status, last_seen_at, created_at, enrolled_at)
             VALUES (:id, :uid, :name, :ver, :jwks, :jwk, :hosts, \'online\', NOW(), NOW(), :ea)',
        );
        $ins->execute([
            'id'   => $this->uuid(),
            'uid'  => $userId,
            'name' => 'holder-transaction',
            'ver'  => '0.12.0',
            'jwks' => '{}',
            'jwk'  => '{}',
            'hosts' => '[]',
            'ea'   => time(),
        ]);

        // Leg B: concurrent claim of a DIFFERENT code by the SAME user with
        // cap = 1. Pre-M-HUB-24 this counted 0 (A's row is uncommitted) and
        // INSERTed a second server. Now it must block on the users lock → 1205.
        $handler = $this->handler($userId, 1);
        $threw   = false;
        try {
            $handler->handleClaimCode('BBBB-2222', $userId);
        } catch (\Throwable $e) {
            // The lock-wait surface is a PDOException (the vendored MySQL
            // connection sets PDO::ERRMODE_EXCEPTION internally even though it
            // does not extend PDO) — assert the type rather than catch it, so
            // a different failure mode can never satisfy this leg.
            $threw = $e instanceof \PDOException
                && (str_contains($e->getMessage(), 'Lock wait timeout')
                    || str_contains($e->getMessage(), '1205'));
        }
        self::assertTrue($threw, 'the second claim must block on the users-row lock and surface 1205');

        // B's exception path must have rolled its transaction back (handler
        // catch → rollBackTrans): no server row is visible from B's connection.
        self::assertSame(0, $this->serverCount($userId), 'refused claim B persisted nothing');
        self::assertNull($this->claimedBy('claim-b'), 'claim row B stayed unclaimed');

        // A commits. B's RETRY now takes the lock cleanly, counts the committed
        // row, and is refused by the cap itself — the overshoot is closed.
        $this->holder->commit();
        self::assertSame(1, $this->serverCount($userId), 'leg A landed exactly one server');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_CAP_REACHED');
        $handler->handleClaimCode('BBBB-2222', $userId);
    }

    public function testCapRefusalNeverLeavesAnOpenTransaction(): void
    {
        $userId = $this->insertUser();
        $this->insertClaimRow('claim-c', 'CCCC-3333');

        $handler = $this->handler($userId, 1);
        // Pre-seed the user with one server through a real claim, then try a
        // second: the cap refusal must roll the transaction back (the next
        // test's SET/truncate would hang on a dangling txn).
        $this->insertClaimRow('claim-d', 'DDDD-4444');
        $handler->handleClaimCode('CCCC-3333', $userId);

        try {
            $handler->handleClaimCode('DDDD-4444', $userId);
            self::fail('expected SERVER_CAP_REACHED');
        } catch (InvalidArgumentException $e) {
            self::assertSame('SERVER_CAP_REACHED', $e->getMessage());
        }

        // If the refusal path left a transaction open, this plain read would
        // run inside it — instead the next claim through the same connection
        // must still work normally (cap lifted) and the row land.
        $lifted = $this->handler($userId, 0);
        $result = $lifted->handleClaimCode('DDDD-4444', $userId);
        self::assertArrayHasKey('server_id', $result);
        self::assertSame(2, $this->serverCount($userId));
    }

    private function handler(string $userId, int $cap): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $this->db,
            new Ed25519KeyManager($this->tmpDir . '/key-' . substr($userId, 0, 8) . $cap . '.pem'),
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
            $this->createAuditStub(),
            'https://hub.example.com',
            static fn (): int => $cap,
        );
    }

    private function serverCount(string $userId): int
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM servers WHERE user_id = :uid',
            ['uid' => $userId],
        );

        $cnt = $rows[0]['cnt'] ?? 0;
        return is_numeric($cnt) ? (int) $cnt : 0;
    }

    private function claimedBy(string $claimRowId): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT claimed_by FROM server_claims WHERE id = :id',
            ['id' => $claimRowId],
        );
        $raw  = $rows[0]['claimed_by'] ?? null;

        return is_string($raw) ? $raw : null;
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
                'server_name' => 'cap-lock-probe',
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
                'u' => 'caplock-' . substr($id, 0, 8),
                'e' => 'caplock-' . substr($id, 0, 8) . '@example.com',
                'p' => password_hash('irrelevant', PASSWORD_BCRYPT),
            ],
        );

        return $id;
    }

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
