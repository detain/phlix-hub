<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Http\Controllers\HubSettingsController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * W5 tests for the schema-sourced settings meta and the PUT bounds law on
 * {@see HubSettingsController}.
 *
 * Two halves:
 * 1. schemaMeta() must describe EVERY allow-listed key, sourced solely from
 *    the vendored `hub-settings.schema.json` — the fourteen W5 keys were
 *    upstreamed into that schema at `detain/phlix-shared` v0.52.0 and the
 *    hub-local SUPPLEMENTAL_META bridge that carried them pre-pin was
 *    deleted (a reflection pin below keeps the deletion permanent);
 * 2. writes outside [minimum, maximum] are rejected 400 (closing the F-09
 *    "advisory only" residual) — including the honest boundary values and the
 *    0-is-a-real-value semantics of the cap keys.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class HubSettingsBoundsAndMetaTest extends TestCase
{
    use DecodedJsonAssertions;

    private HubSettingsController $controller;

    private InMemoryHubSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Fresh static cache per test (vendored schema projection).
        $prop = (new ReflectionClass(HubSettingsController::class))->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->db         = new InMemoryHubSettingsConnection();
        $this->controller = new HubSettingsController(new HubSettingsRepository($this->db));
    }

    protected function tearDown(): void
    {
        $prop = (new ReflectionClass(HubSettingsController::class))->getProperty('schemaMeta');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        parent::tearDown();
    }

    /** @param array<string, mixed> $settings Raw PUT payload map (junk allowed on purpose). */
    private function put(array $settings): \Phlix\Hub\Http\Response
    {
        $request         = new Request();
        $request->method = 'PUT';
        $request->path   = '/api/v1/admin/settings';
        $request->userId = 'admin-1';
        $request->body   = ['settings' => $settings];

        return $this->controller->putSettings($request);
    }

    // -------------------------------------------------------- meta coverage

    public function testSchemaMetaDescribesEveryAllowedKey(): void
    {
        $meta = HubSettingsController::schemaMeta();

        foreach (array_keys(HubSettingsRepository::ALLOWED_KEYS) as $key) {
            self::assertArrayHasKey($key, $meta, "meta must exist for allow-listed key {$key}");
            $block = self::arrayNode($meta[$key]);
            self::assertArrayHasKey('label', $block, "{$key} needs a label for the SPA");
            self::assertArrayHasKey('helpText', $block, "{$key} needs help text for the SPA");
            self::assertArrayHasKey('restart', $block, "{$key} must carry an honest restart flag");
        }
    }

    public function testRestartTrueIsExactlyTheBootConsumedKeys(): void
    {
        $meta      = HubSettingsController::schemaMeta();
        $restartOn = [];
        foreach ($meta as $key => $block) {
            if (($block['restart'] ?? null) === true) {
                $restartOn[] = $key;
            }
        }

        sort($restartOn);
        self::assertSame(
            ['server.metrics.enabled', 'server.rate_limit'],
            $restartOn,
            'only per-worker boot-resolved settings claim restart:true; every W5 toggle is runtime-live',
        );
    }

    public function testNumericCapKeysCarryMandatoryBounds(): void
    {
        $meta = HubSettingsController::schemaMeta();

        foreach (
            [
            'invite.default_expiry_seconds'               => [0, 31536000],
            'server.max_servers_per_user'                 => [0, 1000],
            'server.max_users_per_server'                 => [0, 10000],
            'server.metrics.retention_days'               => [1, 3650],
            'server.relay.reconnect_drain_grace_seconds'  => [0, 300],
            ] as $key => [$min, $max]
        ) {
            $block = self::arrayNode($meta[$key]);
            self::assertSame($min, $block['minimum'], "{$key} minimum");
            self::assertSame($max, $block['maximum'], "{$key} maximum");
        }
    }

    public function testMetaIsSourcedSolelyFromTheVendoredSchema(): void
    {
        // Bridge-era provenance, rotated 2026-10-07 at the shared v0.52.0
        // re-pin: the schema property set and the served meta key set are now
        // the SAME set in the SAME order — no hub-local additions merge over
        // or under the schema output anymore.
        $schemaPath = dirname(__DIR__, 3)
            . '/vendor/detain/phlix-shared/schemas/hub-settings.schema.json';
        /** @var array<string, mixed> $schema */
        $schema = json_decode(
            (string) file_get_contents($schemaPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];

        $meta = HubSettingsController::schemaMeta();

        self::assertSame(array_keys($properties), array_keys($meta));

        // Every served label/restart is the vendored value, verbatim:
        $vendored = self::arrayNode($properties['server.enrollment_ttl']);
        self::assertSame($vendored['label'], $meta['server.enrollment_ttl']['label']);
        self::assertSame($vendored['restart'], $meta['server.enrollment_ttl']['restart']);
    }

    public function testSupplementalMetaBridgeIsDeleted(): void
    {
        // The bridge's yield contract: once upstream ships every W5 key (done
        // at shared v0.52.0), the hub-local projection is dead weight — and it
        // must STAY dead, or drift between two meta sources becomes possible
        // again. Pin its absence.
        self::assertFalse(
            (new ReflectionClass(HubSettingsController::class))->hasConstant('SUPPLEMENTAL_META'),
            'HubSettingsController::SUPPLEMENTAL_META must not return — the vendored schema is the sole meta source',
        );
    }

    // ----------------------------------------------------------- bounds law

    public function testPutRejectsAboveMaximum(): void
    {
        $response = $this->put(['server.max_servers_per_user' => 1001]);

        self::assertSame(400, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('Validation failed', $body['error']);
        $errors = self::arrayNode($body['errors']);
        self::assertSame('Must be <= 1000.', $errors['server.max_servers_per_user']);
    }

    public function testPutRejectsBelowMinimum(): void
    {
        $response = $this->put(['server.metrics.retention_days' => 0]);

        self::assertSame(400, $response->statusCode);
        $body   = self::arrayNode(json_decode((string) $response->body, true));
        $errors = self::arrayNode($body['errors']);
        self::assertSame('Must be >= 1.', $errors['server.metrics.retention_days']);
    }

    public function testPutRejectsNegativeCapAndFloatOverflow(): void
    {
        $response = $this->put([
            'invite.default_expiry_seconds' => -1,
            'server.relay.reconnect_drain_grace_seconds' => 400,
        ]);

        self::assertSame(400, $response->statusCode);
        $body   = self::arrayNode(json_decode((string) $response->body, true));
        $errors = self::arrayNode($body['errors']);
        self::assertSame('Must be >= 0.', $errors['invite.default_expiry_seconds']);
        self::assertSame('Must be <= 300.', $errors['server.relay.reconnect_drain_grace_seconds']);
        // All-or-nothing: NOTHING persisted despite one leg being valid-shaped.
        self::assertSame([], $this->db->rows);
    }

    public function testPutAcceptsExactBoundariesAndZeroCap(): void
    {
        $response = $this->put([
            'server.max_servers_per_user' => 0,
            'server.max_users_per_server' => 10000,
            'server.relay.reconnect_drain_grace_seconds' => 300,
        ]);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertTrue($body['success']);
        $data     = self::arrayNode($body['data']);
        $settings = self::arrayNode($data['settings']);
        self::assertSame(0, $settings['server.max_servers_per_user'], '0 = unlimited is a LEGAL write');
        self::assertSame(10000, $settings['server.max_users_per_server']);
        self::assertArrayHasKey('overridden', $data);
        $overridden = $data['overridden'];
        self::assertIsArray($overridden);
        self::assertContains('server.relay.reconnect_drain_grace_seconds', $overridden);
    }

    public function testFloatMetaAcceptsIntPayloads(): void
    {
        // validateValueType allows int for float keys; bounds must too (2.0).
        $response = $this->put(['server.relay.reconnect_drain_grace_seconds' => 2]);

        self::assertSame(200, $response->statusCode);
    }

    public function testVendoredSchemaBoundsAreAlsoEnforced(): void
    {
        // Vendored meta carries REAL numeric bounds for the TTL keys (the F-09
        // residual predates them too) — the law must fire there as well.
        $tooSmall = $this->put(['server.enrollment_ttl' => 30]);
        self::assertSame(400, $tooSmall->statusCode);
        $body = self::arrayNode(json_decode((string) $tooSmall->body, true));
        $errors = self::arrayNode($body['errors']);
        self::assertSame('Must be >= 60.', $errors['server.enrollment_ttl']);

        // Exactly at the vendored maximum: legal.
        self::assertSame(200, $this->put(['server.enrollment_ttl' => 2592000])->statusCode);
    }

    public function testTypeLawStillRunsBeforeBounds(): void
    {
        $response = $this->put(['server.max_users_per_server' => 'fifty']);

        self::assertSame(400, $response->statusCode);
        $body   = self::arrayNode(json_decode((string) $response->body, true));
        $errors = self::arrayNode($body['errors']);
        self::assertStringContainsString(
            'Expected type int',
            self::stringNode($errors['server.max_users_per_server']),
        );
    }

    public function testEffectiveEchoAfterAcceptReflectsOverride(): void
    {
        $this->put(['server.metrics.retention_days' => 30]);

        // Second PUT echoes live state merged with the new value.
        $response = $this->put(['server.max_users_per_server' => 5]);
        $body     = self::arrayNode(json_decode((string) $response->body, true));
        $data     = self::arrayNode($body['data']);
        $settings = self::arrayNode($data['settings']);

        self::assertSame(30, $settings['server.metrics.retention_days']);
        self::assertSame(5, $settings['server.max_users_per_server']);
    }
}
