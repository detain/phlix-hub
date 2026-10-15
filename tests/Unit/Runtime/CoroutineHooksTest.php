<?php

/**
 * Phlix hub component: Runtime tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Runtime;

use Phlix\Hub\Runtime\CoroutineHooks;
use Phlix\Hub\Runtime\HookDeliveryException;
use Phlix\Hub\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Worker;

/**
 * Composition tests for {@see CoroutineHooks} (R14-01 / R16-01): the mask a
 * worker start resolves from config, the ACK it logs, the loud escape of a
 * delivery failure, and the prepend-ordering law of {@see CoroutineHooks::wrap()}.
 *
 * All tests inject the testing seams — no test mutates this process's physical
 * hook set (the real enforce+verify path is pinned behaviourally by
 * {@see CuratedHookDeliveryProbeTest} instead).
 *
 * @package Phlix\Hub\Tests\Unit\Runtime
 */
final class CoroutineHooksTest extends TestCase
{
    /** @var list<int> masks the injected enforcer was asked to deliver */
    private array $deliveredMasks = [];

    /** @var list<string> messages the injected sink received */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->deliveredMasks = [];
        $this->logged = [];
        CoroutineHooks::resetForTesting();
    }

    protected function tearDown(): void
    {
        CoroutineHooks::resetForTesting();
    }

    private function requireSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('apply() no-ops without ext-swoole — nothing to observe here.');
        }
    }

    private function injectRecordingSeams(): void
    {
        CoroutineHooks::setEnforcerForTesting(function (int $mask): array {
            $this->deliveredMasks[] = $mask;

            return ['ticks' => 7, 'blocked_ms' => 150.2];
        });
        CoroutineHooks::setLogSinkForTesting(function (string $message): void {
            $this->logged[] = $message;
        });
    }

    public function testApplyIsSilentNoopWithoutTheExtension(): void
    {
        if (extension_loaded('swoole')) {
            self::markTestSkipped('This host has ext-swoole loaded; the noop branch is unreachable.');
        }

        // A recording enforcer would PROVE the early exit: reaching delivery
        // at all (or logging) without the extension is the violation.
        CoroutineHooks::setEnforcerForTesting(static function (int $mask): array {
            throw new \LogicException('delivery must not run without ext-swoole');
        });
        CoroutineHooks::setLogSinkForTesting(static function (string $message): void {
            throw new \LogicException('no ACK may be logged without ext-swoole');
        });

        CoroutineHooks::apply([]);

        $this->addToAssertionCount(1); // apply() returned without touching delivery.
    }

    public function testApplyResolvesConfiguredMaskAndLogsTheDeliveryAck(): void
    {
        $this->requireSwoole();
        $this->injectRecordingSeams();

        CoroutineHooks::apply(['coroutine' => ['hook_flags' => 0x2a]]);

        self::assertSame([0x2a], $this->deliveredMasks);
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('coroutine hook allowlist DELIVERED', $this->logged[0]);
        self::assertStringContainsString('mask=0x002a', $this->logged[0]);
        self::assertStringContainsString('probe 7 ticks / 150 ms', $this->logged[0]);
    }

    public function testApplyDefaultsToTheCuratedAllowlistMask(): void
    {
        $this->requireSwoole();
        $this->injectRecordingSeams();

        CoroutineHooks::apply([]);

        self::assertSame([SwooleRuntime::safeHookFlags()], $this->deliveredMasks);
    }

    public function testApplyDeliversZeroWhenCoroutinesAreDisabled(): void
    {
        $this->requireSwoole();
        $this->injectRecordingSeams();

        // Hard-disabled must still DELIVER (mask 0 un-hooks what the vendor
        // constructor installed) — the S433 both-directions config law.
        CoroutineHooks::apply(['coroutine' => ['enabled' => false, 'hook_flags' => 0x2]]);

        self::assertSame([0], $this->deliveredMasks);
        self::assertCount(1, $this->logged, 'the zero-mask delivery is still an ACK-able success');
    }

    public function testApplyPropagatesDeliveryFailureLoudly(): void
    {
        $this->requireSwoole();

        CoroutineHooks::setEnforcerForTesting(static function (int $mask): array {
            throw new HookDeliveryException('planted non-delivery');
        });

        $this->expectException(HookDeliveryException::class);
        $this->expectExceptionMessage('planted non-delivery');

        CoroutineHooks::apply([]);
    }

    /**
     * REVIEW-FIX (hook-mask HTTP arm): behavioral counterpart of the
     * structural pin in HookEnforcementGuardTest. A delivery failure raised
     * through the WRAPPED callback must escape — never be swallowed by a
     * downstream best-effort try inside the original. If wrap() ever moved
     * self::apply() after (or inside a guarded call to) the original, or the
     * composition order flipped, this reddens: the exception must surface at
     * the invocation site and the original must not have run.
     */
    public function testWrapLetsTheEnforcementThrowEscapeAndStarvesTheOriginal(): void
    {
        $this->requireSwoole();

        CoroutineHooks::setEnforcerForTesting(static function (int $mask): array {
            throw new HookDeliveryException('planted wrap-escape');
        });
        CoroutineHooks::setLogSinkForTesting(static function (string $message): void {
        });

        $originalRan = false;
        $worker = new Worker();
        $worker->onWorkerStart = static function (Worker $w) use (&$originalRan): void {
            $originalRan = true;
        };

        CoroutineHooks::wrap($worker, ['coroutine' => ['enabled' => true]]);

        $callback = $worker->onWorkerStart;
        self::assertIsCallable($callback);

        try {
            $callback($worker);
            self::fail('the enforcement throw must escape the wrapped callback, not be caught');
        } catch (HookDeliveryException $e) {
            self::assertSame('planted wrap-escape', $e->getMessage());
        }

        self::assertFalse($originalRan, 'a worker whose hook mask failed to deliver must never proceed');
    }

    public function testWrapRunsTheEnforcementStepBeforeTheOriginalCallback(): void
    {
        $this->requireSwoole();

        /** @var list<string> $order */
        $order = [];
        CoroutineHooks::setEnforcerForTesting(static function (int $mask) use (&$order): array {
            $order[] = 'enforce:' . dechex($mask);

            return ['ticks' => 0, 'blocked_ms' => 151.0];
        });
        CoroutineHooks::setLogSinkForTesting(static function (string $message): void {
        });

        $worker = new Worker();
        $seen = null;
        $worker->onWorkerStart = static function (Worker $w) use (&$order, &$seen): void {
            $order[] = 'original';
            $seen = $w;
        };

        CoroutineHooks::wrap($worker, ['coroutine' => ['hook_flags' => 0x2a]]);

        // Invoke the property exactly the way Workerman does: ($cb)($worker).
        $callback = $worker->onWorkerStart;
        self::assertIsCallable($callback);
        $callback($worker);

        self::assertSame(['enforce:2a', 'original'], $order);
        self::assertSame($worker, $seen);
    }

    public function testWrapOnAWorkerWithoutAnOriginalCallbackStillDelivers(): void
    {
        $this->requireSwoole();
        $this->injectRecordingSeams();

        $worker = new Worker();
        self::assertNull($worker->onWorkerStart);

        CoroutineHooks::wrap($worker, []);

        $callback = $worker->onWorkerStart;
        self::assertIsCallable($callback);
        $callback($worker);

        self::assertSame([SwooleRuntime::safeHookFlags()], $this->deliveredMasks);
    }

    public function testWrapNestsBeforeAnEarlierWrapWithoutReordering(): void
    {
        $this->requireSwoole();

        /** @var list<string> $order */
        $order = [];
        CoroutineHooks::setEnforcerForTesting(static function (int $mask) use (&$order): array {
            $order[] = 'enforce';

            return ['ticks' => 0, 'blocked_ms' => 151.0];
        });
        CoroutineHooks::setLogSinkForTesting(static function (string $message): void {
        });

        $worker = new Worker();
        $worker->onWorkerStart = static function (Worker $w) use (&$order): void {
            $order[] = 'class-callback';
        };

        // Double-wrap (e.g. a future refactor registering from two files) must
        // keep enforcement FIRST, never starve the first wrap's original.
        CoroutineHooks::wrap($worker, []);
        CoroutineHooks::wrap($worker, []);

        $callback = $worker->onWorkerStart;
        self::assertIsCallable($callback);
        $callback($worker);

        self::assertSame(['enforce', 'enforce', 'class-callback'], $order);
    }

    public function testResetForTestingClearsBothSeams(): void
    {
        $enforcer = new ReflectionProperty(CoroutineHooks::class, 'enforcerOverride');
        $sink = new ReflectionProperty(CoroutineHooks::class, 'logSinkOverride');

        CoroutineHooks::setEnforcerForTesting(static fn(int $mask): array => ['ticks' => 0, 'blocked_ms' => 0.0]);
        CoroutineHooks::setLogSinkForTesting(static function (string $message): void {
        });
        self::assertNotNull($enforcer->getValue());
        self::assertNotNull($sink->getValue());

        CoroutineHooks::resetForTesting();

        self::assertNull($enforcer->getValue());
        self::assertNull($sink->getValue());
    }
}
