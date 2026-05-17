<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Hub;

use Phlex\Hub\Hub\Ed25519KeyManager;
use Phlex\Hub\Http\Controllers\HubJwksController;
use Phlex\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HubJwksController}.
 *
 * @package Phlex\Hub\Tests\unit\Hub
 * @since 0.3.0
 *
 * @covers \Phlex\Hub\Http\Controllers\HubJwksController
 */
final class JwksControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-jwks-test-' . uniqid();
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

    public function testServesValidJwksDocument(): void
    {
        $keyPath = $this->tmpDir . '/key.pem';
        $keyManager = new Ed25519KeyManager($keyPath);
        $controller = new HubJwksController($keyManager);

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
        $controller = new HubJwksController($keyManager);

        $request = new Request();
        $response = $controller($request);

        self::assertStringContainsString('max-age=3600', $response->headers['Cache-Control']);
    }
}
