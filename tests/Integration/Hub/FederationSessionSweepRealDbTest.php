<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\ServerReaper;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * M-HUB-26 (review hub__asphp minor #6): `federation_sessions` closed rows
 * were written by the alive=0 marking reaper and never deleted by anything.
 *
 * {@see ServerReaper::sweepDeadFederationSessions()} must, against a real
 * InnoDB with the real 028 schema (FK to `federation_peers`, TIMESTAMP
 * defaults, `idx_last_heartbeat`):
 *
 *  - delete a closed row whose `last_heartbeat_at` is beyond retention,
 *  - KEEP a closed row inside retention (the investigation window),
 *  - NEVER touch a row with `alive = 1` — not even an ancient one. That leg
 *    is the mutation teeth: strip the `alive = 0` predicate and this test
 *    reddens by name.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 * @group integration
 */
final class FederationSessionSweepRealDbTest extends RealDatabaseTestCase
{
    private const PEER_ID = '11111111-2222-4333-8444-555555555555';

    public function testSweepDeletesOnlyClosedRowsBeyondRetentionAndKeepsLiveOnes(): void
    {
        $this->insertPeer();
        $this->insertSession('sess-old-closed', 0, 10);
        $this->insertSession('sess-recent-closed', 0, 1);
        $this->insertSession('sess-old-live', 1, 30);

        $reaper = $this->reaper();

        self::assertSame(
            1,
            $reaper->sweepDeadFederationSessions(),
            'exactly the closed-and-expired row must be purged',
        );
        self::assertSame(
            ['sess-old-live', 'sess-recent-closed'], // ORDER BY id (CHAR lexicographic)
            $this->remainingSessionIds(),
            'recent closed rows (investigation window) and live rows must survive',
        );

        // Idempotent second tick: nothing expired remains, no DELETE issued
        // (a zero is honest here — if it deleted again the first assert's
        // arithmetic was luck).
        self::assertSame(0, $reaper->sweepDeadFederationSessions());
    }

    private function reaper(): ServerReaper
    {
        return new ServerReaper(
            $this->db,
            new StructuredLogger('test', [
                'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
                'processors' => [],
            ]),
        );
    }

    private function insertPeer(): void
    {
        $this->db->query(
            'INSERT INTO federation_peers (id, name, url, public_key, status)
             VALUES (:id, :name, :url, :key, :status)',
            [
                'id' => self::PEER_ID,
                'name' => 'sweep-test-peer',
                'url' => 'https://peer-sweep-test.example.com',
                'key' => 'dummy-public-key',
                'status' => 'disconnected',
            ],
        );
    }

    private function insertSession(string $id, int $alive, int $heartbeatAgeDays): void
    {
        // Explicit TIMESTAMP override: the column defaults to CURRENT_TIMESTAMP
        // but the sweep is age-driven.
        $this->db->query(
            'INSERT INTO federation_sessions
                (id, peer_id, established_at, last_heartbeat_at, bytes_sent, bytes_received, alive)
             VALUES
                (:id, :pid, NOW() - INTERVAL :ageE DAY, NOW() - INTERVAL :ageH DAY, 0, 0, :alive)',
            [
                'id' => $id,
                'pid' => self::PEER_ID,
                // Distinct binds: the vendored workerman/mysql connection maps
                // named params positionally, so one name cannot appear twice.
                'ageE' => $heartbeatAgeDays,
                'ageH' => $heartbeatAgeDays,
                'alive' => $alive,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function remainingSessionIds(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM federation_sessions ORDER BY id',
        );

        $ids = [];
        foreach ($rows as $row) {
            /** @var mixed $raw */
            $raw = $row['id'] ?? null;
            if (is_string($raw)) {
                $ids[] = $raw;
            }
        }

        return $ids;
    }
}
