<?php

/**
 * S458 — thin entrypoint for the parallel PHPUnit runner.
 *
 * The logic lives in {@see \Phlix\Hub\Scripts\ParallelTestRunner}. This file is
 * deliberately side-effects-only (no top-level function/class/const declarations)
 * so it satisfies the PSR-1 SideEffects rule that phpcs enforces as a hard gate
 * failure (scripts/assert-phpcs-corpus.php counts warnings, not just errors): a
 * script that both declares symbols and executes them is rejected.
 *
 * See the class docblock for the full parallelization design and the argv
 * contract the CI "Run PHPUnit" step relies on.
 */

declare(strict_types=1);

use Phlix\Hub\Scripts\ParallelTestRunner;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ParallelTestRunner.php';

exit(ParallelTestRunner::main($argv ?? []));
