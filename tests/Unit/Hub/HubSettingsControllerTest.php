<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Http\Controllers\HubSettingsController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HubSettingsController}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class HubSettingsControllerTest extends TestCase
{
    private HubSettingsController $controller;
    private HubSettingsRepository $repository;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-hubsettings-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // Create a temporary config directory with minimal config files for testing.
        $configDir = $this->tmpDir . '/config';
        mkdir($configDir, 0700, true);

        // Minimal server.php with the allow-listed keys for getDefault().
        file_put_contents($configDir . '/server.php', '<?php return [
            "enrollment_ttl" => 604800,
        ];');

        // Minimal auth.php. NOTE the key names: `access_ttl`/`refresh_ttl` are
        // what AuthServicesProvider reads, and therefore what the dotted
        // allow-list keys must address.
        file_put_contents($configDir . '/auth.php', '<?php return [
            "access_ttl" => 3600,
            "refresh_ttl" => 604800,
        ];');

        // Use an in-memory SQLite-like mock approach via a mock Connection.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $this->repository = new HubSettingsRepository($db, $configDir);
        $this->controller = new HubSettingsController($this->repository);
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
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    public function testGetSettingsContainsMeta(): void
    {
        // Reset the static cache before each test.
        $reflector = new \ReflectionClass(HubSettingsController::class);
        $prop = $reflector->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $request = new Request();
        $response = $this->controller->getSettings($request);

        self::assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body['data']);
        self::assertIsArray($body['data']['meta']);
    }

    public function testSchemaMetaReturnsNonEmptyMap(): void
    {
        // Reset the static cache.
        $reflector = new \ReflectionClass(HubSettingsController::class);
        $prop = $reflector->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $meta = HubSettingsController::schemaMeta();

        self::assertIsArray($meta);
        self::assertNotEmpty($meta, 'schemaMeta() must return a non-empty map');
    }

    public function testSchemaMetaForEnrollmentTtlHasRequiredFields(): void
    {
        // Reset the static cache.
        $reflector = new \ReflectionClass(HubSettingsController::class);
        $prop = $reflector->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $meta = HubSettingsController::schemaMeta();

        self::assertArrayHasKey('server.enrollment_ttl', $meta);
        $entry = $meta['server.enrollment_ttl'];

        self::assertArrayHasKey('label', $entry);
        self::assertSame('Enrollment token TTL', $entry['label']);

        self::assertArrayHasKey('helpText', $entry);
        self::assertNotEmpty($entry['helpText']);

        self::assertArrayHasKey('tier', $entry);
        self::assertSame('standard', $entry['tier']);

        self::assertArrayHasKey('group', $entry);
        self::assertSame('server', $entry['group']);

        self::assertArrayHasKey('minimum', $entry);
        self::assertSame(60.0, $entry['minimum']);

        self::assertArrayHasKey('maximum', $entry);
        self::assertSame(2592000.0, $entry['maximum']);

        self::assertArrayHasKey('default', $entry);
        self::assertSame(604800, $entry['default']);

        // LIVE, not boot-only: EnrollmentJwtService::effectiveTtl() calls
        // getEffective('server.enrollment_ttl') on every mint
        // (src/Hub/EnrollmentJwtService.php:73), so an override applies to the
        // next enrollment with no restart. Asserting `true` here would be
        // asserting that we lie to the operator in the UI.
        self::assertArrayHasKey('restart', $entry);
        self::assertFalse($entry['restart']);

        self::assertArrayHasKey('secret', $entry);
        self::assertFalse($entry['secret']);
    }

    /**
     * Every PUT-settable key must be renderable.
     *
     * `SettingsPage.vue` builds its tabs and field list EXCLUSIVELY from the
     * `meta` block (`tabs`/`tabKeys` are computed from `serverMeta`), so an
     * allow-listed key with no schema entry — or with no `group` — is settable
     * over the API yet invisible in the UI. Replaces the old single-key
     * `logger.level` enum assertion with the general contract, which also
     * catches allow-list ↔ schema drift.
     */
    public function testEveryAllowedKeyHasRenderableSchemaMeta(): void
    {
        // Reset the static cache.
        $reflector = new \ReflectionClass(HubSettingsController::class);
        $prop = $reflector->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $meta = HubSettingsController::schemaMeta();

        foreach (array_keys(HubSettingsRepository::ALLOWED_KEYS) as $key) {
            self::assertArrayHasKey($key, $meta, "allow-listed key '{$key}' has no schema meta");

            $entry = $meta[$key];
            self::assertIsString($entry['group'] ?? null, "'{$key}' needs a group or it renders on no tab");
            self::assertNotSame('', $entry['group'], "'{$key}' needs a non-empty group");
            self::assertIsString($entry['label'] ?? null, "'{$key}' needs a label");
            self::assertNotEmpty($entry['helpText'] ?? null, "'{$key}' needs helpText (§3.4 per-option help)");
            self::assertContains($entry['tier'] ?? null, ['standard', 'advanced'], "'{$key}' needs a valid tier");
        }
    }
}
