<?php

/**
 * Phlix hub component: Runtime tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Runtime;

use Phlix\Hub\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Mask-resolution pins for {@see SwooleRuntime} (R14-01 / R16-01) — port of
 * phlix-server's `tests/Unit/Server/Runtime/SwooleRuntimeTest.php`.
 *
 * @package Phlix\Hub\Tests\Unit\Runtime
 */
final class SwooleRuntimeTest extends TestCase
{
    public function testCoroutineEnabledDefaultsToTrue(): void
    {
        self::assertTrue(SwooleRuntime::coroutineEnabled([]));
        self::assertTrue(SwooleRuntime::coroutineEnabled(['coroutine' => []]));
        self::assertTrue(SwooleRuntime::coroutineEnabled('not-an-array'));
    }

    public function testCoroutineCanBeHardDisabled(): void
    {
        self::assertFalse(SwooleRuntime::coroutineEnabled(['coroutine' => ['enabled' => false]]));
    }

    public function testExplicitTruthyEnabledStaysEnabled(): void
    {
        self::assertTrue(SwooleRuntime::coroutineEnabled(['coroutine' => ['enabled' => true]]));
    }

    public function testResolveHookFlagsHonoursAnExplicitIntOverride(): void
    {
        self::assertSame(
            0x2,
            SwooleRuntime::resolveHookFlags(['coroutine' => ['hook_flags' => 0x2]]),
        );
    }

    public function testResolveHookFlagsFallsBackToTheSafeMask(): void
    {
        self::assertSame(SwooleRuntime::safeHookFlags(), SwooleRuntime::resolveHookFlags([]));
        // A non-int hook_flags is ignored in favour of the safe default.
        self::assertSame(
            SwooleRuntime::safeHookFlags(),
            SwooleRuntime::resolveHookFlags(['coroutine' => ['hook_flags' => 'all']]),
        );
    }

    public function testSafeMaskDropsEveryCrashProneNativeHook(): void
    {
        if (!defined('SWOOLE_HOOK_ALL')) {
            self::assertSame(0, SwooleRuntime::safeHookFlags());
            self::markTestSkipped('ext-swoole not loaded — no hook constants to assert against.');
        }

        $flags = SwooleRuntime::safeHookFlags();

        // File/proc/curl/stdio + the PDO drivers and net-function hook must all
        // be off. (The blocking-function hook for exec/shell_exec is unnamed in
        // this build — the allowlist excludes it by construction; asserted via
        // the exact-allowlist test below.)
        $unsafeNames = [
            'SWOOLE_HOOK_FILE', 'SWOOLE_HOOK_PROC', 'SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_NATIVE_CURL',
            'SWOOLE_HOOK_STDIO', 'SWOOLE_HOOK_PDO_PGSQL', 'SWOOLE_HOOK_PDO_SQLITE', 'SWOOLE_HOOK_NET_FUNCTION',
        ];
        foreach ($unsafeNames as $unsafe) {
            if (defined($unsafe)) {
                self::assertSame(
                    0,
                    $flags & (int) constant($unsafe),
                    "$unsafe must be excluded from the safe coroutine hook mask",
                );
            }
        }
    }

    public function testSafeMaskIsExactlyTheNetworkSleepAllowlist(): void
    {
        if (!defined('SWOOLE_HOOK_TCP')) {
            self::assertSame(0, SwooleRuntime::safeHookFlags());
            self::markTestSkipped('ext-swoole not loaded.');
        }

        $expected = 0;
        foreach (
            [
                'SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_UDP', 'SWOOLE_HOOK_UNIX', 'SWOOLE_HOOK_UDG',
                'SWOOLE_HOOK_SSL', 'SWOOLE_HOOK_TLS', 'SWOOLE_HOOK_STREAM_FUNCTION',
                'SWOOLE_HOOK_SLEEP', 'SWOOLE_HOOK_SOCKETS',
            ] as $name
        ) {
            if (defined($name)) {
                $expected |= (int) constant($name);
            }
        }

        // Exactly the allowlist — nothing more. This is what stops an unnamed
        // bit (e.g. the exec/shell_exec blocking-function hook still set in
        // SWOOLE_HOOK_ALL) from leaking into the mask.
        self::assertSame($expected, SwooleRuntime::safeHookFlags());
        if (defined('SWOOLE_HOOK_ALL')) {
            self::assertLessThan((int) constant('SWOOLE_HOOK_ALL'), SwooleRuntime::safeHookFlags());
        }
    }

    public function testSafeMaskKeepsSocketAndSleepHooksForTheCoroutinePool(): void
    {
        if (!defined('SWOOLE_HOOK_ALL')) {
            self::markTestSkipped('ext-swoole not loaded.');
        }

        $flags = SwooleRuntime::safeHookFlags();

        foreach (['SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_SLEEP'] as $kept) {
            if (defined($kept)) {
                self::assertSame(
                    (int) constant($kept),
                    $flags & (int) constant($kept),
                    "$kept should remain enabled so coroutine network IO still yields",
                );
            }
        }

        // The safe mask must be a strict subset of SWOOLE_HOOK_ALL and non-zero.
        self::assertGreaterThan(0, $flags);
        self::assertSame($flags, $flags & (int) constant('SWOOLE_HOOK_ALL'));
        self::assertNotSame((int) constant('SWOOLE_HOOK_ALL'), $flags);
    }

    public function testRuntimeHookMaskIsZeroWhenCoroutinesDisabled(): void
    {
        // The adapter ctor installs SWOOLE_HOOK_ALL regardless; a bare
        // resolveHookFlags() call is therefore NOT the worker-safe value —
        // runtimeHookMask() must answer 0 so enforcement unhooks everything.
        self::assertSame(0, SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => false]]));
        // The escape hatch must not resurrect hooks the operator disabled.
        self::assertSame(
            0,
            SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => false, 'hook_flags' => 0x2]])
        );
    }

    public function testRuntimeHookMaskResolvesBothEnabledPaths(): void
    {
        self::assertSame(SwooleRuntime::safeHookFlags(), SwooleRuntime::runtimeHookMask([]));
        self::assertSame(
            0x2,
            SwooleRuntime::runtimeHookMask(['coroutine' => ['hook_flags' => 0x2]])
        );
        self::assertSame(
            SwooleRuntime::safeHookFlags(),
            SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => true]])
        );
    }

    public function testCurlHookFlagsCoverTheSpellsAndNeverIntersectTheAllowlist(): void
    {
        if (!defined('SWOOLE_HOOK_NATIVE_CURL')) {
            self::markTestSkipped('ext-swoole not loaded — no cURL hook constants to resolve.');
        }

        $curl = SwooleRuntime::curlHookFlags();
        self::assertGreaterThan(0, $curl);
        if (defined('SWOOLE_HOOK_NATIVE_CURL')) {
            self::assertSame((int) SWOOLE_HOOK_NATIVE_CURL, $curl & (int) SWOOLE_HOOK_NATIVE_CURL);
        }
        if (defined('SWOOLE_HOOK_CURL')) {
            self::assertSame((int) SWOOLE_HOOK_CURL, $curl & (int) SWOOLE_HOOK_CURL);
        }

        // The delivery probe keys on exactly these bits — the curated allowlist
        // must exclude ALL of them (this is what makes "did the mask land?"
        // behaviourally observable).
        self::assertSame(
            0,
            SwooleRuntime::safeHookFlags() & $curl,
            'a cURL hook inside the safe mask would blind the behavioural delivery probe'
        );
    }
}
