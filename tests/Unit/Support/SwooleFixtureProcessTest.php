<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use Phlix\Hub\Tests\Support\SwooleFixtureProcess;
use Phlix\Hub\Tests\Support\SwooleShutdownIsolation;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * S179 — the guard that watches the coroutine fixtures, watched in turn.
 *
 * `SwooleFixtureProcess` exists because `shell_exec('… 2>/dev/null')` discarded
 * the exit status of the two Swoole fixtures, so a child that printed correct
 * markers and then died of SIGSEGV in PHP's request shutdown read as a pass. A
 * guard for that must itself be provable, and provable *here* — the dev box
 * defaults to pcov, where the real segfault cannot happen at all, so every
 * control below raises its condition deterministically rather than relying on
 * Xdebug being loaded.
 *
 * Each test names the mutation it kills:
 *
 * | control fixture           | mutation it stands in for                          |
 * | ------------------------- | -------------------------------------------------- |
 * | `exits_by_sigkill.php`    | none — the sanctioned shape must still pass        |
 * | `exits_cleanly.php`       | the fixture's `terminateWithoutRequestShutdown()` call is deleted |
 * | `dies_by_sigsegv.php`     | the real Xdebug shutdown crash reaches the fixture  |
 * | `exits_nonzero.php`       | the fixture fails early (and `2>/dev/null` used to eat the reason) |
 * | `hangs_forever.php`       | a fixture that never finishes                       |
 * | `writes_large_output.php` | a drain implementation that deadlocks on a full pipe |
 *
 * @package Phlix\Hub\Tests\Unit\Support
 *
 * @covers \Phlix\Hub\Tests\Support\SwooleFixtureProcess
 * @covers \Phlix\Hub\Tests\Support\SwooleShutdownIsolation
 */
final class SwooleFixtureProcessTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }

    /**
     * The sanctioned shape: markers are returned, argv is forwarded, and the
     * child's death by SIGKILL is reported back.
     *
     * Also proves the output written immediately before an uncatchable SIGKILL is
     * NOT lost — the pipe holds it and the parent reads it after the child dies.
     */
    public function testReturnsTheOutputOfAChildThatIsolatedItselfWithSigkill(): void
    {
        $result = SwooleFixtureProcess::run(
            $this->fixture('exits_by_sigkill.php'),
            ['alpha', 'beta'],
        );

        self::assertSame("marker=ok\nargv=alpha,beta\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertSame(SwooleShutdownIsolation::SIGKILL, $result['termsig']);
    }

    /**
     * THE load-bearing control. A child that prints perfect markers and exits 0
     * must fail — that is precisely what the coroutine fixtures did on the pcov
     * dev box before S179, and what they would do again the moment someone
     * removed the terminator.
     */
    public function testAChildThatExitsCleanlyFailsBecauseTheIsolationIsNeutered(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches(
            '/must terminate by signal 9 \(SIGKILL\), but it exited with code 0/',
        );
        $this->expectExceptionMessageMatches('/isolation has been neutered/');

        SwooleFixtureProcess::run($this->fixture('exits_cleanly.php'));
    }

    /**
     * The real defect's shape: correct markers, then death by SIGSEGV. Accepting
     * signal 9 must not mean accepting "any signal".
     */
    public function testAChildKilledBySigsegvFailsAndNamesTheSignal(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/died of signal 11 \(SIGSEGV\)/');

        SwooleFixtureProcess::run($this->fixture('dies_by_sigsegv.php'));
    }

    /**
     * A non-zero exit is a failure too (the sanctioned exit is a SIGNAL, not
     * "anything non-zero"), and the child's stderr — which `2>/dev/null` used to
     * throw away — is surfaced in the message.
     */
    public function testANonZeroExitFailsAndSurfacesTheChildsStderr(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/exited with code 3/');
        $this->expectExceptionMessageMatches('/diagnostic-on-stderr/');

        SwooleFixtureProcess::run($this->fixture('exits_nonzero.php'));
    }

    /**
     * A fixture that never finishes is killed and reported, not waited on
     * forever — and the runner's own kill is not mistaken for the child's
     * sanctioned SIGKILL.
     */
    public function testAChildThatOverrunsItsBudgetIsKilledAndFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/did not finish within its 2\.0 s budget/');

        SwooleFixtureProcess::run($this->fixture('hangs_forever.php'), [], 2.0);
    }

    /**
     * More than one pipe buffer on BOTH streams at once. Two sequential blocking
     * `stream_get_contents()` calls deadlock on this input; the `stream_select()`
     * drain loop must return every byte.
     */
    public function testLargeOutputOnBothPipesIsDrainedWithoutDeadlock(): void
    {
        $result = SwooleFixtureProcess::run($this->fixture('writes_large_output.php'), [], 30.0);

        self::assertSame(200_000, strlen($result['stdout']));
        self::assertSame(150_000, strlen($result['stderr']));
        self::assertSame(str_repeat('o', 32), substr($result['stdout'], 0, 32));
        self::assertSame(str_repeat('e', 32), substr($result['stderr'], 0, 32));
    }

    /**
     * A fixture path that does not exist fails immediately rather than being
     * launched and reported as a mysterious exit code.
     */
    public function testAMissingFixtureScriptFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/coroutine fixture script must exist/');

        SwooleFixtureProcess::run($this->fixture('no_such_fixture_' . bin2hex(random_bytes(4)) . '.php'));
    }
}
