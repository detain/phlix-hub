<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

/**
 * Shared constants for the cross-process HTTP-over-relay proxy.
 *
 * An authenticated `/api/v1/servers/{id}/proxy/*` request lands on an HTTP
 * worker process, but the server tunnel it must traverse lives in the separate
 * relay-ws worker process. The two communicate over a `workerman/channel`
 * broker: the HTTP worker publishes a request on {@see REQUEST_EVENT} and the
 * relay worker publishes the assembled response back on the per-request
 * `reply_event` carried in that message.
 *
 * @package Phlix\Hub\Relay
 * @since 0.10.0
 */
final class RelayProxyProtocol
{
    /**
     * Channel event the HTTP workers publish proxy requests on; the relay-ws
     * worker subscribes to it.
     */
    public const REQUEST_EVENT = 'phlix.relay.proxy.request';

    /**
     * Default localhost port the `workerman/channel` broker listens on.
     */
    public const DEFAULT_CHANNEL_PORT = 2206;

    /**
     * Default seconds an HTTP worker waits for the relayed response before
     * returning 504.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Prevent instantiation — constants only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
