<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Support;

use Workerman\Timer;

/**
 * Production {@see ProcessReloaderInterface}: posix_kill + /proc + Workerman Timer.
 *
 * Extracted from HubRestartController (review hub__srphp #5, SH-070) so the
 * controller can be `final` per AGENTS.md while the whole untestable limb —
 * signals, /proc, and the event-loop deferral — lives behind an injected
 * boundary. The OS calls themselves are exercised by
 * {@see \Phlix\Hub\Tests\Unit\Hub\HubRestartControllerTest}: probe and cmdline
 * read are loop-independent (real posix against real pids), and the timer
 * deferral + graceful-signal laws are pinned against THIS file's source,
 * because there is no Workerman event loop under PHPUnit.
 *
 * @package Phlix\Hub\Common\Support
 */
final class WorkermanProcessReloader implements ProcessReloaderInterface
{
    /**
     * The graceful reload signal. Workerman treats SIGUSR1 and SIGUSR2 both
     * as "reload" but ONLY SIGUSR2 is graceful (`Worker.php:1385-1391` sets
     * `static::$gracefulStop = $signal === SIGUSR2`; `Worker.php:2009-2012`
     * arms the SIGKILL timer only when it is false). The hub carries
     * long-lived relay tunnels, so the non-graceful variant is the wrong
     * choice here. `scripts/install.sh`'s ExecReload sends the same signal so
     * `systemctl reload phlix-hub` and the HTTP endpoint behave identically.
     */
    public const int RELOAD_SIGNAL = SIGUSR2;

    /**
     * Seconds to wait before signalling, so the JSON ack is fully flushed to
     * the client first. Short enough to feel instant, long enough for the
     * response write to complete.
     */
    private const float SIGNAL_DELAY_SECONDS = 0.2;

    public function isSignallable(int $pid): bool
    {
        // Signal 0 performs the permission + existence check without delivering
        // anything: a stale pid file still yields a real error response instead
        // of a cheerful ack the deferred signal would silently contradict.
        return posix_kill($pid, 0);
    }

    public function readCmdline(int $pid): ?string
    {
        $path = '/proc/' . $pid . '/cmdline';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    public function scheduleGracefulReload(int $pid): void
    {
        // One-shot: the trailing `false` is REQUIRED — Workerman's Timer::add()
        // repeats by default, and a repeating reload signal would cycle the
        // workers forever.
        Timer::add(
            self::SIGNAL_DELAY_SECONDS,
            fn (): bool => posix_kill($pid, self::RELOAD_SIGNAL),
            [],
            false,
        );
    }
}
