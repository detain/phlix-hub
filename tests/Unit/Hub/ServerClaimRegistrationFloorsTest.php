<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@gmail.com>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Shared\Hub\ClaimRequest;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * M-HUB-13 registration floors (review hub__exploiter M2): the new-row path
 * of POST /server-claims/new is bounded where the M12 era left it open —
 * hostname-array count/length before the INSERT, a bounded dedupe WINDOW
 * (retry pile-up), and the aggregate table floor that trips to the central
 * 429. Every test here is red on the parent tree by construction (each
 * names exactly one unbounded behavior the cure closes); mutation teeth
 * recorded in .reviews/fixlog/M-HUB-13__fix.md.
 *
 * The fake Connection is a SEMANTIC harness, not a string recorder: it
 * honors `LIMIT n` in the SQL it is given (array_slice), so the window cure
 * is proven against the very mechanism the flood exploited — a parent-tree
 * `LIMIT 1` makes the same rows invisible and the retry INSERTs.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ServerClaimRegistrationFloorsTest extends TestCase
{
    private string $tmpDir;

    /**
     * Aggregate floor mirrored from ClaimRequestHandler::MAX_CLAIM_TABLE_ROWS.
     * Deliberately a literal here (not a reflection read): a silent lowering
     * of the shipped constant must not auto-tune the test; the pin test
     * below ties the two eras together honestly.
     */
    private const FLOOR = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-m13-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        self::assertIsArray($files);
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jwk(string $x): array
    {
        return ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $x];
    }

    /**
     * A valid Ed25519 JWK (32 distinct raw bytes per seed), so fingerprints
     * genuinely differ between seeds.
     *
     * @return array<string, mixed>
     */
    private function validJwk(int $seed = 0): array
    {
        $raw = str_pad((string) $seed, 32, 'k', STR_PAD_LEFT);
        return $this->jwk(base64_encode($raw));
    }

    private function handler(?Connection $db = null): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $db ?? $this->createMock(Connection::class),
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            $this->createMock(StructuredLogger::class),
            $this->createMock(AuditLogger::class),
            'https://hub.example.com',
        );
    }

    /**
     * @param array<string, mixed> $jwk
     * @param list<string>         $hostnames
     */
    private function request(array $jwk, array $hostnames = []): ClaimRequest
    {
        return new ClaimRequest(
            serverName: 'M13 Server',
            version: '0.12.0',
            publicKeysJwk: $jwk,
            hostnameCandidates: $hostnames,
            protocolVersion: 'v1',
        );
    }

    // ------------------------------------------------------------------
    // Hostname-array bound
    // ------------------------------------------------------------------

    public function testHostnameCandidateCountOverBoundIsRejectedBeforeAnyWrite(): void
    {
        $inserted = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$inserted): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = true;
                }
                return [];
            },
        );

        $hostnames = array_map(
            static fn (int $i): string => 'host-' . $i . '.example.com',
            range(1, 17),
        );

        try {
            $this->handler($db)->handleNewClaim($this->request($this->validJwk(), $hostnames));
            self::fail('Expected InvalidArgumentException for an over-count hostname array.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('HOSTNAME_CANDIDATES_INVALID', $e->getMessage());
        }
        self::assertFalse($inserted, 'Fail-loud must happen BEFORE any write.');
    }

    public function testHostnameCandidateLengthOverBoundIsRejected(): void
    {
        $long = str_repeat('a', 254);
        try {
            $this->handler()->handleNewClaim($this->request($this->validJwk(), [$long]));
            self::fail('Expected InvalidArgumentException for a 254-byte hostname.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('HOSTNAME_CANDIDATES_INVALID', $e->getMessage());
        }
    }

    public function testEmptyHostnameCandidateIsRejected(): void
    {
        try {
            $this->handler()->handleNewClaim($this->request($this->validJwk(), ['']));
            self::fail('Expected InvalidArgumentException for an empty hostname.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('HOSTNAME_CANDIDATES_INVALID', $e->getMessage());
        }
    }

    public function testBoundaryHostnamesStillPass(): void
    {
        // Exactly 16 candidates, longest legal (253 octets): the bound must
        // bite the flood, never a spec-legal advertise set.
        $hostnames = [str_repeat('a', 253)];
        for ($i = 1; $i < 16; $i++) {
            $hostnames[] = 'h' . $i . '.lan';
        }

        $db = $this->createMock(Connection::class);
        $inserted = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$inserted): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = $params;
                }
                return [];
            },
        );

        $response = $this->handler($db)->handleNewClaim($this->request($this->validJwk(), $hostnames));

        self::assertNotSame([], $inserted, 'Legal payload must reach the INSERT.');
        self::assertSame(600, $response->expiresIn);
    }

    // ------------------------------------------------------------------
    // Retry pile-up: bounded dedupe window
    // ------------------------------------------------------------------

    public function testDedupeWindowIsBoundedNewestRowsNotSingleRow(): void
    {
        // SQL-shape pin (house style, M12 precedent): the pile-up cure is
        // visible in the statement — a newest-50 scan, never LIMIT 1.
        $sql = '';
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $statement) use (&$sql): array {
                if (str_contains($statement, 'claimed_by IS NULL')) {
                    $sql = $statement;
                }
                return [];
            },
        );

        $this->handler($db)->handleNewClaim($this->request($this->validJwk()));

        self::assertMatchesRegularExpression(
            '/ORDER BY created_at DESC LIMIT 50$/im',
            $sql,
            'Dedupe scan must pin CLAIM_LOOKUP_WINDOW (newest-50); a revert to LIMIT 1 reddens here.',
        );
    }

    public function testRetryReusesMatchOutsideTheNewestRow(): void
    {
        // Semantic harness: 4 unrelated pending pairings interleaved ABOVE
        // this server's own row. The parent tree (LIMIT 1) sees only the
        // newest unrelated row, misses dedupe, and pile-up-INSERTs — red.
        $mine = $this->validJwk(7);
        $rows = [];
        foreach ([1, 2, 3, 4] as $seed) {
            $rows[] = [
                'id' => 'foreign-' . $seed,
                'claim_code' => 'FCDE-999' . $seed,
                'expires_at' => time() + 500,
                'public_key_jwk' => json_encode($this->validJwk($seed)),
            ];
        }
        $rows[] = [
            'id' => 'mine-1',
            'claim_code' => 'MINE-CODE',
            'expires_at' => time() + 500,
            'public_key_jwk' => json_encode($mine),
        ];

        $inserted = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($rows, &$inserted): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = true;
                    return [];
                }
                if (str_contains($sql, 'claimed_by IS NULL')) {
                    // Honor the SQL's LIMIT — this is what the real server does.
                    if (preg_match('/LIMIT (\d+)/', $sql, $m) === 1) {
                        return array_slice($rows, 0, (int) $m[1]);
                    }
                    return $rows;
                }
                return [];
            },
        );

        $response = $this->handler($db)->handleNewClaim($this->request($mine));

        self::assertFalse($inserted, 'A fingerprint match inside the window must reuse, not grow.');
        self::assertSame('MINE-CODE', $response->claimCode);
        self::assertSame('mine-1', $response->claimId);
    }

    // ------------------------------------------------------------------
    // Aggregate table floor
    // ------------------------------------------------------------------

    public function testFloorTripsToRateLimitExceptionWithNoInsert(): void
    {
        $inserted = false;
        $reaped = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$inserted, &$reaped): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = true;
                    return [];
                }
                if (str_contains($sql, 'DELETE FROM server_claims')) {
                    $reaped = true; // nothing expired: all rows are live pending
                    return [];
                }
                if (str_contains($sql, 'SELECT COUNT(*) AS cnt FROM server_claims')) {
                    return [['cnt' => self::FLOOR]];
                }
                return [];
            },
        );

        try {
            $this->handler($db)->handleNewClaim($this->request($this->validJwk()));
            self::fail('Expected RateLimitException at the aggregate floor.');
        } catch (RateLimitException $e) {
            self::assertSame(0, $e->remaining);
            self::assertGreaterThanOrEqual(time() + 599, $e->resetAt);
            self::assertLessThanOrEqual(time() + 600, $e->resetAt);
        }
        self::assertTrue($reaped, 'The floor must attempt the expired-row reaper before refusing.');
        self::assertFalse($inserted, 'A tripped floor must not grow the table.');
    }

    public function testFloorReapsExpiredRowsThenAdmits(): void
    {
        // The floor is pressure-triggered GC, not a hang-up: at-cap-with-
        // expired-garbage must reap and proceed, because the poll already
        // answers 'expired' for those rows (they are unreachable state).
        $inserted = false;
        $reaperCalls = 0;
        $counts = [self::FLOOR, self::FLOOR - 1]; // before reap / after reap
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$inserted, &$reaperCalls, &$counts): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = true;
                    return [];
                }
                if (str_contains($sql, 'DELETE FROM server_claims')) {
                    $reaperCalls++;
                    return [];
                }
                if (str_contains($sql, 'SELECT COUNT(*) AS cnt FROM server_claims')) {
                    return [['cnt' => array_shift($counts)]];
                }
                return [];
            },
        );

        $response = $this->handler($db)->handleNewClaim($this->request($this->validJwk()));

        self::assertSame(1, $reaperCalls, 'Reap exactly once, only because the floor tripped.');
        self::assertTrue($inserted);
        self::assertNotSame('', $response->claimCode);
    }

    public function testBelowFloorInsertsWithoutReapOrCountRefusal(): void
    {
        $reaperCalls = 0;
        $inserted = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$reaperCalls, &$inserted): array {
                if (str_contains($sql, 'INSERT')) {
                    $inserted = true;
                    return [];
                }
                if (str_contains($sql, 'DELETE FROM server_claims')) {
                    $reaperCalls++;
                    return [];
                }
                if (str_contains($sql, 'SELECT COUNT(*) AS cnt FROM server_claims')) {
                    return [['cnt' => self::FLOOR - 1]];
                }
                return [];
            },
        );

        $this->handler($db)->handleNewClaim($this->request($this->validJwk()));

        self::assertTrue($inserted);
        self::assertSame(0, $reaperCalls, 'The reaper is a pressure path, never the routine path.');
    }

    public function testShippedFloorConstantMatchesTheTestEra(): void
    {
        // Census bridge, not theater: the floor VALUE shipped is load-bearing
        // for the justification docblock (max_servers_per_user 0..1000 family).
        // If a future lane changes it, both this pin and every floor test's
        // semantics must be revisited together — silence here means drift.
        $reflection = new \ReflectionClass(ClaimRequestHandler::class);
        $constants = $reflection->getConstants(\ReflectionProperty::class::IS_PRIVATE);

        self::assertSame(self::FLOOR, $constants['MAX_CLAIM_TABLE_ROWS']);
        self::assertSame(50, $constants['CLAIM_LOOKUP_WINDOW']);
        self::assertSame(16, $constants['MAX_HOSTNAME_CANDIDATES']);
        self::assertSame(253, $constants['MAX_HOSTNAME_LENGTH']);
        self::assertSame(600, $constants['CLAIM_TTL_SECONDS']);
    }
}
