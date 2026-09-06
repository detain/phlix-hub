<?php

/**
 * S179 control fixture — a child that dies of SIGSEGV (signal 11) after printing
 * good markers.
 *
 * This reproduces, deterministically and on ANY coverage driver, the shape of the
 * real defect: `transaction_lock_smoke.php` on master printed its four correct
 * markers and then segfaulted in `php_request_shutdown()` under
 * `xdebug.mode=coverage`. The signal is raised explicitly rather than provoked
 * through Xdebug, so this control works on the pcov dev box where the genuine
 * crash cannot happen — the point being that
 * {@see \Phlix\Hub\Tests\Support\SwooleFixtureProcess} must reject signal 11 even
 * though signal 9 is accepted.
 */

declare(strict_types=1);

echo "marker=ok\n";
flush();
fflush(STDOUT);

$pid = getmypid();
if (!is_int($pid)) {
    // Without a pid we cannot self-segfault; exit non-zero so the parent's
    // "must die by signal" assertion fails loudly instead of silently.
    exit(1);
}
posix_kill($pid, 11 /* SIGSEGV */);

// Not reached: SIGSEGV's default disposition terminates the process.
exit(0);
