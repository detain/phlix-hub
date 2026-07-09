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
     * Channel event the HTTP workers publish cancel requests on; the relay-ws
     * worker subscribes to it. Published when the browser aborts a streaming
     * request so the server can stop transferring early.
     */
    public const CANCEL_EVENT = 'phlix.relay.proxy.cancel';

    /**
     * Default localhost port the `workerman/channel` broker listens on.
     */
    public const DEFAULT_CHANNEL_PORT = 2206;

    /**
     * Default seconds an HTTP worker waits for the relayed response before
     * returning 504.
     *
     * Applies to small/quick responses the server produces with no on-demand
     * work: JSON browse, transcode-job status polling, and the transcode-START
     * POST (which only enqueues a job and returns).
     *
     * NOTE: HLS/DASH *playlists* do NOT fall under this default. The wider
     * streaming timeout is selected by path PREFIX (`/hls`, `/dash`), so the
     * per-variant `media_v{V}.m3u8` / `.mpd` playlists ride
     * {@see STREAMING_TIMEOUT_SECONDS} alongside their segments. That is
     * harmless — playlists respond in milliseconds, well under either bound;
     * the segments are what genuinely need the wider ceiling. See
     * {@see \Phlix\Hub\Http\Controllers\ServerProxyController::STREAMING_TIMEOUT_PREFIXES}
     * for the exact prefix set.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * The paired media server's on-demand segment-encode first-byte ceiling, in
     * seconds.
     *
     * Mirrors phlix-server `TranscodeManager::SEGMENT_MAX_WAIT_MS` (30_000 ms):
     * a cold HLS/DASH segment is fully decoded+encoded before the server emits
     * its first byte, so first-byte latency can approach this bound under load
     * (e.g. heavy HEVC→H.264). Recorded here so the hub's playback-read reply
     * timeout ({@see STREAMING_TIMEOUT_SECONDS}) is provably ABOVE it and cannot
     * 504 a slow-but-successful first segment. This constant is documentation of
     * a cross-repo invariant, not a hub timer value.
     */
    public const SEGMENT_ENCODE_CEILING_SECONDS = 30;

    /**
     * Seconds an HTTP worker waits for a PLAYBACK-READ relayed response
     * (HLS/DASH segments and playlists) before returning 504.
     *
     * Must clear the server's {@see SEGMENT_ENCODE_CEILING_SECONDS} (30s
     * first-byte) PLUS the time to move the whole 2–10 MB segment body across
     * the tunnel and reassemble it: in the current buffered relay model the hub
     * reply is delivered only when the body completes (END chunk), so this bound
     * is a TOTAL, not first-byte, timeout. 60s = phlix-server
     * `TranscodeManager::SEGMENT_PRODUCTION_TIMEOUT` (the server's own wedged-job
     * cutoff) and stays under the hls.js client `maxLoadTimeMs` of 120_000 ms —
     * so a genuinely stuck segment is governed by the client's fragment-load
     * policy, not a premature hub 504. Small responses keep
     * {@see DEFAULT_TIMEOUT_SECONDS}.
     */
    public const STREAMING_TIMEOUT_SECONDS = 60;

    /**
     * Prevent instantiation — constants only.
     *
     * @internal
     */
    private function __construct()
    {
    }
}
