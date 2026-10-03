<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\HubSettingsRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Structural guards on {@see HubSettingsRepository::ALLOWED_KEYS}, resolved
 * against the REAL `config/` directory (not a fixture).
 *
 * These exist because Phase 6 shipped fourteen allow-listed keys of which
 * three resolved to `null` and none had a runtime consumer — a settings page
 * full of toggles that did nothing. A fixture-based test cannot catch that
 * class of bug: it happily invents the config the production files lack. So
 * every assertion here points at `config/*.php` as deployed.
 *
 * Roster-pin note (review P3, comment-only): every guard below is
 * ALLOWED_KEYS-driven — a 19th key is auto-swept into all of them with no
 * test change, and a removal silently shrinks coverage. Nothing pins the
 * exact key-set or the W5 head-count of 18; treat a roster change as an
 * explicit review + CHANGELOG event (a future lane may add a count/set
 * pin here if drift ever shows up).
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class HubSettingsAllowListTest extends TestCase
{
    private function repository(): HubSettingsRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        // Real config dir: the whole point is to resolve against what ships.
        return new HubSettingsRepository($db, dirname(__DIR__, 3) . '/config');
    }

    /**
     * Every allow-listed key must resolve to a real config default.
     *
     * A `null` default means the dotted key names a config path that does not
     * exist, so the UI would render an empty control whose "reset to default"
     * has nothing to reset to.
     */
    public function testEveryAllowedKeyResolvesToANonNullDefault(): void
    {
        $repo = $this->repository();

        $orphans = [];
        foreach (array_keys(HubSettingsRepository::ALLOWED_KEYS) as $key) {
            if ($repo->getDefault($key) === null) {
                $orphans[] = $key;
            }
        }

        self::assertSame(
            [],
            $orphans,
            'These allow-listed keys resolve to null against the real config/ directory. '
            . 'Fix the dotted key (it names the config path) or delete it — do NOT add a '
            . 'new config entry just to make it resolve.',
        );
    }

    /**
     * The declared value type must match the type of the resolved default,
     * otherwise a PUT that echoes the default straight back would be rejected
     * as `invalid_type`.
     */
    public function testDeclaredTypesMatchTheResolvedDefaults(): void
    {
        $repo = $this->repository();

        foreach (HubSettingsRepository::ALLOWED_KEYS as $key => $type) {
            /** @var mixed $default */
            $default = $repo->getDefault($key);

            $actual = match (true) {
                is_bool($default)  => 'bool',
                is_int($default)   => 'int',
                is_float($default) => 'float',
                is_array($default) => 'json',
                default            => 'string',
            };

            self::assertSame($type, $actual, "declared type for '{$key}' does not match its config default");
        }
    }

    /**
     * Secrets and boot-bound infrastructure must never become editable from
     * the web UI.
     *
     * `public_domain` / `domain` / `hub_base_url` are baked into already-issued
     * enrollment JWTs and the JWKS URL, so editing one silently breaks the
     * enrolled estate; `tls_enabled` and `subdomain_auto_claim` drive
     * ACME/TLS provisioning and are a lockout foot-gun; the rest are secrets
     * or listen-socket config.
     *
     * Roster-pin note (review P3, comment-only): this guard only catches
     * leaks of paths DENIED_KEYS already enumerates — a future secret-shaped
     * key nobody added to DENIED_KEYS sails through. A naming heuristic
     * (deny anything matching /secret|token|password|key$/) would close
     * that gap; deliberately not added in a docs lane.
     */
    public function testNoDeniedKeyIsAllowListed(): void
    {
        $leaked = array_values(array_intersect(
            HubSettingsRepository::DENIED_KEYS,
            array_keys(HubSettingsRepository::ALLOWED_KEYS),
        ));

        self::assertSame([], $leaked, 'DO-NOT-EXPOSE config paths leaked into ALLOWED_KEYS');
    }

    /**
     * The auth TTL keys must address the config keys the JWT stack actually
     * reads. Companion to
     * {@see \Phlix\Hub\Tests\Unit\Common\Container\Providers\AuthServicesProviderTest},
     * which asserts the runtime consequence.
     */
    public function testAuthTtlKeysAddressTheConfigKeysTheProviderReads(): void
    {
        self::assertArrayHasKey('auth.access_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.refresh_ttl', HubSettingsRepository::ALLOWED_KEYS);

        // The inverse of the Phase 6 regression: these names must NOT come back.
        self::assertArrayNotHasKey('auth.access_token_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayNotHasKey('auth.refresh_token_ttl', HubSettingsRepository::ALLOWED_KEYS);

        $authConfig = include dirname(__DIR__, 3) . '/config/auth.php';
        self::assertIsArray($authConfig);
        self::assertArrayHasKey('access_ttl', $authConfig);
        self::assertArrayHasKey('refresh_ttl', $authConfig);
    }

    /**
     * The live signup toggle keys must stay mirrored. `auth.signups_disabled`
     * is the ALLOWED_KEYS / error-code spelling; `signups_enabled` is the
     * boot spelling AuthServicesProvider reads verbatim. config/auth.php
     * derives one from the other off HUB_SIGNUPS_ENABLED — if the twin ever
     * drifts, the settings default would silently contradict the boot flag.
     */
    public function testSignupToggleKeysMirrorEachOtherInConfig(): void
    {
        self::assertArrayHasKey('auth.signups_disabled', HubSettingsRepository::ALLOWED_KEYS);
        self::assertSame('bool', HubSettingsRepository::ALLOWED_KEYS['auth.signups_disabled']);

        $authConfig = include dirname(__DIR__, 3) . '/config/auth.php';
        self::assertIsArray($authConfig);
        self::assertArrayHasKey('signups_enabled', $authConfig);
        self::assertArrayHasKey('signups_disabled', $authConfig);
        self::assertIsBool($authConfig['signups_disabled']);
        self::assertSame(
            !$authConfig['signups_enabled'],
            $authConfig['signups_disabled'],
            'signups_disabled must be the exact boolean twin of signups_enabled',
        );
    }

    /**
     * W5 (F9 lesson): EVERY numeric allow-listed key must carry hard bounds in
     * the merged meta the PUT law reads. A cap/retention/TTL key without
     * minimum+maximum would let any integer in through the settings API —
     * exactly how a "max users" toggle becomes a DoS primitive. The consumer
     * clamps again at read (HubSettingsResolvers), but admission here is what
     * keeps the write path honest.
     */
    public function testEveryNumericKeyCarriesBoundsInMergedMeta(): void
    {
        $reflector = new \ReflectionClass(\Phlix\Hub\Http\Controllers\HubSettingsController::class);
        $cache     = $reflector->getProperty('schemaMeta');
        $cache->setAccessible(true);
        $cache->setValue(null, null);
        $meta = \Phlix\Hub\Http\Controllers\HubSettingsController::schemaMeta();
        // Leave the cache warm exactly as production would find it.

        foreach (HubSettingsRepository::ALLOWED_KEYS as $key => $type) {
            if ($type !== 'int' && $type !== 'float') {
                continue;
            }

            self::assertArrayHasKey($key, $meta, "numeric key {$key} needs a meta block");
            self::assertIsNumeric(
                $meta[$key]['minimum'] ?? null,
                "numeric key {$key} must declare a minimum (F9 bounds law)",
            );
            self::assertIsNumeric(
                $meta[$key]['maximum'] ?? null,
                "numeric key {$key} must declare a maximum (F9 bounds law)",
            );
            self::assertLessThanOrEqual(
                (float) $meta[$key]['maximum'],
                (float) $meta[$key]['minimum'],
                "bounds for {$key} are inverted",
            );
        }
    }
}
