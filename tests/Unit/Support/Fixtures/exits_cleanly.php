<?php

/**
 * S179 control fixture — the NEUTERED shape: prints the same good markers and
 * then returns normally, so PHP request shutdown runs.
 *
 * This is byte-for-byte what a coroutine fixture looks like when someone deletes
 * its `terminateWithoutRequestShutdown()` call, and it is the mutation that must
 * go RED on the pcov dev box too — where the Xdebug shutdown segfault cannot
 * manifest, so "the output looked right" is all a weaker guard would see.
 */

declare(strict_types=1);

echo "marker=ok\n";
