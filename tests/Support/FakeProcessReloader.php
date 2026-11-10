<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\Support\ProcessReloaderInterface;
use Phlix\Hub\Common\Support\WorkermanProcessReloader;

/**
 * Recording fake for {@see ProcessReloaderInterface} (review SH-070).
 *
 * Replaces the old `TestableRestartController` subclass seam: instead of
 * opening the controller to inheritance so tests could intercept the signal,
 * the endpoint's three OS limbs (probe / cmdline witness / deferred reload)
 * are injected and recorded here. There is no event loop under PHPUnit, so
 * {@see fireScheduledReload()} stands in for the one-shot Workerman timer.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class FakeProcessReloader implements ProcessReloaderInterface
{
    /** @var list<int> Every pid handed to isSignallable(), in call order. */
    public array $probes = [];

    /** @var list<int> Pids handed to scheduleGracefulReload(), in call order. */
    public array $scheduled = [];

    /** @var list<array{int, int}> Every [pid, signal] actually delivered after a manual fire. */
    public array $delivered = [];

    /**
     * Witness handed to the Minor 55 identity gate; null = unreadable
     * /proc entry. Defaults to the honest hub master's argv so the
     * pre-existing success-path laws keep testing what they tested.
     */
    public ?string $cmdline = "php\0/opt/phlix-hub/start.php\0start\0";

    private ?bool $signallable;

    /** @var (callable(): void)|null The deferred reload, pending manual firing. */
    private $pendingReload = null;

    /**
     * @param bool|null $signallable probe verdict; null means "the fake was
     *                               configured without an opinion" and answers
     *                               true (the success-path default).
     */
    public function __construct(?bool $signallable = null)
    {
        $this->signallable = $signallable;
    }

    public function isSignallable(int $pid): bool
    {
        $this->probes[] = $pid;

        return $this->signallable ?? true;
    }

    public function readCmdline(int $pid): ?string
    {
        return $this->cmdline;
    }

    public function scheduleGracefulReload(int $pid): void
    {
        $this->scheduled[] = $pid;
        $this->pendingReload = function () use ($pid): void {
            // Stands in for WorkermanProcessReloader's deferred closure: the
            // reload limb the production adapter arms with posix_kill is the
            // graceful SIGUSR2 (RELOAD_SIGNAL — the fake records the SAME
            // constant the adapter uses, so a signal-choice drift there is
            // caught by the adapter's own source-pinned law below, not faked
            // away here).
            $this->delivered[] = [$pid, WorkermanProcessReloader::RELOAD_SIGNAL];
        };
    }

    /**
     * Run the deferred reload, standing in for the one-shot Workerman timer.
     *
     * @return bool True when a deferred reload was pending and has now fired.
     */
    public function fireScheduledReload(): bool
    {
        if ($this->pendingReload === null) {
            return false;
        }

        ($this->pendingReload)();
        $this->pendingReload = null;

        return true;
    }
}
