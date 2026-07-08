<?php

/**
 * Phlix hub component: Container.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Container;

use RuntimeException;

/**
 * Thrown at container-build time when no usable JWT secret is configured
 * while running outside an explicitly-allowed development environment.
 *
 * Failing fast here prevents two problems:
 *  - a silent random per-process secret that invalidates every token on
 *    restart, and
 *  - the `workers > 1` split-secret bug where each worker would otherwise
 *    mint a different random secret, causing intermittent auth failures as
 *    requests land on workers that can't verify each other's tokens.
 *
 * @package Phlix\Hub\Common\Container
 */
final class MissingJwtSecretException extends RuntimeException
{
}
