<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Controllers\HubJwksController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HubJwksController}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Http\Controllers\HubJwksController
 */
final class JwksControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-jwks-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        // HB-4.6d: exercise the REAL per-surface JWKS RateLimiter (120/60s)
        // rather than a limited:false stub — a handful of hits never trips.
        $this->rateLimiter = new RateLimiter(60, 120);
    }

    /** @var RateLimiterInterface */
    private $rateLimiter;

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

    public function testServesValidJwksDocument(): void
    {
        $keyPath = $this->tmpDir . '/key.pem';
        $keyManager = new Ed25519KeyManager($keyPath);
        $controller = new HubJwksController($keyManager, $this->rateLimiter);

        $request = new Request();
        $response = $controller($request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('public, max-age=3600', $response->headers['Cache-Control']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('keys', $body);
        self::assertCount(1, $body['keys']);

        $key = $body['keys'][0];
        self::assertSame('OKP', $key['kty']);
        self::assertSame('Ed25519', $key['crv']);
        self::assertSame('sig', $key['use']);
        self::assertSame('EdDSA', $key['alg']);
    }

    public function testJwksIsCacheable(): void
    {
        $keyPath = $this->tmpDir . '/key.pem';
        $keyManager = new Ed25519KeyManager($keyPath);
        $controller = new HubJwksController($keyManager, $this->rateLimiter);

        $request = new Request();
        $response = $controller($request);

        self::assertStringContainsString('max-age=3600', $response->headers['Cache-Control']);
    }

    /**
     * S7: during a rotation overlap the JWKS lists BOTH kids; after the overlap
     * window lapses only the current kid remains.
     */
    public function testJwksListsBothKidsDuringOverlapThenOneAfter(): void
    {
        $now = 7_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $keyPath = $this->tmpDir . '/rotating-key.pem';
        $keyManager = new Ed25519KeyManager($keyPath, $clock);
        $keyManager->getOrCreateKeyPair();
        $oldKid = $keyManager->getKid();

        $keyManager->rotate();
        $newKid = $keyManager->getKid();

        $controller = new HubJwksController($keyManager, $this->rateLimiter);

        // During overlap: two keys, both kids.
        $body = json_decode($controller(new Request())->body, true);
        self::assertIsArray($body);
        self::assertCount(2, $body['keys']);
        $kids = array_map(static fn (array $k): string => (string) $k['kid'], $body['keys']);
        self::assertEqualsCanonicalizing([$newKid, $oldKid], $kids);

        // After the overlap window lapses: only the current kid.
        $now += Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;
        $bodyAfter = json_decode($controller(new Request())->body, true);
        self::assertIsArray($bodyAfter);
        self::assertCount(1, $bodyAfter['keys']);
        self::assertSame($newKid, $bodyAfter['keys'][0]['kid']);
    }

    /**
     * HB-4.6d: the JWKS limiter is keyed by client IP (`jwks:{ip}`) and trips
     * only after exceeding its generous 120/60s budget — the 121st hit from one
     * IP throws {@see RateLimitException}. Confirms a REAL limiter, not a stub.
     */
    public function testJwksLimiterTripsAfterExceeding120PerIpInWindow(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        // Frozen clock → all hits fall in one window.
        $limiter = new RateLimiter(60, 120, 10000, static fn (): int => 2_000_000);
        $controller = new HubJwksController($keyManager, $limiter);

        $request = new Request();
        $request->remoteIp = '203.0.113.7';

        // 119 hits are within budget; the limiter trips when the count reaches
        // the 120/60s ceiling (limited when count >= max).
        for ($i = 0; $i < 119; $i++) {
            self::assertSame(200, $controller($request)->statusCode, 'hit #' . $i . ' within budget');
        }

        $this->expectException(RateLimitException::class);
        $controller($request);
    }
}
