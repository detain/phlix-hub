<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Router} path matching — focused on the `{name}` single-segment
 * vs `{name:regex}` (multi-segment) placeholder support that lets the SPA shell serve
 * deep links like `/app/player/abc` (phlix_ui_missing.md #5).
 */
final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        $r = new Request();
        $r->method = $method;
        $r->path = $path;
        return $r;
    }

    public function testSingleSegmentParamDoesNotMatchAcrossSlashes(): void
    {
        $router = new Router();
        $router->get(
            '/api/v1/servers/{id}',
            static fn (Request $r, array $p): Response => (new Response())->status(200)->json($p)
        );

        $hit = $router->dispatch($this->request('GET', '/api/v1/servers/abc'));
        self::assertSame(200, $hit->statusCode);

        // a second segment must NOT be swallowed by the single-segment {id}
        $miss = $router->dispatch($this->request('GET', '/api/v1/servers/abc/extra'));
        self::assertSame(404, $miss->statusCode);
    }

    public function testGreedyParamMatchesMultipleSegments(): void
    {
        $captured = null;
        $router = new Router();
        $router->get('/app/{path:.*}', static function (Request $r, array $p) use (&$captured): Response {
            $captured = $p['path'] ?? null;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        foreach (['/app/browse', '/app/player/abc', '/app/media/123'] as $path) {
            $res = $router->dispatch($this->request('GET', $path));
            self::assertSame(200, $res->statusCode, "deep link {$path} should serve the shell");
        }
        self::assertSame('media/123', $captured); // last dispatch captured the full tail
    }

    public function testGreedyParamCapturesTheFullTail(): void
    {
        $captured = null;
        $router = new Router();
        $router->get('/app/{path:.*}', static function (Request $r, array $p) use (&$captured): Response {
            $captured = $p['path'] ?? null;
            return (new Response())->status(200)->json(['ok' => true]);
        });

        $router->dispatch($this->request('GET', '/app/player/abc'));
        self::assertSame('player/abc', $captured);
    }
}
