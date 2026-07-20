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
 *
 * @covers \Phlix\Hub\Http\Controllers\HubSettingsController
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

        // Minimal server.php with required keys for getDefault().
        file_put_contents($configDir . '/server.php', '<?php return [
            "enrollment_ttl" => 604800,
            "heartbeat_interval" => 60,
            "enrollment_renewal_threshold" => 86400,
            "subdomain_auto_claim" => true,
            "tls_enabled" => true,
            "domain" => "phlix.media",
            "public_domain" => "phlix.media",
            "relay_ping_interval" => 30,
            "max_servers_per_user" => 10,
        ];');

        // Minimal auth.php.
        file_put_contents($configDir . '/auth.php', '<?php return [
            "access_token_ttl" => 3600,
            "refresh_token_ttl" => 604800,
        ];');

        // Minimal logger.php.
        file_put_contents($configDir . '/logger.php', '<?php return [
            "level" => "info",
            "audit_enabled" => true,
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

        self::assertArrayHasKey('restart', $entry);
        self::assertTrue($entry['restart']);

        self::assertArrayHasKey('secret', $entry);
        self::assertFalse($entry['secret']);
    }

    public function testSchemaMetaForLoggerLevelHasEnum(): void
    {
        // Reset the static cache.
        $reflector = new \ReflectionClass(HubSettingsController::class);
        $prop = $reflector->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $meta = HubSettingsController::schemaMeta();

        self::assertArrayHasKey('logger.level', $meta);
        $entry = $meta['logger.level'];

        self::assertArrayHasKey('enum', $entry);
        self::assertIsArray($entry['enum']);
        self::assertContains('debug', $entry['enum']);
        self::assertContains('info', $entry['enum']);
        self::assertContains('warning', $entry['enum']);
        self::assertContains('error', $entry['enum']);
        self::assertContains('critical', $entry['enum']);

        self::assertArrayHasKey('enumLabels', $entry);
        self::assertIsArray($entry['enumLabels']);
        self::assertArrayHasKey('debug', $entry['enumLabels']);
        self::assertArrayHasKey('info', $entry['enumLabels']);

        self::assertArrayHasKey('default', $entry);
        self::assertSame('info', $entry['default']);
    }
}
