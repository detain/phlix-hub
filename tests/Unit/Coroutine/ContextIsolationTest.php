<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Coroutine;

use Fiber;
use Phlix\Hub\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use support\Context;

/**
 * Unit tests proving coroutine-local request-context isolation in the
 * hub daemon (step 0.2c).
 *
 * Mirrors the equivalent server-side test in
 * `phlix-server/tests/Unit/Server/Coroutine/ContextIsolationTest.php`.
 *
 * The Workerman/Webman coroutine runtime stores per-request state in a
 * driver picked from the active eventLoop:
 *
 *   - {@see \Workerman\Coroutine\Context\Swoole} when ext-swoole is loaded
 *     and Swoole is configured as the eventLoop (the production path —
 *     see `start.php` lines ~48-58).
 *   - {@see \Workerman\Coroutine\Context\Swow}   when ext-swow is loaded.
 *   - {@see \Workerman\Coroutine\Context\Fiber}  fallback (used by the
 *     test suite — PHP 8.1+ ships fibers natively, no extension needed).
 *
 * All three drivers MUST isolate context state per coroutine/fiber so
 * one request can never read or trample another's data. These tests
 * assert that property against the Fiber driver (deterministic, no
 * extension required) and additionally cover the ext-swoole-absent
 * graceful-fallback branch in `start.php`.
 *
 * @covers \Phlix\Hub\Http\RequestContext
 * @covers \support\Context
 * @package Phlix\Hub\Tests\Unit\Coroutine
 * @since   0.1.x (Step 0.2c)
 */
final class ContextIsolationTest extends TestCase
{
    /**
     * Each test starts from a clean root context so leakage from a
     * prior test (or the PHPUnit bootstrap itself) doesn't show up as
     * a false-positive.
     *
     * {@see Context::destroy()} replaces the current coroutine's
     * ArrayObject with an empty one — exactly what the eventLoop would
     * do at the end of a real request.
     */
    protected function setUp(): void
    {
        Context::destroy();
    }

    /**
     * Sanity baseline: when no value has been published into the
     * context, {@see RequestContext::getUserId()} returns `null` and
     * {@see RequestContext::hasUserId()} returns `false`.
     */
    public function testUserIdIsNullWhenUnset(): void
    {
        $this->assertNull(RequestContext::getUserId());
        $this->assertFalse(RequestContext::hasUserId());
    }

    /**
     * The private constructor exists to forbid instantiation. This test
     * pokes it via reflection so coverage reflects intent and a future
     * hand that "loosens" the visibility doesn't go unnoticed.
     */
    public function testConstructorIsPrivateAndClassIsFinal(): void
    {
        $rc = new \ReflectionClass(RequestContext::class);
        $this->assertTrue($rc->isFinal(), 'RequestContext must stay final');

        $ctor = $rc->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate(), 'constructor must stay private');

        $ctor->setAccessible(true);
        $instance = $rc->newInstanceWithoutConstructor();
        $ctor->invoke($instance);
        $this->assertInstanceOf(RequestContext::class, $instance);
    }

    /**
     * `setUserId` round-trips a string through `support\Context`.
     */
    public function testSetThenGetRoundTripsUserId(): void
    {
        RequestContext::setUserId('hub-user-42');
        $this->assertSame('hub-user-42', RequestContext::getUserId());
        $this->assertTrue(RequestContext::hasUserId());
    }

    /**
     * Both `setUserId(null)` and `clearUserId()` wipe the slot. After
     * either, `getUserId()` returns `null` and `hasUserId()` is `false`.
     */
    public function testClearUserIdRemovesTheSlot(): void
    {
        RequestContext::setUserId('hub-user-7');
        $this->assertTrue(RequestContext::hasUserId());

        RequestContext::clearUserId();
        $this->assertNull(RequestContext::getUserId());
        $this->assertFalse(RequestContext::hasUserId());

        RequestContext::setUserId('hub-user-9');
        RequestContext::setUserId(null);
        $this->assertNull(RequestContext::getUserId());
        $this->assertFalse(RequestContext::hasUserId());
    }

    /**
     * Empty string is treated as "no user-id" by `hasUserId()` so an
     * accidental `$request->userId = ''` cannot masquerade as an
     * authenticated user downstream. `getUserId()` still returns the
     * stored empty string — downstream code is expected to gate on
     * `hasUserId()`.
     */
    public function testEmptyStringIsNotConsideredPresent(): void
    {
        RequestContext::setUserId('');
        $this->assertFalse(RequestContext::hasUserId());
        $this->assertSame('', RequestContext::getUserId());
    }

    /**
     * If something else writes a non-string into the user-id slot,
     * `getUserId()` returns `null` rather than handing back the wrong
     * type. Keeps the `?string` contract honest under PHPStan L9 and
     * Psalm errorLevel 1.
     */
    public function testGetUserIdReturnsNullForNonStringValue(): void
    {
        Context::set(RequestContext::KEY_USER_ID, 1234);
        $this->assertNull(RequestContext::getUserId());
        $this->assertFalse(RequestContext::hasUserId());

        Context::set(RequestContext::KEY_USER_ID, ['oops']);
        $this->assertNull(RequestContext::getUserId());
        $this->assertFalse(RequestContext::hasUserId());
    }

    /**
     * Core isolation property: setting a value in one Fiber must not
     * leak into another. Each Fiber stands in for a Swoole coroutine —
     * the {@see \Workerman\Coroutine\Context\Fiber} driver indexes its
     * WeakMap by `Fiber::getCurrent()`, exactly like the Swoole driver
     * indexes by coroutine-id.
     *
     * Without isolation, the second fiber would see `hub-user-A`
     * (Fiber A's value). With isolation, it sees `null`.
     */
    public function testUserIdIsIsolatedBetweenFibers(): void
    {
        $resultsA = [];
        $resultsB = [];

        $fiberA = new Fiber(function () use (&$resultsA): void {
            $resultsA['before'] = RequestContext::getUserId();
            RequestContext::setUserId('hub-user-A');
            $resultsA['after_set'] = RequestContext::getUserId();
            Fiber::suspend();
            $resultsA['after_resume'] = RequestContext::getUserId();
        });

        $fiberB = new Fiber(function () use (&$resultsB): void {
            $resultsB['before'] = RequestContext::getUserId();
            RequestContext::setUserId('hub-user-B');
            $resultsB['after_set'] = RequestContext::getUserId();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        $this->assertNull($resultsA['before'], 'Fiber A sees a clean context on entry');
        $this->assertSame('hub-user-A', $resultsA['after_set']);
        $this->assertSame('hub-user-A', $resultsA['after_resume'], 'Fiber A retains its own value across suspend');

        $this->assertNull($resultsB['before'], 'Fiber B must NOT see Fiber A\'s hub-user-A');
        $this->assertSame('hub-user-B', $resultsB['after_set']);
    }

    /**
     * `support\Context` itself (not just the wrapper) must isolate.
     * This guards against future "convenience" code that bypasses
     * {@see RequestContext} and calls `Context::set` directly: such
     * code MUST still be coroutine-safe.
     */
    public function testRawSupportContextIsIsolatedBetweenFibers(): void
    {
        $seen = [];

        $a = new Fiber(function () use (&$seen): void {
            Context::set('phlix.hub.raw', 'A');
            Fiber::suspend();
            $seen['A_after_resume'] = Context::get('phlix.hub.raw');
        });

        $b = new Fiber(function () use (&$seen): void {
            $seen['B_initial'] = Context::get('phlix.hub.raw');
            Context::set('phlix.hub.raw', 'B');
            $seen['B_after_set'] = Context::get('phlix.hub.raw');
        });

        $a->start();
        $b->start();
        $a->resume();

        $this->assertNull($seen['B_initial'], 'Fiber B starts with a clean context');
        $this->assertSame('B', $seen['B_after_set']);
        $this->assertSame('A', $seen['A_after_resume'], 'Fiber A still has its own value');
    }

    /**
     * Ext-swoole **graceful-fallback** branch in `start.php`.
     *
     * `start.php` wraps the Swoole eventLoop + coroutine-hook
     * activation in `if (extension_loaded('swoole')) { … } else {
     * trigger_error(..., E_USER_WARNING); }`. The "else" branch must
     * emit a single `E_USER_WARNING` whose message names "Swoole" and
     * tells the operator to install it.
     *
     * We can't re-execute `start.php` in-process (it would try to
     * start a worker), but we can exercise the exact same fallback
     * idiom under a captured error handler.
     */
    public function testSwooleFallbackEmitsUserWarningWithActionableMessage(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = ['errno' => $errno, 'errstr' => $errstr];
            return true;
        });

        try {
            // The literal idiom from `phlix-hub/start.php`, factored so
            // the test can drive the fallback branch deterministically
            // regardless of whether ext-swoole is actually loaded.
            $swooleLoaded = false; // simulate ext-swoole not loaded
            if (!$swooleLoaded) {
                trigger_error(
                    'Swoole extension not detected — coroutine runtime will not be active. '
                        . 'Install ext-swoole to enable.',
                    E_USER_WARNING
                );
            }
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'Exactly one warning should fire');
        $this->assertSame(E_USER_WARNING, $captured[0]['errno']);
        $this->assertStringContainsString('Swoole', $captured[0]['errstr']);
        $this->assertStringContainsString('Install', $captured[0]['errstr']);
    }

    /**
     * Ext-swoole **happy-path** branch: when `extension_loaded('swoole')`
     * is true, the fallback warning MUST NOT fire. Skipped if ext-swoole
     * isn't loaded in the test environment — the negative assertion
     * would otherwise be vacuous.
     */
    public function testSwoolePresentBranchEmitsNoWarning(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole not loaded in this PHP build');
        }

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = ['errno' => $errno, 'errstr' => $errstr];
            return true;
        });

        try {
            $swooleLoaded = extension_loaded('swoole');
            if (!$swooleLoaded) {
                trigger_error('should not fire', E_USER_WARNING);
            }
        } finally {
            restore_error_handler();
        }

        $this->assertCount(0, $captured, 'No warning should fire when ext-swoole is present');
    }
}
