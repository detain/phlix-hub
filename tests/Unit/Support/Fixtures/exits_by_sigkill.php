<?php

/**
 * S179 control fixture — the SANCTIONED shape: print, flush, die by SIGKILL.
 *
 * Used by {@see \Phlix\Hub\Tests\Unit\Support\SwooleFixtureProcessTest} to prove
 * {@see \Phlix\Hub\Tests\Support\SwooleFixtureProcess} accepts (and returns the
 * output of) a child that isolated itself the way the coroutine fixtures do.
 * Deliberately uses NO coroutines, so the expectation is identical on every
 * coverage driver.
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use Phlix\Hub\Tests\Support\SwooleShutdownIsolation;

echo "marker=ok\n";
echo 'argv=' . implode(',', array_slice($argv, 1)) . "\n";

SwooleShutdownIsolation::terminateWithoutRequestShutdown();
