<?php

/**
 * Phlix hub test: Unit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Red-first pins for HUB-2.1 (bug #5, fail-open): the auth config's env reads
 * used `getenv(...) ?: <default>`, and `?:` treats the STRING "0" as falsy —
 * so the operator's explicit `HUB_SIGNUPS_ENABLED=0` produced 'true' and kept
 * SELF-SERVICE REGISTRATION OPEN. That is a security fail-open, not a typo:
 * the file's own header (:16-17) documents "false"/"0"/"no"/"off" as closing
 * registration, and the deny-list has carried '0' since auth audit fix 3.
 *
 * The cure is the estate's EnvConfig reader (ported into the hub): unset or
 * set-but-empty means "not configured" → the default; "0" is an EXPLICIT
 * value and survives. The '0' cases below were RED against the `?:` shape;
 * the trim/case and affirmative legs pin the semantics the closure already
 * had so the port stays provably behavior-conservative there.
 *
 * @package Phlix\Hub\Tests\Unit\Config
 */
final class AuthEnvSignupsTest extends TestCase
{
    private const ENVS = [
        'HUB_SIGNUPS_ENABLED',
        'HUB_JWT_SECRET',
        'HUB_JWT_ACCESS_TTL',
        'HUB_JWT_REFRESH_TTL',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENVS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function authConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/auth.php';

        return $config;
    }

    public function testExplicitZeroClosesSignups(): void
    {
        putenv('HUB_SIGNUPS_ENABLED=0');
        $config = $this->authConfig();

        // THE fail-open: under `?:` the string '0' fell back to 'true' and
        // registrations stayed open while the operator believed them closed.
        $this->assertFalse($config['signups_enabled']);
        // Dotted-twin law (HubSettingsRepository::ALLOWED_KEYS, rule 4):
        // the resolver key must track exactly.
        $this->assertTrue($config['signups_disabled']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function closingValuesProvider(): array
    {
        return [
            'false' => ['false'],
            'padded false (trim)' => [' false'],
            'off' => ['off'],
            'uppercase NO (case)' => ['NO'],
            'Zero word' => ['0'],
        ];
    }

    #[DataProvider('closingValuesProvider')]
    public function testClosingValuesCloseSignups(string $value): void
    {
        putenv('HUB_SIGNUPS_ENABLED=' . $value);

        $this->assertFalse($this->authConfig()['signups_enabled']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openValuesProvider(): array
    {
        return [
            'true' => ['true'],
            'one' => ['1'],
            'yes' => ['yes'],
            'uppercase ON (case)' => ['ON'],
        ];
    }

    #[DataProvider('openValuesProvider')]
    public function testAffirmativeValuesKeepSignupsOpen(string $value): void
    {
        putenv('HUB_SIGNUPS_ENABLED=' . $value);

        $this->assertTrue($this->authConfig()['signups_enabled']);
    }

    public function testUnsetOrEmptyKeepsTheDocumentedDefaultOpen(): void
    {
        // Unset → default 'true'.
        $this->assertTrue($this->authConfig()['signups_enabled']);

        // Set-but-empty is "not configured" under the EnvConfig convention.
        putenv('HUB_SIGNUPS_ENABLED=');
        $this->assertTrue($this->authConfig()['signups_enabled']);
    }

    public function testJwtSecretZeroArrivesVerbatimInsteadOfLookingUnset(): void
    {
        putenv('HUB_JWT_SECRET=0');

        // Config layer forwards; AuthServicesProvider::resolveSecret() owns
        // validation (≥32-byte gate, placeholder refusal, dev-fallback
        // doctrine) — a '0' secret hits the SAME refusal path an unset one
        // does, but it no longer masquerades as "operator set nothing".
        $this->assertSame('0', $this->authConfig()['secret']);
    }

    public function testEmptyJwtSecretMeansUnset(): void
    {
        putenv('HUB_JWT_SECRET=');

        $this->assertNull($this->authConfig()['secret']);
    }

    public function testZeroTtlsReachTheConfigLayer(): void
    {
        putenv('HUB_JWT_ACCESS_TTL=0');
        putenv('HUB_JWT_REFRESH_TTL=0');
        $config = $this->authConfig();

        // `?:` swallowed both zeros into the defaults (3600 / 604800). The
        // TTL readers are the AuthServicesProvider verbatim-key contract
        // (header above); a 0 TTL is an operator dial, not the config file's
        // problem to second-guess.
        $this->assertSame(0, $config['access_ttl']);
        $this->assertSame(0, $config['refresh_ttl']);
    }

    public function testUnsetTtlsKeepDocumentedDefaults(): void
    {
        $config = $this->authConfig();

        $this->assertSame(3600, $config['access_ttl']);
        $this->assertSame(604800, $config['refresh_ttl']);
    }

    public function testMalformedTtlCastsTheWayTheSitesAlwaysDid(): void
    {
        putenv('HUB_JWT_REFRESH_TTL=36x');

        // (int) '36x' === 36 — the pre-existing cast posture of these sites,
        // preserved by EnvConfig::integer() verbatim.
        $this->assertSame(36, $this->authConfig()['refresh_ttl']);
    }
}
