<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * M-HUB-12 (review hub__exploiter M1, finding 4): the claim-code refusal arms
 * must not persist one `audit_logs` row per guess — an attacker-paced INSERT
 * rate against a retained table is a disk-growth DoS on the hub's own audit
 * trail. Refusals are file-log warnings carrying the R20-04 fingerprint; the
 * PERSISTED events are the successful claim and (via the limiter in
 * ServerClaimRateLimitTest) the first-trip transition.
 *
 * These pins are RED on the parent commit by construction: there the refusal
 * arms call `$this->audit->logFailedAuth(...)`, which the never() expectations
 * below reject.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ClaimAuditDeAmplificationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claim-deamp-' . uniqid();
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
     * @param list<array<string, mixed>> $logContexts
     * @param list<array<string, mixed>> $auditSuccesses
     *
     * @return array{0: StructuredLogger&MockObject, 1: AuditLogger&MockObject}
     */
    private function recorders(array &$logContexts, array &$auditSuccesses): array
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            static function (string $message, array $context = []) use (&$logContexts): void {
                $logContexts[] = ['message' => $message, 'context' => $context];
            },
        );
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context = []) use (&$logContexts): void {
                $logContexts[] = ['message' => $message, 'context' => $context];
            },
        );

        $audit = $this->createMock(AuditLogger::class);
        // The PERSISTENCE boundary under test: logFailedAuth / logServerClaim
        // are the ONLY AuditLogger methods that write audit_logs rows.
        $audit->method('logServerClaim')->willReturnCallback(
            static function (string $userId, string $serverId, string $fp) use (&$auditSuccesses): void {
                $auditSuccesses[] = ['user_id' => $userId, 'server_id' => $serverId, 'claim_code_fp' => $fp];
            },
        );

        return [$logger, $audit];
    }

    private function handlerFor(Connection $db, StructuredLogger $logger, AuditLogger $audit): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            $logger,
            $audit,
            'https://hub.example.com',
        );
    }

    /**
     * Typed extraction of one recorded log arm's context (repo rule: no
     * mixed casts / no offset access on mixed in tests — mirrors
     * FederationFrameAssertions' narrowing shape).
     *
     * @param list<array<string, mixed>> $logs
     *
     * @return array<string, mixed>
     */
    private static function contextAt(array $logs, int $index): array
    {
        /** @var mixed $entry */
        $entry = $logs[$index] ?? null;
        self::assertIsArray($entry);
        /** @var mixed $context */
        $context = $entry['context'] ?? null;
        self::assertIsArray($context);

        /** @var array<string, mixed> $context */
        return $context;
    }

    /** @param list<array<string, mixed>> $rows */
    private function dbReturning(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, $params = null) use ($rows): array {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return $rows;
                }
                return [];
            },
        );
        return $db;
    }

    public function testEmptyAfterNormalisationGuessPersistsNothing(): void
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = [];
        /** @var list<array<string, mixed>> $successes */
        $successes = [];
        [$logger, $audit] = $this->recorders($logs, $successes);

        $audit->expects(self::never())->method('logFailedAuth');
        $audit->expects(self::never())->method('logServerClaim');

        $db = $this->dbReturning([]);
        $handler = $this->handlerFor($db, $logger, $audit);

        try {
            $handler->handleClaimCode('   ', 'user-1');
            self::fail('expected CLAIM_CODE_NOT_FOUND for whitespace-only input');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CLAIM_CODE_NOT_FOUND', $e->getMessage());
        }

        self::assertNotEmpty($logs);
        $arm = self::contextAt($logs, 0);
        self::assertSame('auth_failure', $arm['event'] ?? null);
        self::assertSame('CLAIM_CODE_INVALID', $arm['reason'] ?? null);
    }

    public function testUnknownCodeGuessWarnsToFileOnly(): void
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = [];
        /** @var list<array<string, mixed>> $successes */
        $successes = [];
        [$logger, $audit] = $this->recorders($logs, $successes);

        $audit->expects(self::never())->method('logFailedAuth');
        $audit->expects(self::never())->method('logServerClaim');

        // No claim row exists at all (empty lookup result).
        $handler = $this->handlerFor($this->dbReturning([]), $logger, $audit);

        try {
            $handler->handleClaimCode('QQQQ-9999', 'user-1');
            self::fail('expected CLAIM_CODE_NOT_FOUND');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CLAIM_CODE_NOT_FOUND', $e->getMessage());
        }

        $arm = self::contextAt($logs, 0);
        self::assertSame('auth_failure', $arm['event'] ?? null);
        self::assertSame('CLAIM_CODE_NOT_FOUND', $arm['reason'] ?? null);
        self::assertSame(
            ClaimRequestHandler::claimCodeFingerprint('QQQQ-9999'),
            $arm['claim_code_fp'] ?? null,
            'the file arm keeps the R20-04 fingerprint (display-variant stable) for correlation',
        );
        // …and never the plaintext (the same scrub law now protects the file
        // channel the refusals moved to).
        self::assertStringNotContainsString('QQQQ-9999', json_encode($logs, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('QQQQ9999', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function testExpiredCodeGuessWarnsToFileOnly(): void
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = [];
        /** @var list<array<string, mixed>> $successes */
        $successes = [];
        [$logger, $audit] = $this->recorders($logs, $successes);

        $audit->expects(self::never())->method('logFailedAuth');
        $audit->expects(self::never())->method('logServerClaim');

        $handler = $this->handlerFor($this->dbReturning([[
            'id' => 'claim-x',
            'claim_code' => 'QQQQ-9999',
            'expires_at' => time() - 5,
            'claimed_by' => null,
        ]]), $logger, $audit);

        try {
            $handler->handleClaimCode('QQQQ-9999', 'user-1');
            self::fail('expected CLAIM_CODE_EXPIRED');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CLAIM_CODE_EXPIRED', $e->getMessage());
        }
        self::assertSame('CLAIM_CODE_EXPIRED', self::contextAt($logs, 0)['reason'] ?? null);
    }

    public function testAlreadyClaimedCodeGuessWarnsToFileOnly(): void
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = [];
        /** @var list<array<string, mixed>> $successes */
        $successes = [];
        [$logger, $audit] = $this->recorders($logs, $successes);

        $audit->expects(self::never())->method('logFailedAuth');
        $audit->expects(self::never())->method('logServerClaim');

        $handler = $this->handlerFor($this->dbReturning([[
            'id' => 'claim-x',
            'claim_code' => 'QQQQ-9999',
            'expires_at' => time() + 600,
            'claimed_by' => 'owner-1',
        ]]), $logger, $audit);

        try {
            $handler->handleClaimCode('QQQQ-9999', 'user-1');
            self::fail('expected CLAIM_CODE_ALREADY_CLAIMED');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CLAIM_CODE_ALREADY_CLAIMED', $e->getMessage());
        }
        $context = self::contextAt($logs, 0);
        self::assertSame('CLAIM_CODE_ALREADY_CLAIMED', $context['reason'] ?? null);
        // Operator-facing correlation survives (who owns the code) in the
        // FILE channel — the arm the DB trail lost.
        self::assertSame('owner-1', $context['claimed_by'] ?? null);
    }

    public function testSuccessfulClaimPersistsExactlyOneAuditRowWithFingerprint(): void
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = [];
        /** @var list<array<string, mixed>> $successes */
        $successes = [];
        [$logger, $audit] = $this->recorders($logs, $successes);

        $audit->expects(self::never())->method('logFailedAuth');

        $db = $this->dbReturning([[
            'id' => 'claim-ok-1',
            'claim_code' => 'DR4Q-7AXB',
            'expires_at' => time() + 600,
            'claimed_by' => null,
            'public_key_jwk' => '{"kty":"OKP","crv":"Ed25519","x":"aaa"}',
            'hostname_candidates_json' => '[]',
            'server_name' => 'Deampl Server',
            'version' => '0.12.0',
        ]]);
        $handler = $this->handlerFor($db, $logger, $audit);

        $result = $handler->handleClaimCode('dr4q-7axb', 'user-deamp-1');

        self::assertArrayHasKey('enrollment_jwt', $result);
        self::assertCount(1, $successes, 'the SUCCESS arm is the persisted event');
        self::assertSame('user-deamp-1', $successes[0]['user_id']);
        self::assertNotSame('', $successes[0]['server_id']);
        self::assertSame(
            ClaimRequestHandler::claimCodeFingerprint('DR4Q7AXB'),
            $successes[0]['claim_code_fp'],
            'the audit row carries the fingerprint, never the plaintext code (R20-04)',
        );

        $payload = json_encode($successes, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('DR4Q-7AXB', $payload);
        self::assertStringNotContainsString('DR4Q7AXB', $payload);
    }
}
