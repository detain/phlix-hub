<?php

declare(strict_types=1);

/**
 * Legacy entry-point shim.
 *
 * The canonical Workerman bootstrap is now {@see ../start.php}. This
 * file remains so existing systemd units (pre-`start.php` installs)
 * that point at `public/index.php start` keep working — it just
 * forwards to the root-level bootstrap.
 *
 * New deploys should run `php start.php start` directly. The install
 * script writes that into the systemd unit on fresh installs.
 *
 * @deprecated since 0.7 — invoke start.php directly instead.
 */

require __DIR__ . '/../start.php';
