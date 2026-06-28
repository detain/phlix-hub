<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Shared\Hub\ClaimRequest;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ClaimRequestHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\ClaimRequestHandler
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
        self::assertIsArray($inserted);
        self::assertSame($response->claimId, $inserted['id']);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, (string) $inserted['id']);
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

    public function testHandleNewClaimReturnsExistingCodeForDuplicateRequest(): void
    {
        $existingCode = 'ABCD-1234';
        $existingClaimId = 'claim-existing-123';

        $sharedJwk = $this->validEd25519Jwk();

        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($existingCode, $existingClaimId, $sharedJwk) {
                if (str_contains($sql, 'SELECT') && str_contains($sql, 'claimed_by IS NULL')) {
                    return [[
                        'id' => $existingClaimId,
                        'claim_code' => $existingCode,
                        'expires_at' => time() + 300,
                        'public_key_jwk' => json_encode($sharedJwk),
                    ]];
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

        self::assertSame($existingCode, $response->claimCode);
        self::assertSame($existingClaimId, $response->claimId);
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
        // SELECT returns the paired row; the follow-up DELETE returns null.
        $db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'SELECT')) {
                return [[
                    'id' => 'claim-1',
                    'status' => 'paired',
                    'claimed_by' => 'user-1',
                    'paired_server_id' => 'server-1',
                    'expires_at' => time() + 600,
                ]];
            }
            return null;
        });
        $handler = $this->makeHandler($db);

        $result = $handler->getClaimStatus('claim-1');

        self::assertSame('claimed', $result['status']);
        self::assertSame('server-1', $result['server_id'] ?? null);
        self::assertSame('https://hub.example.com/.well-known/jwks.json', $result['hub_jwks_url'] ?? null);
        self::assertNotEmpty($result['enrollment_jwt'] ?? '');
        // A signed JWT is three dot-separated segments (two dots).
        self::assertSame(2, substr_count((string) ($result['enrollment_jwt'] ?? ''), '.'));
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
