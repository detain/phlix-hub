<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\HubSettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HubSettingsRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\HubSettingsRepository
 */
final class HubSettingsRepositoryTest extends TestCase
{
    private function repo(Connection $db, ?string $configDir = null): HubSettingsRepository
    {
        return new HubSettingsRepository($db, $configDir);
    }

    // ------------------------------------------------------------ getOverride

    public function testGetOverrideReturnsDecodedValueWhenRowExists(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '3600', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('auth.access_ttl');

        self::assertNotNull($result);
        self::assertSame(3600, $result['value']);
        self::assertSame('int', $result['value_type']);
    }

    public function testGetOverrideReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db);
        self::assertNull($repo->getOverride('auth.access_ttl'));
    }

    public function testGetOverrideDecodesBoolTrue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '1', 'value_type' => 'bool'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.bool_key');

        self::assertNotNull($result);
        self::assertTrue($result['value']);
    }

    public function testGetOverrideDecodesBoolFalse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '0', 'value_type' => 'bool'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.bool_key');

        self::assertNotNull($result);
        self::assertFalse($result['value']);
    }

    public function testGetOverrideDecodesFloat(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '3.14', 'value_type' => 'float'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.float_key');

        self::assertNotNull($result);
        self::assertSame(3.14, $result['value']);
    }

    public function testGetOverrideDecodesJson(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '{"foo":"bar"}', 'value_type' => 'json'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.json_key');

        self::assertNotNull($result);
        self::assertSame(['foo' => 'bar'], $result['value']);
    }

    public function testGetOverrideDefaultsToStringType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => 'hello', 'value_type' => null],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.string_key');

        self::assertNotNull($result);
        self::assertSame('hello', $result['value']);
        self::assertSame('string', $result['value_type']);
    }

    // --------------------------------------------------------- getAllOverrides

    public function testGetAllOverridesReturnsKeyedMap(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_key' => 'auth.access_ttl', 'setting_value' => '3600', 'value_type' => 'int'],
            ['setting_key' => 'auth.refresh_ttl', 'setting_value' => '86400', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getAllOverrides();

        self::assertIsArray($result);
        self::assertSame(3600, $result['auth.access_ttl']);
        self::assertSame(86400, $result['auth.refresh_ttl']);
    }

    public function testGetAllOverridesReturnsEmptyArrayWhenNoRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db);
        self::assertSame([], $repo->getAllOverrides());
    }

    public function testGetAllOverridesSkipsMalformedRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_key' => 'auth.access_ttl', 'setting_value' => '3600', 'value_type' => 'int'],
            ['setting_key' => null, 'setting_value' => 'x', 'value_type' => 'string'], // malformed
            ['setting_key' => 'auth.refresh_ttl', 'setting_value' => '86400', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getAllOverrides();

        self::assertCount(2, $result);
        self::assertArrayHasKey('auth.access_ttl', $result);
        self::assertArrayHasKey('auth.refresh_ttl', $result);
    }

    // --------------------------------------------------------------- set

    public function testSetUsesUpsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringStartsWith('INSERT INTO hub_settings'),
                self::isType('array')
            );

        $repo = $this->repo($db);
        $repo->set('auth.access_ttl', 3600, 'int');
    }

    // ----------------------------------------------------------- getDefault

    public function testGetDefaultReadsFromConfigFile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // no overrides

        // Use realpath to get correct config path from test file location
        $configDir = realpath(__DIR__ . '/../../../config');
        self::assertNotFalse($configDir, 'config directory should exist');
        $repo = $this->repo($db, $configDir);
        $result = $repo->getDefault('auth.access_ttl');

        // auth.php has access_ttl defined - value depends on env vars
        self::assertNotNull($result);
    }

    public function testGetDefaultReturnsNullForUnknownKey(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault('nonexistent.file.key'));
    }

    public function testGetDefaultReturnsNullForUnknownFile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault('unknownfile.some_key'));
    }

    public function testGetDefaultReturnsNullForEmptyKey(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault(''));
    }

    public function testGetDefaultNavigatesNestedKeys(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        // server.php has nested 'arr' key with 'sonarr' containing 'api_key' (empty string in dev config)
        $result = $repo->getDefault('server.arr.sonarr.api_key');
        // The nested navigation works - value is empty string from config
        self::assertSame('', $result);
    }

    // --------------------------------------------------------- getEffective

    public function testGetEffectiveReturnsOverrideWhenPresent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['setting_value' => '7200', 'value_type' => 'int']], // getOverride call
        );

        $repo = $this->repo($db);
        $result = $repo->getEffective('auth.access_ttl');

        self::assertSame(7200, $result);
    }

    public function testGetEffectiveFallsBackToDefaultWhenNoOverride(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [], // getOverride returns null (no override)
        );

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $result = $repo->getEffective('auth.access_ttl');

        // Should fall back to config default
        self::assertNotNull($result);
    }

    // ----------------------------------------------------- getEffectiveMany

    public function testGetEffectiveManyReturnsValuesAndOverriddenList(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [ // getAllOverrides
                ['setting_key' => 'auth.access_ttl', 'setting_value' => '7200', 'value_type' => 'int'],
            ],
        );

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $keys = ['auth.access_ttl', 'auth.refresh_ttl'];
        $result = $repo->getEffectiveMany($keys);

        self::assertArrayHasKey('values', $result);
        self::assertArrayHasKey('overridden', $result);
        self::assertSame(7200, $result['values']['auth.access_ttl']);
        self::assertContains('auth.access_ttl', $result['overridden']);
        self::assertNotContains('auth.refresh_ttl', $result['overridden']);
    }

    public function testGetEffectiveManyReturnsDefaultsWhenNotOverridden(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // no overrides

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $keys = ['auth.access_ttl'];
        $result = $repo->getEffectiveMany($keys);

        self::assertSame([], $result['overridden']);
        self::assertArrayHasKey('auth.access_ttl', $result['values']);
    }

    // -------------------------------------------------------- ALLOWED_KEYS

    public function testAllowedKeysContainsExpectedEntries(): void
    {
        self::assertArrayHasKey('server.enrollment_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.access_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.refresh_ttl', HubSettingsRepository::ALLOWED_KEYS);
    }

    public function testDeniedKeysContainsSecrets(): void
    {
        self::assertContains('auth.secret', HubSettingsRepository::DENIED_KEYS);
        self::assertContains('server.hub_base_url', HubSettingsRepository::DENIED_KEYS);
        self::assertContains('server.tls_enabled', HubSettingsRepository::DENIED_KEYS);
    }
}
