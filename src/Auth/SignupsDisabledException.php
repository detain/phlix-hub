<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use RuntimeException;

/**
 * Thrown by {@see AuthManager::register()} when account self-service is
 * switched off (`auth.signups_enabled` is false in config/auth.php, i.e.
 * env `HUB_SIGNUPS_ENABLED=false`).
 *
 * A distinct type so the HTTP layer can map it to its own wire frame
 * (403 + `auth.signups_disabled`) instead of lumping "registrations are
 * closed" in with "your input was invalid" (400).
 *
 * @package Phlix\Hub\Auth
 */
final class SignupsDisabledException extends RuntimeException
{
}
