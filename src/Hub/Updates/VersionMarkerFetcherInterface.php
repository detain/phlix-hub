<?php

/**
 * Phlix hub component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub\Updates;

/**
 * Non-blocking fetcher for a remote plain-text version marker (S75).
 *
 * The contract is deliberately CALLBACK-shaped rather than
 * `fetch(string $url): ?string`: the hub is a resident-memory Workerman
 * process, so the only implementation allowed to reach the network must hand
 * control back to the event loop and be resumed by it. A return-a-string
 * signature can only be honoured by blocking (`file_get_contents`, blocking
 * cURL) or by suspending a coroutine — and the latter would fork the code path
 * on `Coroutine::getCid() > 0`, which under PHPUnit is ALWAYS false, i.e. the
 * suite would only ever exercise the arm production does not use.
 *
 * @package Phlix\Hub\Hub\Updates
 * @since   S75 (core update check)
 */
interface VersionMarkerFetcherInterface
{
    /**
     * Fetch `$url` and invoke `$onDone` exactly once with the outcome.
     *
     * Implementations MUST NOT block the calling worker. `$onDone` receives
     * either the response body (first argument) or an error message (second
     * argument); exactly one of the two is non-null.
     *
     * @param string                                  $url    Absolute http(s) URL of the marker.
     * @param callable(string|null, string|null):void $onDone Completion callback: (body, error).
     *
     * @return void
     */
    public function fetch(string $url, callable $onDone): void;
}
