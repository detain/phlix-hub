<?php

/**
 * S179 control fixture — a child that fails with a non-zero exit code and writes
 * a diagnostic to stderr.
 *
 * Two properties are proven with it: a non-zero exit is still a failure (the
 * sanctioned exit is a SIGNAL, not "any non-zero"), and stderr survives — the
 * previous `shell_exec('… 2>/dev/null')` launcher discarded exactly this text.
 */

declare(strict_types=1);

fwrite(STDERR, "diagnostic-on-stderr\n");

exit(3);
