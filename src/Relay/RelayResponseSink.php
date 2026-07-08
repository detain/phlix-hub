<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

/**
 * Downstream sink for an HTTP response streamed back through the relay proxy.
 *
 * {@see RelayProxyBridge::stream()} drives one of these as it consumes the
 * server's response incrementally: exactly one {@see self::head()}, then zero or
 * more {@see self::body()} calls (each carrying one already-decoded body
 * fragment), then exactly one {@see self::end()}. The implementation writes each
 * fragment straight to the browser connection as it arrives, so a multi-MB HLS
 * segment (or a large direct-play stream) never has to be buffered whole on the
 * hub before the first byte reaches the client.
 *
 * The bridge itself knows nothing about HTTP framing or the browser socket — an
 * implementation (see {@see \Phlix\Hub\Http\ConnectionResponseSink}) owns the
 * status line, header policy, chunked-vs-fixed-length framing, and connection
 * back-pressure.
 *
 * @package Phlix\Hub\Relay
 * @since 0.11.0
 */
interface RelayResponseSink
{
    /**
     * Begin the response: emit the status line + headers to the downstream.
     *
     * Called exactly once, before any {@see self::body()} call.
     *
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Response headers (name => value).
     *
     * @return void
     */
    public function head(int $status, array $headers): void;

    /**
     * Write one body fragment to the downstream.
     *
     * @param string $bytes Raw (already base64-decoded) body fragment.
     *
     * @return bool True to keep streaming; false when the downstream is gone
     *              (e.g. the browser closed the connection) and the bridge should
     *              stop consuming.
     */
    public function body(string $bytes): bool;

    /**
     * Complete the response (flush any terminating framing and release the
     * downstream). Called exactly once, after the final {@see self::body()}.
     *
     * @return void
     */
    public function end(): void;

    /**
     * Abnormally terminate the response after an unrecoverable transport error
     * (e.g. an exception raised while consuming the relay).
     *
     * Called INSTEAD OF {@see self::end()} when {@see self::head()} or
     * {@see self::body()} may already have written real bytes to the downstream
     * before the failure — at that point sending any further response (a
     * synthesized error, or letting the exception surface to a generic
     * catch-all that would send its own buffered response) is unsafe: it would
     * write a second, unrelated response onto a connection that already has
     * partial HTTP framing on it, corrupting the wire protocol for this
     * connection (and any request reusing it, if kept-alive).
     *
     * Implementations MUST NOT throw (this is itself an error-recovery path —
     * a throw here has nowhere left to go) and MUST make a best-effort attempt
     * to force-close the underlying connection so the ambiguous partial
     * response can never be left open for reuse.
     *
     * @return void
     */
    public function abort(): void;
}
