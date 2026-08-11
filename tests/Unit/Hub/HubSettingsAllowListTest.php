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
}
