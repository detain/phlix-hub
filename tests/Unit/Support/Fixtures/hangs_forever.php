<?php

/**
 * S179 control fixture — a child that never finishes, to exercise the runner's
 * wall-clock budget.
 *
 * It holds stdout open (so the parent's drain loop cannot end on EOF) and sleeps.
 * The runner must kill it and FAIL, not hang the suite and not read the timeout
 * kill as the sanctioned SIGKILL exit.
 */

declare(strict_types=1);

echo "marker=started\n";

sleep(300);
