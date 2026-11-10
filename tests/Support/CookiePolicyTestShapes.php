<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Hub\CookiePolicy;

/**
 * Deterministic {@see CookiePolicy} shapes for the CP-011 suites: fixed
 * resolvers instead of live hub-settings reads, so no test depends on
 * ConnectionPool state (unit runs have none) and the enabled/insecure axes
 * can be flipped per leg without touching env.
 *
 * Deliberately does NOT touch the shipped default resolvers — the
 * no-arg-defaults path is pinned separately in CookiePolicyTest.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class CookiePolicyTestShapes
{
    /** Policy with the shipped posture: cookie mode ON, Secure ON. */
    public static function on(): CookiePolicy
    {
        return self::with(enabled: true, insecure: false);
    }

    public static function with(bool $enabled = true, bool $insecure = false): CookiePolicy
    {
        return new CookiePolicy(
            static fn (): bool => $enabled,
            static fn (): bool => $insecure,
        );
    }
}
