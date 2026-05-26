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
