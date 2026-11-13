<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@gmail.com>
 * @license   MPL-2.0
 */

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
 * HUB-12: a crafted non-finite JWK member ({"use":1e999}) must map to the
 * existing SERVER_KEY_INVALID, and a stored unencodable JWK must never
 * fingerprint-match (no hash('') collision).
 */
final class ClaimJwkEncodeHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-hub12-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private static function x(): string
    {
        return rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '=');
    }

    private function handler(Connection $db): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            $this->createMock(StructuredLogger::class),
            $this->createMock(AuditLogger::class),
            'https://hub.example.com',
        );
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function request(array $jwk): ClaimRequest
    {
        return new ClaimRequest(
            serverName: 'S',
            version: '0.12.0',
            publicKeysJwk: $jwk,
            hostnameCandidates: [],
            protocolVersion: 'v1',
        );
    }

    public function testNonFiniteMemberIsServerKeyInvalidBeforeAnyWrite(): void
    {
        /** @var array<string, mixed> $jwk */
        $jwk = json_decode(
            '{"kty":"OKP","crv":"Ed25519","x":"' . self::x() . '","use":1e999}',
            true,
        );
        self::assertIsFloat($jwk['use']);
        $inserted = false;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$inserted): array {
                $inserted = $inserted || str_contains($sql, 'INSERT');
                return [];
            },
        );
        try {
            $this->handler($db)->handleNewClaim($this->request($jwk));
            self::fail('Expected SERVER_KEY_INVALID');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('SERVER_KEY_INVALID', $e->getMessage());
        }
        self::assertFalse($inserted);
    }

    public function testStoredUnencodableJwkNeverMatchesAndFingerprintThrows(): void
    {
        $valid = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::x()];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM server_claims') && str_contains($sql, 'ORDER BY')) {
                    return [['public_key_jwk' => '{"kty":"OKP","use":1e999}', 'id' => 'x']];
                }
                return [];
            },
        );
        $h = $this->handler($db);
        $m = new \ReflectionMethod($h, 'jwkFingerprint');
        try {
            $m->invoke($h, ['use' => INF]);
            self::fail('jwkFingerprint must throw, not hash empty string');
        } catch (\JsonException) {
            self::addToAssertionCount(1);
        }
        $f = new \ReflectionMethod($h, 'findExistingPendingClaim');
        self::assertNull($f->invoke($h, $valid));
    }
}
