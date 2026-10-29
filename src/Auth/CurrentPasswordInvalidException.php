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
 * Thrown by {@see AuthManager::changePassword()} when the supplied
 * current-password proof does not verify against the account.
 *
 * A distinct type so the HTTP layer can map it to the credential frame
 * (401 + `auth.invalid_credentials`, exactly like a failed login) instead
 * of lumping "you failed to prove ownership" in with "your new password
 * is malformed" (400 + `validation_failed`). Mirrors why
 * {@see SignupsDisabledException} exists for the register path.
 *
 * @package Phlix\Hub\Auth
 */
final class CurrentPasswordInvalidException extends RuntimeException
{
}
