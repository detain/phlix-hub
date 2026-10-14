<?php

/**
 * Phlix hub component: Runtime tests.
 *
 * R14-01 / R16-01 — static enforcement guard: the curated coroutine-hook
 * allowlist must be DELIVERED by the APIs that physically work, verified
 * BEHAVIOURALLY, and never again by a check that reports success either way.
 * Port of phlix-server's S433 `HookAllowlistEnforcementGuardTest`, reshaped
 * for the hub topology: enforcement lives in {@see \Phlix\Hub\Runtime\
 * CoroutineHooks} (2 direct applies + 4 property wraps) instead of one
 * start.php closure, because the hub's relay/federation onWorkerStart callbacks
 * are set inside worker classes this lane may not edit.
 *
 * ## Why a static guard next to the behavioural test
 *
 * `CuratedHookDeliveryProbeTest` proves the shipped mechanism works on a real
 * Swoole runtime. This file proves the mechanism is the ONLY one wired into
 * the bootstrap, with rules that can never regress to the measured-lie shapes
 * — even on a box without ext-swoole (the probe suite skips there; these pins
 * never do). The exact trap, measured on the estate before any fix shipped:
 *
 *  - `Coroutine::set(['hook_flags' => X])` inside a coroutine updates the
 *    REPORTED option; already-installed handlers stay physically hooked.
 *  - `Coroutine::getOptions()['hook_flags']` then READS X in both the working
 *    and the broken state — the standard check that passed while the SIGSEGV
 *    mitigation reached no worker.
 *
 * So this guard tokenizes (comment-stripped) and pins, per rule:
 *   1. the master block in start.php gates `enableCoroutine` behind
 *      `SwooleRuntime::coroutineEnabled` + `resolveHookFlags` and contains no
 *      bare `SWOOLE_HOOK_ALL` — the ungated full hook set can never return;
 *   2. `CoroutineHooks::apply()` resolves the WORKER-safe mask
 *      (`runtimeHookMask`, not `resolveHookFlags` — the zero-when-disabled
 *      path must land) and reaches delivery only through `HookDelivery::
 *      enforceAndVerify`, with neither lie-API anywhere in the file;
 *   3. `HookDelivery` itself never reads `getOptions` (that equality IS the
 *      planted-drift trap), does call `Swoole\Runtime::enableCoroutine` (the
 *      only physically-effective API), drives the sibling ticker with
 *      `Coroutine::create`, and compares against `YIELD_TICK_FLOOR` behind
 *      `HookDeliveryException` throws;
 *   4. the census: exactly 2 in-closure applies (http, maintenance) + 3 wraps
 *      in Application.php (relay :8802, client-relay :8803, syncplay :8804) +
 *      exactly 1 wrap in HubServicesProvider.php (federation :8805) — a new
 *      worker type added without delivery reddens a number, not a code review;
 *   5. the two applies are the FIRST statement of their closures — ahead of
 *      every best-effort `try` (the maintenance warning and the HTTP
 *      channel-join try both sit behind them), so a HookDeliveryException
 *      always escapes to kill the child loudly;
 *   6. the survival token is code-resident here and absent from every
 *      production carrier.
 *
 * @package Phlix\Hub\Tests\Unit\Runtime
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;

final class HookEnforcementGuardTest extends TestCase
{
    /** Survival token for R14-01/R16-01 — must live in code, never in any *.md file. */
    public const string SURVIVAL_TOKEN = 'HUBR14HOOKDELIVERYENFORCEDV6P9';

    private const START_PHP = __DIR__ . '/../../../start.php';
    private const APPLICATION = __DIR__ . '/../../../src/Application.php';
    private const PROVIDER = __DIR__ . '/../../../src/Common/Container/Providers/HubServicesProvider.php';
    private const COROUTINE_HOOKS = __DIR__ . '/../../../src/Runtime/CoroutineHooks.php';
    private const HOOK_DELIVERY = __DIR__ . '/../../../src/Runtime/HookDelivery.php';

    /** Comment-stripped, whitespace-normalized source — prose can lie, tokens can't. */
    private static function code(string $file): string
    {
        $stripped = php_strip_whitespace($file);
        self::assertNotFalse($stripped, "php_strip_whitespace({$file}) failed — empty/missing file?");

        return (string) preg_replace('/\s+/', ' ', $stripped);
    }

    /**
     * The body of a named static method as it ships — brace-matched from the
     * method signature's opening `{` over comment-stripped, whitespace-
     * normalized text (the method bodies carry no brace-bearing string
     * literals, so a plain depth walk is exact).
     */
    private static function methodBody(string $code, string $signatureAnchor): string
    {
        $at = strpos($code, $signatureAnchor);
        self::assertNotFalse($at, "source must define {$signatureAnchor}");
        $open = strpos($code, '{', $at);
        self::assertNotFalse($open);

        $depth = 0;
        $len = strlen($code);
        for ($i = $open; $i < $len; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        self::fail("unterminated body for {$signatureAnchor}?");
    }

    public function testMasterBootstrapOnlyEnablesTheGatedCuratedMask(): void
    {
        $start = self::code(self::START_PHP);

        foreach (
            [
                'Worker::$eventLoopClass' =>
                    'Swoole must be pinned as the event-loop driver in the master, before any Worker exists',
                'SwooleRuntime::coroutineEnabled' =>
                    'the master enable step is config-gated — the hard-disable path must reach it',
                'SwooleRuntime::resolveHookFlags' =>
                    'the master installs the curated mask, not the extension default',
            ] as $needle => $why
        ) {
            self::assertStringContainsString($needle, $start, "start.php must contain {$needle} — {$why}.");
        }

        // The original defect itself: `enableCoroutine(SWOOLE_HOOK_ALL)` with
        // no config gate. Comments are stripped, so any surviving hit is CODE.
        self::assertStringNotContainsString(
            'SWOOLE_HOOK_ALL',
            $start,
            'the master must never re-introduce the ungated full hook set — that is the exact '
            . 'crash-prone mask (R14-01) the curated allowlist replaces.'
        );
    }

    public function testApplyResolvesTheWorkerSafeMaskAndNeverTheLieApi(): void
    {
        $hooks = self::code(self::COROUTINE_HOOKS);
        $body = self::methodBody($hooks, 'public static function apply(mixed $config): void');

        foreach (
            [
                'SwooleRuntime::runtimeHookMask' =>
                    'the WORKER-safe mask (zero when disabled) — resolveHookFlags here would leave '
                    . 'enabled=false silently running whatever the vendor constructor installed',
                'self::deliver' => 'delivery goes through the single enforce+verify seam',
                'self::log' => 'per-worker DELIVERY ACK: every worker that starts says so',
            ] as $needle => $why
        ) {
            self::assertStringContainsString($needle, $body, "apply() must contain {$needle} — {$why}.");
        }

        foreach (
            [
                'Coroutine::set' =>
                    'inside a coroutine this only updates the REPORTED mask — the measured silent-success shape',
                'getOptions' =>
                    'the reported option reads curated in BOTH the working and the broken state — the exact trap',
            ] as $forbidden => $why
        ) {
            self::assertStringNotContainsString(
                $forbidden,
                $hooks,
                "CoroutineHooks may not reference {$forbidden} anywhere — {$why}."
            );
        }

        $deliver = self::methodBody($hooks, 'private static function deliver(int $mask): array');
        self::assertStringContainsString(
            'HookDelivery::enforceAndVerify',
            $deliver,
            'the production delivery step is install-AND-prove, never install alone.'
        );
    }

    public function testDeliveryProbeIsBehaviouralNotReported(): void
    {
        $probe = self::code(self::HOOK_DELIVERY);

        self::assertStringNotContainsString(
            'getOptions',
            $probe,
            'HookDelivery must never consult the reported mask — swapping the tick comparison '
            . 'for a getOptions() equality is the planted-drift trap this guard exists to end.'
        );
        foreach (
            [
                'Runtime::enableCoroutine' =>
                    'the full-mask replacement API — the only one that physically un-swaps handlers',
                'Coroutine::create' => 'the sibling ticker that counts yields',
                'Coroutine::sleep' => 'the ticker cadence',
                'YIELD_TICK_FLOOR' => 'the behavioural threshold the verdict is taken against',
                'new HookDeliveryException' => 'an inconclusive or no-delivery verdict must be a THROWN failure',
            ] as $needle => $why
        ) {
            self::assertStringContainsString($needle, $probe, "HookDelivery must contain {$needle} — {$why}.");
        }
    }

    public function testEveryWorkerTypeDeliversExactlyOnce(): void
    {
        $app = self::code(self::APPLICATION);
        $provider = self::code(self::PROVIDER);

        self::assertSame(
            2,
            substr_count($app, 'CoroutineHooks::apply('),
            'exactly two in-closure applies must exist (http, maintenance) — a third worker '
            . 'closure added without delivery, or a delivery point removed, is the census this pins.'
        );
        self::assertSame(
            2,
            substr_count($app, '->onWorkerStart = static function'),
            'the apply census and the in-file closure census must move together — an unwrapped '
            . 'onWorkerStart closure in Application.php is the silent-success regression.'
        );
        self::assertSame(
            3,
            substr_count($app, 'CoroutineHooks::wrap('),
            'exactly three wraps must exist in Application.php (relay :8802, client-relay :8803, '
            . 'syncplay :8804) — every class-owned worker start() gets its hook step prepended.'
        );
        self::assertSame(
            1,
            substr_count($provider, 'CoroutineHooks::wrap('),
            'exactly one wrap must exist in HubServicesProvider.php (federation :8805).'
        );
    }

    public function testAppliesAreFirstStatementAheadOfEveryBestEffortTry(): void
    {
        $app = self::code(self::APPLICATION);

        // HTTP worker: apply precedes the channel-join try body.
        $assign = strpos($app, '$worker->onWorkerStart = static function');
        $apply = strpos($app, 'CoroutineHooks::apply($bootConfig);');
        $firstBody = strpos($app, 'ChannelClient::connect');
        self::assertNotFalse($assign);
        self::assertNotFalse($apply);
        self::assertNotFalse($firstBody);
        self::assertLessThan($apply, $assign, 'the HTTP apply must live inside its onWorkerStart closure');
        self::assertLessThan($firstBody, $apply, 'the HTTP apply must precede the closure body it guards');
        self::assertLessThan(400, $apply - $assign, 'apply drifted away from the closure head?');

        // Maintenance worker: apply precedes the 'failed to start' warning
        // catch by a bounded gap — the delivery throw must sit OUTSIDE the
        // try that swallows everything else (server S433 rule 3, hub shape).
        $mAssign = strpos($app, '$maintenanceWorker->onWorkerStart = static function');
        $mApply = strpos($app, 'CoroutineHooks::apply($serverConfig);');
        $warning = strpos($app, "'Maintenance worker failed to start: '");
        self::assertNotFalse($mAssign);
        self::assertNotFalse($mApply);
        self::assertNotFalse($warning);
        self::assertLessThan($mApply, $mAssign, 'the maintenance apply must live inside its closure');
        self::assertLessThan($warning, $mApply, 'an undelivered hook mask must escape before the '
            . 'best-effort warning can convert it into an idle worker');
        self::assertLessThan(600, $warning - $mApply, 'the apply and the catch it guards drifted apart?');
    }

    public function testSurvivalTokenIsCodeResidentAndAbsentFromProductionSource(): void
    {
        self::assertStringContainsString(
            self::SURVIVAL_TOKEN,
            (string) file_get_contents(__FILE__),
            'R14-01/R16-01 survival token vanished from its carrier file.'
        );
        $productionCarriers = [
            self::START_PHP,
            self::APPLICATION,
            self::PROVIDER,
            self::COROUTINE_HOOKS,
            self::HOOK_DELIVERY,
        ];
        foreach ($productionCarriers as $f) {
            self::assertStringNotContainsString(
                self::SURVIVAL_TOKEN,
                self::code($f),
                'the token must never ship in production source (' . basename($f) . ').'
            );
        }
    }
}
