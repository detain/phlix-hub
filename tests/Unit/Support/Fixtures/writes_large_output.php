<?php

/**
 * S179 control fixture — writes more than one pipe buffer (64 KiB on Linux) to
 * BOTH stdout and stderr, then isolates itself with SIGKILL.
 *
 * It exists to prove {@see \Phlix\Hub\Tests\Support\SwooleFixtureProcess}'s
 * `stream_select()` drain loop cannot deadlock. The obvious implementation —
 * `stream_get_contents($stdout)` followed by `stream_get_contents($stderr)` —
 * hangs forever here: the child blocks writing to the full stderr pipe while the
 * parent blocks reading stdout that will never reach EOF.
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use Phlix\Hub\Tests\Support\SwooleShutdownIsolation;

$stdoutBytes = 200_000;
$stderrBytes = 150_000;

// Interleaved in chunks so both pipes fill while the other is being written.
$chunk = 8192;
$written = 0;
$writtenErr = 0;

while ($written < $stdoutBytes || $writtenErr < $stderrBytes) {
    if ($written < $stdoutBytes) {
        $size = min($chunk, $stdoutBytes - $written);
        fwrite(STDOUT, str_repeat('o', $size));
        $written += $size;
    }

    if ($writtenErr < $stderrBytes) {
        $size = min($chunk, $stderrBytes - $writtenErr);
        fwrite(STDERR, str_repeat('e', $size));
        $writtenErr += $size;
    }
}

SwooleShutdownIsolation::terminateWithoutRequestShutdown();
