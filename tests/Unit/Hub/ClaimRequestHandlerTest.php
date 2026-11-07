<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Shared\Hub\ClaimRequest;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ClaimRequestHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ClaimRequestHandlerTest extends TestCase
{
    /**
     * Canonical RFC-4122 v4 UUID: 8-4-4-4-12 lowercase hex, version nibble
     * `4`, variant nibble one of 8/9/a/b. The claim `id` doubles as the
     * unguessable poll secret a headless server uses to fetch its enrollment
     * JWT, so it MUST be a >=128-bit CSPRNG value of this exact shape (S4).
     */
    private const string UUID_V4_REGEX =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claim-test-' . uniqid();
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

    public function testGenerateClaimCodeReturns4Plus4Format(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $code = $handler->generateClaimCode();

        self::assertSame(9, strlen($code));
        self::assertSame('-', $code[4]);
        $parts = explode('-', $code);
        self::assertSame(4, strlen($parts[0]));
        self::assertSame(4, strlen($parts[1]));
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}$/', $parts[0]);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}$/', $parts[1]);
    }

    public function testGenerateClaimCodeHasNoAmbiguousCharacters(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        for ($i = 0; $i < 50; $i++) {
            $code = $handler->generateClaimCode();
            $parts = explode('-', $code);
            foreach ($parts as $part) {
                self::assertStringNotContainsString('0', $part);
                self::assertStringNotContainsString('O', $part);
                self::assertStringNotContainsString('I', $part);
                self::assertStringNotContainsString('1', $part);
            }
        }
    }

    public function testHandleNewClaimRejectsInvalidProtocolVersion(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $request = new ClaimRequest(
            serverName: 'Test Server',
            version: '0.11.0',
            publicKeysJwk: $this->validEd25519Jwk(),
            hostnameCandidates: ['https://localhost:32400'],
            protocolVersion: 'v2',
        );

        $this->expectException(\InvalidArgumentException::class);
        $handler->handleNewClaim($request);
    }

    public function testHandleNewClaimRejectsMalformedJwk(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $request = new ClaimRequest(
            serverName: 'Test Server',
            version: '0.11.0',
            publicKeysJwk: ['kty' => 'RSA'],
            hostnameCandidates: [],
            protocolVersion: 'v1',
        );

        $this->expectException(\InvalidArgumentException::class);
        $handler->handleNewClaim($request);
    }

    public function testHandleNewClaimInsertsPendingClaim(): void
    {
        $db = $this->createMock(Connection::class);
        $inserted = [];
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$inserted) {
                if (str_contains($sql, 'INSERT INTO server_claims')) {
                    $inserted = $params;
                }
                return [];
            });

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $request = new ClaimRequest(
            serverName: 'My NAS',
            version: '0.11.0',
            publicKeysJwk: $this->validEd25519Jwk(),
            hostnameCandidates: ['https://192.168.1.100:32400'],
            protocolVersion: 'v1',
        );

        $response = $handler->handleNewClaim($request);

        self::assertNotEmpty($response->claimCode);
        self::assertSame(9, strlen($response->claimCode));
        self::assertSame(600, $response->expiresIn);
        self::assertNotEmpty($response->claimId);
        self::assertSame('https://hub.example.com', $response->hubBaseUrl);

        // S4: the claim id (the poll secret) must be a CSPRNG UUID v4 and the
        // value persisted to server_claims.id must be that same value.
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $response->claimId);
        self::addToAssertionCount(1);
        self::assertSame($response->claimId, $inserted['id']);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, (string) $inserted['id']);

        // SH-049 (review hub__secaudit #1): the pairing code is persisted ONLY
        // as a sha256 over its canonical normalised form (separators stripped,
        // uppercased) — the same normalisation the fingerprint law uses. The
        // plaintext display form rides the wire to the console once and never
        // touches the table, so a row dump cannot pair any server.
        self::assertArrayNotHasKey('claim_code', $inserted);
        self::assertSame(
            hash('sha256', strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $response->claimCode))),
            $inserted['claim_code_hash'] ?? null,
            'stored value must be sha256 of the normalised code the response carries',
        );
        self::assertSame(64, strlen((string) ($inserted['claim_code_hash'] ?? '')));
    }

    /**
     * S4 regression: the claim `id` poll secret is a high-entropy CSPRNG value.
     *
     * A non-crypto RNG (mt_rand/uniqid) or a sequential/low-entropy id would
     * let an attacker guess a pending claim's id and steal the freshly-minted
     * enrollment JWT. Mint a large sample and assert every id is a canonical
     * UUID v4 (128-bit, version/variant pinned) and that all draws are unique
     * — the observable signature of {@see \Phlix\Hub\Common\Support\Ids::uuidV4()}.
     */
    public function testHandleNewClaimMintsCsprngClaimIds(): void
    {
        $db = $this->createMock(Connection::class);
        $captured = [];
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured) {
                if (str_contains($sql, 'INSERT INTO server_claims')) {
                    $captured[] = (string) $params['id'];
                }
                return [];
            });

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $draws = 500;
        for ($i = 0; $i < $draws; $i++) {
            $request = new ClaimRequest(
                serverName: 'Server ' . $i,
                version: '0.11.0',
                publicKeysJwk: $this->validEd25519Jwk(),
                hostnameCandidates: [],
                protocolVersion: 'v1',
            );
            $response = $handler->handleNewClaim($request);
            self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $response->claimId);
        }

        self::assertCount($draws, $captured);
        foreach ($captured as $id) {
            self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $id);
        }
        self::assertCount(
            $draws,
            array_unique($captured),
            'Every claim id draw must be unique (CSPRNG, no collisions).',
        );
    }

    /**
     * SH-049 (review hub__secaudit #1): the duplicate-retry arm can no longer
     * re-deliver a stored code — the database holds only its one-way hash, so
     * the row is NEVER a bearer of display material. The honest shape is
     * ROTATION: the retry re-issues a FRESH code under the SAME claim id
     * (poll-continuity law preserved), an UPDATE rewrites the hash, nothing
     * INSERTs (no table growth under retry pile-up), and the remaining TTL is
     * carried forward untouched — re-issuing must not extend the pairing
     * window.
     */
    public function testHandleNewClaimRotatesCodeForDuplicateRequest(): void
    {
        $existingClaimId = 'claim-existing-123';
        $existingExpiresAt = time() + 300;

        $sharedJwk = $this->validEd25519Jwk();

        $db = $this->createMock(Connection::class);
        $updates = [];
        $inserts = 0;
        $db->method('query')
            ->willReturnCallback(function (
                string $sql,
                array $params,
            ) use (
                $existingClaimId,
                $existingExpiresAt,
                $sharedJwk,
                &$updates,
                &$inserts
) {
                if (str_contains($sql, 'SELECT') && str_contains($sql, 'claimed_by IS NULL')) {
                    return [[
                        'id' => $existingClaimId,
                        'expires_at' => $existingExpiresAt,
                        'public_key_jwk' => json_encode($sharedJwk),
                    ]];
                }
                if (str_contains($sql, 'UPDATE server_claims')) {
                    $updates[] = $params;
                }
                if (str_contains($sql, 'INSERT INTO server_claims')) {
                    $inserts++;
                }
                return [];
            });

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);
        $handler = new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');

        $request = new ClaimRequest(
            serverName: 'Duplicate Server',
            version: '0.11.0',
            publicKeysJwk: $sharedJwk,
            hostnameCandidates: [],
            protocolVersion: 'v1',
        );

        $response = $handler->handleNewClaim($request);

        self::assertSame($existingClaimId, $response->claimId);
        self::assertSame(9, strlen($response->claimCode));
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $response->claimCode);
        self::assertCount(1, $updates, 'the rotated code must rewrite the stored hash');
        self::assertSame(
            hash('sha256', strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $response->claimCode))),
            $updates[0]['claim_code_hash'] ?? null,
        );
        self::assertSame($existingClaimId, $updates[0]['id'] ?? null);
        self::assertSame(0, $inserts, 'a retry must not grow the table');
        self::assertSame(
            max(0, $existingExpiresAt - time()),
            $response->expiresIn,
            'rotation carries the REMAINING ttl — it never extends the pairing window',
        );
    }

    public function testGetClaimStatusReturnsPendingForUnclaimedRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'claim-1',
            'status' => 'pending',
            'claimed_by' => null,
            'paired_server_id' => null,
            'expires_at' => time() + 600,
        ]]);
        $handler = $this->makeHandler($db);

        self::assertSame(['status' => 'pending'], $handler->getClaimStatus('claim-1'));
    }

    public function testGetClaimStatusReturnsExpiredWhenMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $handler = $this->makeHandler($db);

        self::assertSame(['status' => 'expired'], $handler->getClaimStatus('nope'));
    }

    public function testGetClaimStatusReturnsExpiredWhenPastExpiry(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'claim-1',
            'status' => 'pending',
            'claimed_by' => null,
            'paired_server_id' => null,
            'expires_at' => time() - 1,
        ]]);
        $handler = $this->makeHandler($db);

        self::assertSame(['status' => 'expired'], $handler->getClaimStatus('claim-1'));
    }

    public function testGetClaimStatusReturnsEnrollmentWhenPaired(): void
    {
        $db = $this->createMock(Connection::class);
        // SH-049: consumption is DELETE-FIRST — the winning poll is the caller
        // whose DELETE reports one row. The follow-up DELETE answers 1 here.
        // Only server_claims traffic is ordered — minting the JWT reads its
        // TTL from hub_settings, and that SELECT belongs AFTER the win.
        $order = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$order) {
            if (str_contains($sql, 'server_claims')) {
                $order[] = str_contains($sql, 'SELECT') ? 'select' : 'delete';
            }
            if (str_contains($sql, 'SELECT')) {
                return [[
                    'id' => 'claim-1',
                    'status' => 'paired',
                    'claimed_by' => 'user-1',
                    'paired_server_id' => 'server-1',
                    'expires_at' => time() + 600,
                ]];
            }
            return 1;
        });
        $handler = $this->makeHandler($db);

        $result = $handler->getClaimStatus('claim-1');

        self::assertSame('claimed', $result['status']);
        self::assertSame('server-1', $result['server_id'] ?? null);
        self::assertSame('https://hub.example.com/.well-known/jwks.json', $result['hub_jwks_url'] ?? null);
        self::assertNotEmpty($result['enrollment_jwt'] ?? '');
        // A signed JWT is three dot-separated segments (two dots).
        self::assertSame(2, substr_count((string) ($result['enrollment_jwt'] ?? ''), '.'));
        self::assertSame(
            ['select', 'delete'],
            $order,
            'consumption must be the SECOND statement — mint only after winning the DELETE',
        );
    }

    /**
     * SH-049 (review hub__secaudit #3, single-winner consumption): two
     * concurrent polls of one paired claim used to BOTH mint enrollment JWTs
     * (SELECT → mint → DELETE ordered nothing). The DELETE's affected-rows
     * answer is now the arbiter: the loser (zero rows — the row vanished under
     * the winner's DELETE) reports `expired` and mints NOTHING.
     */
    public function testGetClaimStatusLoserOfConcurrentPollMintsNothing(): void
    {
        $db = $this->createMock(Connection::class);
        $order = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$order) {
            if (str_contains($sql, 'server_claims')) {
                $order[] = str_contains($sql, 'SELECT') ? 'select' : 'delete';
            }
            if (str_contains($sql, 'SELECT')) {
                return [[
                    'id' => 'claim-1',
                    'status' => 'paired',
                    'claimed_by' => 'user-1',
                    'paired_server_id' => 'server-1',
                    'expires_at' => time() + 600,
                ]];
            }
            return 0;
        });
        $handler = $this->makeHandler($db);

        $result = $handler->getClaimStatus('claim-1');

        self::assertSame(['status' => 'expired'], $result);
        self::assertSame(
            ['select', 'delete'],
            $order,
            'a lost consumption DELETE stops the flow — no JWT material is produced',
        );
    }

    /**
     * SH-049: the retry-dedupe window scan must not project any bearer form of
     * the pairing code either — the stored hash is a one-way secret-stand-in
     * and even IT has no reason to travel; the arm needs only id, TTL, and
     * the JWK to identify the retry.
     */
    public function testDuplicateLookupWindowProjectsNoCodeColumns(): void
    {
        $db = $this->createMock(Connection::class);
        $windowSql = null;
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use (&$windowSql) {
            if (str_contains($sql, 'claimed_by IS NULL')) {
                $windowSql = $sql;
            }
            return [];
        });
        $handler = $this->makeHandler($db);

        $handler->handleNewClaim(new ClaimRequest(
            serverName: 'Projection Probe',
            version: '0.11.0',
            publicKeysJwk: $this->validEd25519Jwk(),
            hostnameCandidates: [],
            protocolVersion: 'v1',
        ));

        self::assertIsString($windowSql, 'the new-claim path must consult the dedupe window');
        self::assertStringNotContainsString('claim_code', $windowSql);
    }

    /**
     * R19-01 + R20-04 + SH-049 law pins, driven through one full claim:
     *
     *  1. The locking read matches the hash column (`claim_code_hash = :hash`
     *     — migration 052) with equality on a UNIQUE index — still a single
     *     const-access row lock, and no REPLACE() anywhere.
     *  2. The bind param carries sha256 of the NORMALISED (stripped +
     *     uppercased) input, so lenient display variants still hit the row —
     *     and the plaintext never appears in SQL or params.
     *  3. Neither the logger nor the audit trail ever receives the claim code
     *     in plaintext (raw or normalised) — only the sha256 fingerprint under
     *     `claim_code_fp`. The code is a pairing bearer secret; logs and
     *     audit_logs outlive its 10-minute TTL by months.
     */
    public function testClaimLookupUsesIndexedNormColumnAndNeverLeadsPlaintextCode(): void
    {
        /** @var list<array{sql: string, params: array<string, mixed>}> $selects */
        $selects = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, $params = null) use (&$selects) {
                if (str_contains($sql, 'FOR UPDATE')) {
                    $selects[] = ['sql' => $sql, 'params' => is_array($params) ? $params : []];

                    return [[
                        'id' => 'claim-norm-1',
                        'expires_at' => time() + 600,
                        'claimed_by' => null,
                        'public_key_jwk' => '{"kty":"OKP","crv":"Ed25519","x":"aaa"}',
                        'hostname_candidates_json' => '[]',
                        'server_name' => 'Norm Server',
                        'version' => '0.12.0',
                    ]];
                }
                return [];
            });

        /** @var list<array<string, mixed>> $logContexts */
        $logContexts = [];
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context = []) use (&$logContexts): void {
                $logContexts[] = $context;
            },
        );
        /** @var list<array{reason: string, context: array<string, mixed>}> $auditRecords */
        $auditRecords = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason, array $context = []) use (&$auditRecords): void {
                $auditRecords[] = ['reason' => $reason, 'context' => $context];
            },
        );

        $handler = new ClaimRequestHandler(
            $db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            $logger,
            $audit,
            'https://hub.example.com',
        );

        // Lenient input: lowercase + dash + stray space must normalise to DR4Q7AXB.
        $handler->handleClaimCode('dr4q-7axb ', 'user-norm-1');

        self::assertCount(1, $selects, 'exactly one locking claim read');
        self::assertStringContainsString('WHERE claim_code_hash = :hash FOR UPDATE', $selects[0]['sql']);
        self::assertStringNotContainsString('REPLACE(', $selects[0]['sql']);
        self::assertStringNotContainsString('claim_code_norm', $selects[0]['sql']);
        $expectedHash = hash('sha256', 'DR4Q7AXB');
        self::assertSame(['hash' => $expectedHash], $selects[0]['params']);

        // No plaintext code anywhere in the SQL or the bind params — the
        // statement travels with the one-way form only (SH-049).
        self::assertStringNotContainsString('DR4Q', $selects[0]['sql']);

        // No plaintext code anywhere in the log or audit payloads.
        $haystack = json_encode(['logs' => $logContexts, 'audit' => $auditRecords], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('DR4Q-7AXB', $haystack);
        self::assertStringNotContainsString('DR4Q7AXB', $haystack);

        // The success record exists and carries exactly the fingerprint.
        self::assertNotEmpty($logContexts);
        $expectedFp = substr(hash('sha256', 'DR4Q7AXB'), 0, 8);
        self::assertSame(
            $expectedFp,
            ClaimRequestHandler::claimCodeFingerprint('dr4q-7axb '),
            'fingerprint is display-variant stable',
        );
        $found = false;
        foreach ($logContexts as $context) {
            if (($context['claim_code_fp'] ?? null) !== null) {
                self::assertSame($expectedFp, $context['claim_code_fp']);
                $found = true;
            }
        }
        self::assertTrue($found, 'success INFO must carry claim_code_fp');
    }

    private function makeHandler(Connection $db): ClaimRequestHandler
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $logger = $this->createMock(StructuredLogger::class);
        $audit = $this->createMock(AuditLogger::class);

        return new ClaimRequestHandler($db, $keyManager, $logger, $audit, 'https://hub.example.com');
    }

    /**
     * Valid Ed25519 JWK for testing.
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
}
