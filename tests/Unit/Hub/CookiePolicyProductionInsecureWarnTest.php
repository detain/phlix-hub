<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Closure;
use Phlix\Hub\Hub\CookiePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * HUB-4.2 (bug #3 hub half, SH-033 port): the hub's insecure-cookie opt-in
 * (HUB_COOKIE_INSECURE env or its stored twin auth.cookie.insecure) is a DEV
 * affordance; on a hub marked production it now speaks up — exactly one loud
 * E_USER_WARNING per process, the same once-per-process latch idiom as the
 * server's CookiePolicy (phlix-server src/Auth/CookiePolicy.php
 * warnProductionInsecureOnce), with a hub-scoped latch key so the two hosts
 * each keep their own once-semantics even if ever loaded in one process.
 *
 * The hub's production marker accepts any of HUB_ENV / APP_ENV / PHLIX_ENV
 * strictly equal to 'production' (the three names the hub's shipped config
 * readers honour; the server has the single PHLIX_ENV name).
 *
 * Warning, not gate — same ratified posture as the server twin and the
 * boot-time DB-credential guard: the opt-out keeps working, dev/unset tiers
 * stay silent, healthy production (flag off) stays silent, and a degraded
 * store stays silent because the shipped secure posture has nothing to warn
 * about.
 */
final class CookiePolicyProductionInsecureWarnTest extends TestCase
{
    /** The documented test seam: the hub-scoped once-per-process latch. */
    private const LATCH = '__phlix_hub_cookie_insecure_production_warn';

    /** @var array<string, string|false> env values captured before the test */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS[self::LATCH]);
        $this->env(CookiePolicy::INSECURE_ENV, null);
        $this->env('HUB_ENV', null);
        $this->env('APP_ENV', null);
        $this->env('PHLIX_ENV', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->envBackup = [];
        unset($GLOBALS[self::LATCH]);
        parent::tearDown();
    }

    private function env(string $name, ?string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /**
     * Read insecureOverride() N times under an E_USER_WARNING capture.
     *
     * @return array{0: bool, 1: list<string>} [last return value, warnings]
     */
    private function probe(CookiePolicy $policy, int $times = 1): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        try {
            $result = false;
            for ($i = 0; $i < $times; $i++) {
                $result = $policy->insecureOverride();
            }
        } finally {
            restore_error_handler();
        }

        return [$result, $warnings];
    }

    /** @param Closure():bool|null $insecure */
    private function policy(?\Closure $insecure = null): CookiePolicy
    {
        return new CookiePolicy(null, $insecure);
    }

    public function testProductionWithEnvFlagWarnsOnceAcrossRepeatedReads(): void
    {
        $this->env('HUB_ENV', 'production');
        $this->env(CookiePolicy::INSECURE_ENV, '1');

        [$result, $warnings] = $this->probe($this->policy(), 2);

        // The opt-out stays honored — this warns, it does not block.
        self::assertTrue($result);
        self::assertCount(1, $warnings, 'the latch must fire exactly once per process');
        self::assertStringContainsString(CookiePolicy::INSECURE_ENV, $warnings[0]);
        // MINOR-2 (HUB-4.2 review): the message names the marker that
        // actually won, not a fixed HUB_ENV string.
        self::assertStringContainsString('HUB_ENV=production', $warnings[0]);
        self::assertStringContainsString('Secure', $warnings[0]);
        self::assertArrayHasKey(self::LATCH, $GLOBALS, 'the latch is the documented once-marker');
    }

    public function testProductionWithEnvTrueFormWarnsOnce(): void
    {
        // Hub-delta arm (disclosed): the hub env switch accepts '1' AND
        // 'true'; the warning must cover both spellings.
        $this->env('HUB_ENV', 'production');
        $this->env(CookiePolicy::INSECURE_ENV, 'true');

        [$result, $warnings] = $this->probe($this->policy());

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(CookiePolicy::INSECURE_ENV, $warnings[0]);
    }

    public function testProductionWithStoredOptInWarns(): void
    {
        $this->env('HUB_ENV', 'production');

        [$result, $warnings] = $this->probe($this->policy(static fn (): bool => true));

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(CookiePolicy::COOKIE_INSECURE_KEY, $warnings[0]);
    }

    public function testProductionMarkedViaAppEnvAlsoWarns(): void
    {
        $this->env('APP_ENV', 'production');
        $this->env(CookiePolicy::INSECURE_ENV, '1');

        [$result, $warnings] = $this->probe($this->policy());

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        // The winning marker names itself: an APP_ENV-only deployment must
        // not be told about HUB_ENV (MINOR-2, HUB-4.2 review).
        self::assertStringContainsString('APP_ENV=production', $warnings[0]);
        self::assertStringNotContainsString('HUB_ENV=production', $warnings[0]);
    }

    public function testProductionMarkedViaPhlixEnvAlsoWarns(): void
    {
        // Same-process estate idiom: a harness exporting PHLIX_ENV marks the
        // hub production too.
        $this->env('PHLIX_ENV', 'production');

        [$result, $warnings] = $this->probe($this->policy(static fn (): bool => true));

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('PHLIX_ENV=production', $warnings[0]);
        self::assertStringNotContainsString('HUB_ENV=production', $warnings[0]);
    }

    public function testMultipleMatchingMarkersNameTheFirstInOrder(): void
    {
        // Documented precedence: HUB_ENV, APP_ENV, PHLIX_ENV — the first
        // match speaks, so an operator exporting all three hears the most
        // hub-specific knob (MINOR-2, HUB-4.2 review).
        $this->env('HUB_ENV', 'production');
        $this->env('APP_ENV', 'production');
        $this->env('PHLIX_ENV', 'production');
        $this->env(CookiePolicy::INSECURE_ENV, '1');

        [$result, $warnings] = $this->probe($this->policy());

        self::assertTrue($result);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('HUB_ENV=production', $warnings[0]);
        self::assertStringNotContainsString('APP_ENV=production', $warnings[0]);
        self::assertStringNotContainsString('PHLIX_ENV=production', $warnings[0]);
    }

    public function testDevTierWithEnvFlagStaysSilent(): void
    {
        $this->env('HUB_ENV', 'development');
        $this->env(CookiePolicy::INSECURE_ENV, '1');

        [$result, $warnings] = $this->probe($this->policy());

        self::assertTrue($result);
        self::assertSame([], $warnings);
    }

    public function testUnsetTierWithEnvFlagStaysSilent(): void
    {
        // No marker name set at all (manual dev boot) — the same exact
        // 'production' scope law as the server twin.
        $this->env(CookiePolicy::INSECURE_ENV, '1');

        [$result, $warnings] = $this->probe($this->policy());

        self::assertTrue($result);
        self::assertSame([], $warnings);
        self::assertArrayNotHasKey(self::LATCH, $GLOBALS, 'silence must not burn the latch');
    }

    public function testProductionWithoutAnyOptInStaysSilent(): void
    {
        $this->env('HUB_ENV', 'production');

        [$result, $warnings] = $this->probe($this->policy(static fn (): bool => false));

        self::assertFalse($result);
        self::assertSame([], $warnings);
    }

    public function testProductionWithThrowingResolverStaysSilent(): void
    {
        // Degraded resolver = fail-closed secure posture = nothing to warn
        // about (the server twin's same disposition).
        $this->env('HUB_ENV', 'production');

        $throwing = static function (): bool {
            throw new RuntimeException('settings store down');
        };

        [$result, $warnings] = $this->probe($this->policy($throwing));

        self::assertFalse($result);
        self::assertSame([], $warnings);
    }
}
