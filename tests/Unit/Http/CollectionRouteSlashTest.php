<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Guards the trailing-slash contract on `/me/*` collection endpoints.
 *
 * ## The bug this exists to prevent
 *
 * {@see Router::addRoute()} builds an anchored `#^{prefix}{path}$#` and
 * {@see Request} performs no trailing-slash normalisation. A group registering
 * only `'/'` therefore serves **only** the slashed form, and the slashless form
 * 404s.
 *
 * That shipped. `/api/v1/me/shares` and `/api/v1/me/invite-links` registered
 * `'/'` alone, and the SPA called them WITHOUT the slash:
 *
 * | page | path called | result |
 * |---|---|---|
 * | `SharedWithMePage.vue:67` | `/api/v1/me/shares` | **404 — page could never load** |
 * | `ManageSharesPage.vue:73` | `/api/v1/me/shares/` | 200 — same controller, worked |
 * | `InviteLinksPage.vue` (list + create) | `/api/v1/me/invite-links` | **404** |
 *
 * Confirmed against production before the fix: the slashless forms returned 404
 * while the slashed forms returned 401 (route present, auth-gated), and a
 * deliberately fake sibling path also returned 404 — so the 401/404 split is
 * meaningful rather than coincidental.
 *
 * **Why no existing test caught it:** the controller tests set `$request->path`
 * and invoke the controller directly, bypassing the Router entirely. Any guard
 * for this class of defect MUST dispatch through a real {@see Router}.
 *
 * @covers \Phlix\Hub\Http\Router
 */
final class CollectionRouteSlashTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        $r = new Request();
        $r->method = $method;
        $r->path = $path;

        return $r;
    }

    private static function ok(): callable
    {
        return static fn (Request $r): Response => (new Response())->status(200)->json(['ok' => true]);
    }

    /**
     * The trap itself, pinned. If someone later "simplifies" the double
     * registration back to a single `'/'`, this documents what breaks.
     */
    public function test_registering_only_a_slash_does_not_serve_the_slashless_path(): void
    {
        $router = new Router();
        $router->group('/api/v1/me/things', static function (Router $r): void {
            $r->get('/', self::ok());
        });

        self::assertSame(200, $router->dispatch($this->request('GET', '/api/v1/me/things/'))->statusCode);
        self::assertSame(
            404,
            $router->dispatch($this->request('GET', '/api/v1/me/things'))->statusCode,
            'A group registering only "/" cannot serve the slashless collection path.',
        );
    }

    /** And the mirror image, so the asymmetry is unambiguous. */
    public function test_registering_only_empty_does_not_serve_the_slashed_path(): void
    {
        $router = new Router();
        $router->group('/api/v1/me/things', static function (Router $r): void {
            $r->get('', self::ok());
        });

        self::assertSame(200, $router->dispatch($this->request('GET', '/api/v1/me/things'))->statusCode);
        self::assertSame(404, $router->dispatch($this->request('GET', '/api/v1/me/things/'))->statusCode);
    }

    /** Registering both is additive — same handler, either path, no collision. */
    public function test_registering_both_forms_serves_both_paths(): void
    {
        $router = new Router();
        $router->group('/api/v1/me/things', static function (Router $r): void {
            $handler = self::ok();
            $r->get('', $handler);
            $r->get('/', $handler);
        });

        self::assertSame(200, $router->dispatch($this->request('GET', '/api/v1/me/things'))->statusCode);
        self::assertSame(200, $router->dispatch($this->request('GET', '/api/v1/me/things/'))->statusCode);
    }

    /**
     * Drift guard at the REAL registration site.
     *
     * The router tests above prove the mechanism but would stay green if
     * `Application.php` regressed, so assert against its source directly. This is
     * deliberately a source assertion: booting `Application` needs a container and
     * a live database, and the alternative — re-declaring the routes in the test —
     * would guard a copy rather than the thing that ships.
     *
     * @dataProvider collectionGroups
     */
    public function test_application_registers_both_forms_for_user_collections(string $group, string $verb): void
    {
        $src = file_get_contents(__DIR__ . '/../../../src/Application.php');
        self::assertIsString($src);

        $start = strpos($src, "group('/api/v1/me/{$group}'");
        self::assertNotFalse($start, "Group /api/v1/me/{$group} is no longer registered.");
        $block = substr($src, $start, 1600);

        self::assertStringContainsString(
            "\$r->{$verb}('',",
            $block,
            "/api/v1/me/{$group} must register {$verb} at '' — the SPA calls the slashless form.",
        );
        self::assertStringContainsString(
            "\$r->{$verb}('/',",
            $block,
            "/api/v1/me/{$group} must keep {$verb} at '/' — existing callers use the slashed form.",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function collectionGroups(): array
    {
        return [
            'shares list'          => ['shares', 'get'],
            'shares create'        => ['shares', 'post'],
            'invite-links list'    => ['invite-links', 'get'],
            'invite-links create'  => ['invite-links', 'post'],
        ];
    }
}
