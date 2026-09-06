<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see EnrollmentJwtService}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class EnrollmentJwtServiceTest extends TestCase
{
    use DecodedJsonAssertions;

    private string $tmpDir;
    private Ed25519KeyManager $keyManager;
    private EnrollmentJwtService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-enrollment-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $keyPath = $this->tmpDir . '/signing-key.pem';
        $this->keyManager = new Ed25519KeyManager($keyPath);
        $this->service = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com');
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

    public function testCreateEnrollmentJwtReturnsWellFormedJwt(): void
    {
        $token = $this->service->createEnrollmentJwt('server-uuid-123');

        $parts = explode('.', $token);
        self::assertCount(3, $parts);
    }

    public function testCreateEnrollmentJwtRoundTrips(): void
    {
        $serverId = 'server-uuid-456';
        $token = $this->service->createEnrollmentJwt($serverId);
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($token, $kid);

        self::assertNotNull($payload);
        self::assertSame('phlix-hub', $payload['iss']);
        self::assertSame('server', $payload['aud']);
        self::assertSame($serverId, $payload['sub']);
        self::assertSame($serverId, $payload['server_id']);
        self::assertSame('https://hub.example.com', $payload['hub_base_url']);
    }

    public function testValidateEnrollmentJwtReturnsNullForWrongKid(): void
    {
        $token = $this->service->createEnrollmentJwt('server-xyz');
        $payload = $this->service->validateEnrollmentJwt($token, 'wrong-kid');
        self::assertNull($payload);
    }

    /**
     * Regression: a token minted before a hub restart must still validate
     * afterwards. The kid is a fingerprint of the persisted key (stable across
     * restarts), not a per-process timestamp — an earlier version timestamped
     * the kid in the constructor, so a fresh manager on the same key file
     * produced a different kid and rejected every outstanding token as a
     * spurious ENROLLMENT_TOKEN_EXPIRED on heartbeat.
     */
    public function testEnrollmentJwtSurvivesKeyManagerReload(): void
    {
        $keyPath = $this->tmpDir . '/signing-key.pem';
        // Mint under the first manager (generates + persists the key).
        $token = $this->service->createEnrollmentJwt('server-reload');
        $originalKid = $this->keyManager->getKid();

        // Simulate a restart: a brand-new manager loading the SAME key file.
        $reloadedManager = new Ed25519KeyManager($keyPath);
        $reloadedService = new EnrollmentJwtService($reloadedManager, 'https://hub.example.com');

        self::assertSame(
            $originalKid,
            $reloadedManager->getKid(),
            'kid must be stable across reloads of the same key',
        );

        $decodedHeader = self::arrayNode(json_decode(
            (string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true),
            true,
        ));
        $tokenKid = $decodedHeader['kid'] ?? null;
        self::assertIsString($tokenKid, 'the minted header must carry a kid');

        $payload = $reloadedService->validateEnrollmentJwt($token, $tokenKid);
        self::assertNotNull($payload, 'token minted before reload must still validate after');
        self::assertSame('server-reload', $payload['server_id']);
    }

    public function testValidateEnrollmentJwtReturnsNullForTamperedToken(): void
    {
        $token = $this->service->createEnrollmentJwt('server-tampered');
        // Flip a byte in the middle of the signature (last segment) — single
        // last-char flips can collide due to base64url padding bits.
        $parts = explode('.', $token);
        $sig = $parts[2];
        $mid = (int) floor(strlen($sig) / 2);
        $orig = $sig[$mid];
        $parts[2] = substr($sig, 0, $mid) . ($orig === 'A' ? 'B' : 'A') . substr($sig, $mid + 1);
        $tampered = implode('.', $parts);
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($tampered, $kid);
        self::assertNull($payload);
    }

    public function testCreateEnrollmentJwtWithCustomTtl(): void
    {
        $token = $this->service->createEnrollmentJwt('server-ttl', 3600);
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($token, $kid);
        self::assertNotNull($payload);
        self::assertGreaterThan(time(), $payload['exp']);
        self::assertLessThanOrEqual(time() + 3605, $payload['exp']);
    }

    public function testGetHubJwksUrlReturnsCorrectUrl(): void
    {
        $url = $this->service->getHubJwksUrl();
        self::assertSame('https://hub.example.com/.well-known/jwks.json', $url);
    }

    public function testGetHubBaseUrlReturnsBaseWithoutTrailingSlash(): void
    {
        $service = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com/');
        self::assertSame('https://hub.example.com', $service->getHubBaseUrl());
    }

    public function testValidateEnrollmentJwtReturnsNullForExpiredToken(): void
    {
        $keyPath = $this->tmpDir . '/expired.pem';
        $km = new Ed25519KeyManager($keyPath);
        $service = new EnrollmentJwtService($km, 'https://hub.example.com');

        $token = $service->createEnrollmentJwt('server-expired', -10);
        $payload = $service->validateEnrollmentJwt($token, $km->getKid());
        self::assertNull($payload);
    }

    /**
     * S7: a JWT minted under the pre-rotation key must keep validating during
     * the rotation overlap window, then be rejected once the window lapses,
     * while a freshly-minted token under the new key validates throughout.
     */
    public function testEnrollmentJwtValidThroughRotationOverlapThenRejected(): void
    {
        $now = 5_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $keyPath = $this->tmpDir . '/rotating-key.pem';
        $km = new Ed25519KeyManager($keyPath, $clock);
        $service = new EnrollmentJwtService($km, 'https://hub.example.com');

        // Mint a 7-day enrollment JWT under the original key.
        $token = $service->createEnrollmentJwt('server-rotate', 604800);
        $oldKid = $km->getKid();

        // Rotate: original key becomes the retained previous key.
        $km->rotate();
        $newKid = $km->getKid();
        self::assertNotSame($oldKid, $newKid);

        // During the overlap window the pre-rotation token still validates.
        $payload = $service->validateEnrollmentJwt($token, $oldKid);
        self::assertNotNull($payload, 'pre-rotation token must validate during overlap');
        self::assertSame('server-rotate', $payload['server_id']);

        // A token minted under the new key validates too.
        $newToken = $service->createEnrollmentJwt('server-new', 604800);
        self::assertNotNull(
            $service->validateEnrollmentJwt($newToken, $newKid),
            'current-key token must validate',
        );

        // Advance past the 24h overlap window (but the 7-day token has NOT yet
        // expired) — the pre-rotation token must now be rejected on kid alone.
        $now += Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;
        self::assertNull(
            $service->validateEnrollmentJwt($token, $oldKid),
            'pre-rotation token must be rejected after overlap window lapses',
        );

        // The current-key token still validates after the overlap window.
        self::assertNotNull(
            $service->validateEnrollmentJwt($newToken, $newKid),
            'current-key token must still validate after overlap window',
        );
    }

    /**
     * S8: a token advertising `alg:none` must be rejected even though it is
     * otherwise correctly signed (Ed25519) over the forged header.
     */
    public function testValidateRejectsAlgNoneToken(): void
    {
        $kid = $this->keyManager->getKid();
        $token = $this->forgeSignedToken(['alg' => 'none', 'typ' => 'JWT', 'kid' => $kid], 'server-none');
        self::assertNull($this->service->validateEnrollmentJwt($token, $kid));
    }

    /**
     * S8: a token advertising a mismatched algorithm (e.g. HS256) must be
     * rejected — defends against alg-confusion / downgrade.
     */
    public function testValidateRejectsMismatchedAlgToken(): void
    {
        $kid = $this->keyManager->getKid();
        $token = $this->forgeSignedToken(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $kid], 'server-hs256');
        self::assertNull($this->service->validateEnrollmentJwt($token, $kid));
    }

    /**
     * S8: a non-`JWT` `typ` must be rejected.
     */
    public function testValidateRejectsUnexpectedTypHeader(): void
    {
        $kid = $this->keyManager->getKid();
        $token = $this->forgeSignedToken(['alg' => 'EdDSA', 'typ' => 'JWE', 'kid' => $kid], 'server-jwe');
        self::assertNull($this->service->validateEnrollmentJwt($token, $kid));
    }

    /**
     * Forge a JWT with an attacker-chosen header but a VALID Ed25519
     * signature over that header+payload, so only the alg/typ pin (not the
     * signature check) can reject it.
     *
     * @param array<string, string> $header
     */
    private function forgeSignedToken(array $header, string $serverId): string
    {
        $b64 = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $now = time();
        $payload = [
            'iss' => 'phlix-hub',
            'sub' => $serverId,
            'aud' => 'server',
            'exp' => $now + 3600,
            'iat' => $now,
            'kid' => $header['kid'] ?? '',
            'hub_base_url' => 'https://hub.example.com',
            'server_id' => $serverId,
        ];
        $h = $b64((string) json_encode($header));
        $p = $b64((string) json_encode($payload));
        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $privateKey = $keyPair['private'];
        if ($privateKey === '') {
            self::fail('the key manager must return a non-empty private key');
        }
        $sig = sodium_crypto_sign_detached("{$h}.{$p}", $privateKey);
        return "{$h}.{$p}." . $b64($sig);
    }
}
