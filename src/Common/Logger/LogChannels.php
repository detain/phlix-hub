<?php

declare(strict_types=1);

namespace Phlex\Hub\Common\Logger;

/**
 * Log channel constants for consistent logger naming.
 *
 * Each public constant is a channel name handed to
 * {@see LoggerFactory::get()} so callers reference channels by symbol
 * rather than by string literal. New channels added here should also be
 * registered with the container in
 * {@see \Phlex\Hub\Common\Container\Providers\CoreServicesProvider::channels()}
 * so they are resolvable via `logger.<name>` aliases.
 *
 * @package Phlex\Hub\Common\Logger
 * @since 0.1.0
 */
final class LogChannels
{
    public const APPLICATION = 'application';
    public const HTTP = 'http';
    public const WEBSOCKET = 'websocket';
    public const DATABASE = 'database';
    public const AUTH = 'auth';
    public const HUB = 'hub';
    public const RELAY = 'relay';
    public const AUDIT = 'audit';

    /**
     * Prevent instantiation — this class is a static constant holder only.
     */
    private function __construct()
    {
    }
}
