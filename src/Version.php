<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Compile-time-constant package version marker.
 *
 * Keep this in sync with the git tag and the CHANGELOG entry.
 *
 * @package Phlix\Hub
 * @since 0.1.0
 */
final class Version
{
    /**
     * Current package version (semver).
     *
     * @var non-empty-string
     */
    public const VERSION = '0.1.0';

    /**
     * Prevent instantiation — static marker only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
