<?php

/**
 * CP-029 / D-1a hub twin — migration 055 + family store, against REAL MySQL.
 *
 * Server twin: TokenSessionFamilyMigration127RealDbTest (413765b6). The unit
 * suite decides WHAT the adjudicator does; this file proves the rows the
 * decision depends on actually exist, stay consistent, and die when they
 * should — under the real storage engine, the real clock, and the real FK
 * graph. Everything the hub's family law rests on is convicted here:
 *
 *  - the DDL applies fresh through the full chain and matches the server
 *    twin's INFORMATION_SCHEMA fingerprint byte-for-shape (widths, ENUM
 *    domains, index inventory, both CASCADE edges);
 *  - replay idempotence BOTH ways: the runner skips a recorded chain, and
 *    the raw statements re-execute as no-ops (CREATE IF NOT EXISTS truth) —
 *    the two together make the migration safe against any re-deploy posture;
 *  - the grace window is computed by the DATABASE clock against the same
 *    constant the adjudicator passes (a PHP-side now() would drift with the
 *    TZ-window flake class the estate already documents);
 *  - consume → reuse, family isolation, listing order, and — the hub's
 *    chosen nuclear edge — user delete CASCADEing families and grants,
 *    plus the revokeAllForUser primitive the hub ships WITHOUT a production
 *    call site (no watermark choke on the hub; this leg is its only
 *    conviction, which is exactly why it lives here and not in prose).
 *
 * Phlix hub test: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Auth;

use Phlix\Hub\Auth\TokenSessionRepository;
use Phlix\Hub\Common\Database\MigrationRunner;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Phlix\Hub\Common\Support\Ids;

use function array_column;
use function array_map;
use function file_get_contents;
use function password_hash;
use function preg_split;
use function str_contains;
use function trim;

/**
 * @package Phlix\Hub\Tests\Integration\Auth
 *
 * @group integration
 */
final class TokenSessionFamilyMigration055RealDbTest extends RealDatabaseTestCase
{
    /** Mirrors AuthManager::ROTATION_GRACE_SECONDS — kept as a literal here
     *  on purpose: a rotation of the manager constant must redden the grace
     *  leg below, and this file's pin forces a conscious second look. */
    private const GRACE_SECONDS = 10;

    private TokenSessionRepository $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new TokenSessionRepository($this->db);
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function testTheFamilyTablesArriveWithTheTwinDdlFingerprint(): void
    {
        /** @var mixed $columns */
        $columns = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:t1, :t2) ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ['t1' => 'auth_token_sessions', 't2' => 'auth_refresh_grants'],
        );
        self::assertIsArray($columns);

        $shape = [];
        foreach ($columns as $row) {
            $descriptor = $row['COLUMN_NAME'] . ':' . $row['COLUMN_TYPE'] . ':' . $row['IS_NULLABLE'];
            $shape[(string) $row['TABLE_NAME']][] = $descriptor;
        }

        // The query orders by TABLE_NAME (alphabetical: auth_refresh_grants
        // before auth_token_sessions) — the expectation mirrors that order so
        // the comparison stays strict (assertSame) on both keys and columns.
        self::assertSame([
            'auth_refresh_grants' => [
                'jti:char(32):NO',
                'session_id:char(36):NO',
                'consumed_at:timestamp:YES',
                'created_at:timestamp:NO',
            ],
            'auth_token_sessions' => [
                'id:char(36):NO',
                'user_id:char(36):NO',
                "status:enum('active','revoked'):NO",
                'revoke_reason:varchar(64):YES',
                'device_type:varchar(50):YES',
                'device_name:varchar(255):YES',
                'created_at:timestamp:NO',
                'last_used_at:timestamp:NO',
                'revoked_at:timestamp:YES',
            ],
        ], $shape);
    }

    public function testTheIndexAndCascadeInventoryMatchTheServerTwin(): void
    {
        /** @var mixed $indexes */
        $indexes = $this->db->query(
            'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:t1, :t2) '
            . 'AND SEQ_IN_INDEX = 1 ORDER BY TABLE_NAME, INDEX_NAME',
            ['t1' => 'auth_token_sessions', 't2' => 'auth_refresh_grants'],
        );
        self::assertIsArray($indexes);
        self::assertSame(
            ['auth_refresh_grants:idx_arg_consumed', 'auth_refresh_grants:idx_arg_session',
                'auth_refresh_grants:PRIMARY', 'auth_token_sessions:idx_ats_user',
                'auth_token_sessions:idx_ats_user_status', 'auth_token_sessions:PRIMARY'],
            array_map(static fn (array $r): string => $r['TABLE_NAME'] . ':' . $r['INDEX_NAME'], $indexes),
        );

        /** @var mixed $fks */
        $fks = $this->db->query(
            'SELECT k.TABLE_NAME, k.REFERENCED_TABLE_NAME, d.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k '
            . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS d '
            . 'ON d.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND d.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA '
            . 'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN (:t1, :t2) ORDER BY k.TABLE_NAME',
            ['t1' => 'auth_token_sessions', 't2' => 'auth_refresh_grants'],
        );
        self::assertIsArray($fks);
        self::assertSame([
            [
                'TABLE_NAME' => 'auth_refresh_grants',
                'REFERENCED_TABLE_NAME' => 'auth_token_sessions',
                'DELETE_RULE' => 'CASCADE',
            ],
            ['TABLE_NAME' => 'auth_token_sessions', 'REFERENCED_TABLE_NAME' => 'users', 'DELETE_RULE' => 'CASCADE'],
        ], array_map(static fn (array $r): array => [
            'TABLE_NAME' => $r['TABLE_NAME'],
            'REFERENCED_TABLE_NAME' => $r['REFERENCED_TABLE_NAME'],
            'DELETE_RULE' => $r['DELETE_RULE'],
        ], $fks));
    }

    public function testTheRunnerSkipsAlreadyRecordedMigrationsOnReplay(): void
    {
        // The harness migrated the schema once; a second full run must
        // execute NOTHING (empty list = every file ledger-recorded with a
        // matching checksum). This is the deploy-pod-restart idempotence.
        $executed = (new MigrationRunner($this->db, self::migrationsDirectory()))->run();
        self::assertSame([], $executed);
    }

    public function testTheRawStatementsReExecuteAsNoOpsWithoutLosingRows(): void
    {
        $family = $this->store->createSession($this->seedUser('cp055raw'));
        self::assertSame(1, $this->countRows('auth_token_sessions'));

        $sql = (string) file_get_contents(self::migrationsDirectory() . '/055_auth_token_sessions.sql');
        $statements = preg_split('/;\s*\n/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!str_contains($statement, 'CREATE TABLE')) {
                continue;
            }
            self::assertNotFalse($this->db->query($statement), 'raw re-execute must not throw: ' . $statement);
        }

        self::assertSame(1, $this->countRows('auth_token_sessions'));
        $stillThere = $this->store->isSessionActive($family);
        self::assertTrue($stillThere, 'the IF NOT EXISTS no-op must leave the live family intact');
    }

    // ------------------------------------------------------------------
    // Clock / grace
    // ------------------------------------------------------------------

    public function testTheGraceWindowIsComputedByTheDatabaseClock(): void
    {
        $user = $this->seedUser('cp055grace');
        $family = $this->store->createSession($user);
        $this->store->recordGrant('jti-fresh-000000000000000000aa', $family);

        // 2 s into a 10 s window: benign multi-tab race territory.
        $this->setConsumedAt($family, 'jti-fresh-000000000000000000aa', 2);
        $grant = $this->store->findGrant('jti-fresh-000000000000000000aa', self::GRACE_SECONDS);
        self::assertNotNull($grant);
        self::assertTrue($grant['consumed']);
        self::assertTrue($grant['withinGrace']);

        // 30 s in: outside by any reading of the constant — reuse territory.
        $this->setConsumedAt($family, 'jti-fresh-000000000000000000aa', 30);
        $grant = $this->store->findGrant('jti-fresh-000000000000000000aa', self::GRACE_SECONDS);
        self::assertNotNull($grant);
        self::assertTrue($grant['consumed']);
        self::assertFalse($grant['withinGrace']);
    }

    public function testConsumeThenReuseFlowsThroughTheRealRows(): void
    {
        $user = $this->seedUser('cp055reuse');
        $family = $this->store->createSession($user);
        $jti = 'jti-reuse-00000000000000000000bb';
        $this->store->recordGrant($jti, $family);

        $this->store->markGrantConsumed($jti);
        $first = $this->store->findGrant($jti, self::GRACE_SECONDS);
        self::assertNotNull($first);
        self::assertTrue($first['consumed']);
        self::assertTrue($first['withinGrace'], 'immediate replay is the race, not the theft');

        // A second consume attempt must NOT move the first stamp (the
        // UPDATE's consumed_at IS NULL guard, convicted on real rows).
        $before = $this->consumedAtOf($jti);
        $this->store->markGrantConsumed($jti);
        self::assertSame($before, $this->consumedAtOf($jti));

        // The adjudicator's kill arm, executed for real: family dies.
        $this->store->revokeSession($family, 'refresh_token_reuse');
        $after = $this->store->findGrant($jti, self::GRACE_SECONDS);
        self::assertNotNull($after);
        self::assertSame('revoked', $after['sessionStatus']);
        self::assertFalse($this->store->isSessionActive($family));
    }

    // ------------------------------------------------------------------
    // Isolation / listing
    // ------------------------------------------------------------------

    public function testRevokingOneFamilyNeverTouchesAnotherFamilyOfTheSameUser(): void
    {
        $user = $this->seedUser('cp055isolate');
        $familyA = $this->store->createSession($user);
        $familyB = $this->store->createSession($user);
        $jtiB = 'jti-isolate-000000000000000000cc';
        $this->store->recordGrant($jtiB, $familyB);

        $this->store->revokeSession($familyA, 'logout');

        self::assertTrue($this->store->isSessionActive($familyB));
        $grant = $this->store->findGrant($jtiB, self::GRACE_SECONDS);
        self::assertNotNull($grant);
        self::assertSame('active', $grant['sessionStatus']);
        self::assertFalse($grant['consumed']);
        self::assertSame($familyB, $grant['sessionId']);
    }

    public function testActiveListingOrdersByLastUseAndPassesDeviceNullsThrough(): void
    {
        $user = $this->seedUser('cp055list');
        $older = $this->store->createSession($user);
        $newer = $this->store->createSession($user, 'tablet', null);

        $this->db->query(
            'UPDATE auth_token_sessions SET last_used_at = DATE_SUB(NOW(), INTERVAL 3600 SECOND) WHERE id = :id',
            ['id' => $older],
        );
        $this->store->touchSession($newer);

        $rows = $this->store->listActiveSessions($user);
        self::assertSame([$newer, $older], array_column($rows, 'id'), 'newest-used first, always');
        self::assertSame('tablet', $rows[0]['device_type']);
        self::assertNull($rows[0]['device_name']);
        self::assertNull($rows[1]['device_type']);

        // A revoked family leaves the list immediately (it is a live view).
        $this->store->revokeSession($newer, 'logout');
        self::assertSame([$older], array_column($this->store->listActiveSessions($user), 'id'));
    }

    // ------------------------------------------------------------------
    // Cascade + the primitive without a production call site
    // ------------------------------------------------------------------

    public function testDeletingTheUserCascadesFamiliesAndGrants(): void
    {
        // The hub's shipped nuclear edge: no watermark choke exists, so the
        // user-row CASCADE IS the kill-all path (register deviation table).
        $user = $this->seedUser('cp055cascade');
        $family = $this->store->createSession($user);
        $jti = 'jti-cascade-000000000000000000dd';
        $this->store->recordGrant($jti, $family);

        $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $user]);

        self::assertSame(0, $this->countRows('auth_token_sessions'));
        self::assertSame(0, $this->countRows('auth_refresh_grants'));
    }

    public function testDeletingAFamilyCascadesItsGrants(): void
    {
        $user = $this->seedUser('cp055famdelete');
        $family = $this->store->createSession($user);
        $this->store->recordGrant('jti-famdel-000000000000000000ee', $family);

        $this->db->query('DELETE FROM auth_token_sessions WHERE id = :id', ['id' => $family]);
        self::assertSame(0, $this->countRows('auth_refresh_grants'));
    }

    public function testRevokeAllForUserKillsExactlyThatUsersFamiliesOnce(): void
    {
        $one = $this->seedUser('cp055all-one');
        $two = $this->seedUser('cp055all-two');
        $familyOne = $this->store->createSession($one);
        $familyTwo = $this->store->createSession($one);
        $familyElsewhere = $this->store->createSession($two);

        $this->store->revokeAllForUser($one, 'twin_probe');

        self::assertFalse($this->store->isSessionActive($familyOne));
        self::assertFalse($this->store->isSessionActive($familyTwo));
        self::assertTrue($this->store->isSessionActive($familyElsewhere));

        $stamp = $this->revokedAtOf($familyOne);
        // Second call must not rewrite the first kill (the UPDATE's status
        // guard): reason and timestamp of a death stay who-dunnit-faithful.
        $this->store->revokeAllForUser($one, 'twin_probe_later');
        self::assertSame($stamp, $this->revokedAtOf($familyOne));
        /** @var mixed $rows */
        $rows = $this->db->query('SELECT revoke_reason FROM auth_token_sessions WHERE id = :id', ['id' => $familyOne]);
        self::assertIsArray($rows);
        self::assertSame('twin_probe', $rows[0]['revoke_reason']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedUser(string $username): string
    {
        $id = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash, display_name, is_admin) '
            . 'VALUES (:id, :u, :e, :p, :d, 0)',
            [
                'id' => $id,
                'u' => $username,
                'e' => $username . '@example.test',
                'p' => password_hash('Str0ng-Passw0rd!', PASSWORD_ARGON2ID),
                'd' => $username,
            ],
        );

        return $id;
    }

    /** Move a grant's consumption stamp into the past ON THE DB CLOCK. */
    private function setConsumedAt(string $family, string $jti, int $secondsAgo): void
    {
        $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = DATE_SUB(NOW(), INTERVAL :s SECOND) '
            . 'WHERE jti = :jti AND session_id = :sid',
            ['s' => $secondsAgo, 'jti' => $jti, 'sid' => $family],
        );
    }

    private function consumedAtOf(string $jti): ?string
    {
        /** @var mixed $rows */
        $rows = $this->db->query('SELECT consumed_at FROM auth_refresh_grants WHERE jti = :jti', ['jti' => $jti]);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);

        return $rows[0]['consumed_at'] === null ? null : (string) $rows[0]['consumed_at'];
    }

    private function revokedAtOf(string $family): ?string
    {
        /** @var mixed $rows */
        $rows = $this->db->query('SELECT revoked_at FROM auth_token_sessions WHERE id = :id', ['id' => $family]);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);

        return $rows[0]['revoked_at'] === null ? null : (string) $rows[0]['revoked_at'];
    }

    private function countRows(string $table): int
    {
        /** @var mixed $rows */
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM ' . $table);
        self::assertIsArray($rows);

        return (int) $rows[0]['c'];
    }
}
