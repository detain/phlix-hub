<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
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
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->method('hit')->willReturn(new RateLimitState(1, 4, time() + 900, false, 5));
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
}
