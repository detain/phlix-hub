<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\Controllers\ThemesController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * HUB-S48: `GET /api/v1/themes` serves the three built-ins in the server wire shape.
 */
final class ThemesControllerTest extends TestCase
{
    public function testIndexServesBuiltInCatalogueInServerWireShape(): void
    {
        $response = (new ThemesController())->index(new Request());

        self::assertSame(200, $response->statusCode);
        /** @var array{themes: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['nocturne', 'daylight', 'midnight'], array_column($body['themes'], 'id'));
        foreach ($body['themes'] as $theme) {
            self::assertSame(['id', 'name', 'dark', 'extends', 'tokens', 'source', 'builtIn'], array_keys($theme));
            self::assertTrue($theme['builtIn']);
            self::assertNull($theme['source']);
            self::assertNull($theme['extends']);
            self::assertIsArray($theme['tokens']);
            self::assertNotSame([], $theme['tokens']);
        }
    }
}
