<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_numeric;
use function preg_match;

/**
 * Guards the graceful-stop budget pairing between start.php and the systemd
 * unit written by scripts/install.sh.
 *
 * Workerman's master SIGTERMs each child and SIGKILLs it after
 * Worker::$stopTimeout seconds — a vendor default of TWO. The hub serves long-
 * lived relay tunnels (:8802/:8803) and in-flight proxied streams, so a 2 s
 * kill window cuts live playback on every `systemctl stop`/restart, while the
 * unit simultaneously grants TimeoutStopSec=30 (28 s of budget unused).
 * start.php now pins Worker::$stopTimeout under the unit value; this pin keeps
 * the pair coherent — moving either side alone reddens here.
 *
 * Static source-pins, the repo's tripwire idiom: neither side is reachable
 * from a unit-test process (the daemon never boots in PHPUnit), and the law is
 * precisely about the shipped TEXT of both files.
 */
final class StopTimeoutBudgetTest extends TestCase
{
    private const START_PHP = __DIR__ . '/../../../start.php';
    private const INSTALL_SH = __DIR__ . '/../../../scripts/install.sh';

    /**
     * Lower bound for a useful drain budget: below this the pin is
     * indistinguishable from the 2 s vendor default it replaces.
     */
    private const MIN_DRAIN_SECONDS = 5;

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "{$path} must be readable by the pin");

        return $source;
    }

    /**
     * Extract the single integer captured by $pattern, failing loud (never
     * trusting a possibly-unmatched $m[1]) when the anchor is absent.
     */
    private static function pinnedInt(string $subject, string $pattern, string $message): int
    {
        if (preg_match($pattern, $subject, $matches) !== 1 || !is_numeric($matches[1] ?? null)) {
            self::fail($message);
        }

        return (int) $matches[1];
    }

    public function testStartPhpPinsTheWorkerStopTimeoutExplicitly(): void
    {
        $budget = self::pinnedInt(
            $this->source(self::START_PHP),
            '/^Worker::\$stopTimeout = (\d+);$/m',
            'start.php must assign Worker::$stopTimeout explicitly'
        );

        self::assertGreaterThanOrEqual(
            self::MIN_DRAIN_SECONDS,
            $budget,
            'the stop budget must be a real drain window, not a re-statement of the 2 s vendor default'
        );
    }

    public function testTheDaemonBudgetSitsStrictlyUnderTheShippedUnitBudget(): void
    {
        $workerBudget = self::pinnedInt(
            $this->source(self::START_PHP),
            '/^Worker::\$stopTimeout = (\d+);$/m',
            'start.php must pin Worker::$stopTimeout (first case covers the bound)'
        );
        $unitBudget = self::pinnedInt(
            $this->source(self::INSTALL_SH),
            '/^TimeoutStopSec=(\d+)$/m',
            'the systemd unit heredoc in scripts/install.sh must keep a numeric TimeoutStopSec='
        );

        // The pairing law: the daemon must finish (and self-escalate to
        // SIGKILL) BEFORE systemd's hard cut, or the outer budget is theater.
        self::assertLessThan(
            $unitBudget,
            $workerBudget,
            'Worker::$stopTimeout must stay under the unit TimeoutStopSec — '
            . 'raise or lower BOTH together when the drain window moves'
        );
    }
}
