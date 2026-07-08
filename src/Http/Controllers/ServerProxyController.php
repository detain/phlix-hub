<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\ConnectionResponseSink;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\ServerInfoDto;
use Workerman\Connection\TcpConnection;

use function base64_decode;
use function explode;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function rawurldecode;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * Proxies an authenticated `/api/v1/servers/{id}/proxy/{path:.*}` request to a
 * paired media server over the reverse relay tunnel.
 *
 * The controller authenticates the user, verifies they own the server, and
 * confirms a live relay session exists, then hands the request to the
 * {@see RelayProxyBridge} which round-trips it through the relay-ws worker (the
 * process that owns the tunnel) and returns the server's response.
 *
 * Scope: read-only traffic only — JSON browse (libraries, media lists, detail,
 * search) PLUS playback reads (HLS/DASH playlists + segments, the direct-play
 * byte stream, and transcode-job status polling) — with ONE narrowly-scoped
 * write exception: starting an on-demand transcode
 * (`POST /api/v1/media/{id}/transcode`), which a player needs before it can
 * stream an incompatible title. See {@see self::BROWSE_SCOPE_ALLOWLIST} for the
 * GET/HEAD path prefixes and {@see self::BROWSE_SCOPE_PATTERNS} for the exact,
 * anchored POST pattern; every other mutating method/path fails closed.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.10.0
 */
final class ServerProxyController
{
    /**
     * Request headers that must not be forwarded verbatim to the server.
     *
     * Hop-by-hop headers (RFC 9110) plus `host`/`content-length` (rebuilt by the
     * server) and `authorization`/`cookie` (the hub user credential is NOT the
     * server's — the tunnel itself is the trust boundary; identity is conveyed
     * via the `X-Phlix-Relay-User` header instead).
     *
     * @var list<string>
     */
    private const STRIPPED_REQUEST_HEADERS = [
        'host',
        'content-length',
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'authorization',
        'cookie',
        // Trust markers + forwarding headers: a client must never be able to
        // pre-set these — the hub stamps its own authenticated values in
        // buildForwardHeaders() AFTER stripping. Any inbound copy (in any case)
        // is dropped here so a forged X-Phlix-Relay-User / X-Forwarded-For can
        // never reach the server. See {@see self::TRUST_MARKER_PREFIXES}.
        'x-phlix-relay',
        'x-phlix-relay-user',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
        'x-real-ip',
    ];

    /**
     * Inbound header-name prefixes (lower-case) that are never forwarded from
     * the client regardless of suffix. Catches arbitrary `x-forwarded-*` and
     * `x-phlix-relay*` variants the explicit list above might not enumerate, so
     * no client-supplied trust/forwarding marker can survive into the tunnel.
     *
     * @var list<string>
     */
    private const STRIPPED_REQUEST_HEADER_PREFIXES = [
        'x-forwarded-',
        'x-phlix-relay',
    ];

    /**
     * Browse-scope allowlist: the only method + path-prefix combinations the
     * relay proxy is permitted to forward to a paired media server.
     *
     * The proxy exposes READ-ONLY traffic to an authenticated, server-owning
     * hub user in two families: (1) JSON browse — libraries, media lists/detail,
     * search, images/posters, OPDS catalog; and (2) playback reads — the HLS and
     * DASH playlists + segments, the direct-play byte stream, and transcode-job
     * status polling. Anything outside this set — notably admin, mutating, or
     * scan endpoints — is rejected with 403 `proxy.scope_denied` BEFORE
     * forwarding, so a compromised/confused client cannot use the hub as a
     * deputy to reach privileged server APIs. (The one permitted write — the
     * transcode-START POST — lives in {@see self::BROWSE_SCOPE_PATTERNS}, since
     * its id is a variable middle segment a prefix cannot express.)
     *
     * Keyed by HTTP method (upper-case); each value is a list of allowed path
     * prefixes matched against the resolved `/`-prefixed forward path by
     * {@see self::isWithinBrowseScope()} (exact match, or a `/`-delimited
     * sub-path — never a bare sibling like `/hlsX`). Path traversal can never
     * ride a widened prefix: {@see self::hasTraversalSegment()} rejects any
     * dot-segment or encoded separator BEFORE this allowlist is consulted.
     *
     * NOTE: only GET/HEAD are listed HERE. The only permitted non-GET/HEAD
     * request is the single anchored POST in {@see self::BROWSE_SCOPE_PATTERNS};
     * every other PUT/DELETE/PATCH (and any unlisted method/path) is
     * intentionally denied by the browse-scope gate. Denied mutating routes are
     * still registered in {@see \Phlix\Hub\Application} so they route to this
     * controller (and get a deliberate 403 via {@see self::isWithinBrowseScope()})
     * rather than a bare 404.
     *
     * @var array<string, list<string>>
     */
    private const BROWSE_SCOPE_ALLOWLIST = [
        'GET' => [
            // Browse / metadata (JSON).
            '/api/v1/libraries',
            '/api/v1/media',
            '/api/v1/search',
            '/api/v1/collections',
            '/api/v1/genres',
            '/api/v1/studios',
            '/api/v1/people',
            '/api/v1/images',
            '/api/v1/opds',
            // Playback reads (bytes). The server exposes HLS/DASH and the direct
            // byte stream at the ROOT (not under /api/v1), so the forward tail is
            // bare. `/hls` + `/dash` cover every per-variant playlist
            // (`media_v{V}.m3u8`), init/segment (`seg-*.ts`, `*.m4s`), subtitle
            // sidecar and the master manifest under a per-job directory. `/media`
            // covers ONLY the direct-play byte stream `/media/{id}/stream` — the
            // ONLY route registered under bare `/media/` at all (mutating or not).
            // The admin merge endpoint lives at `/api/v1/admin/media/merge` (a
            // different prefix, and denied by the method gate regardless).
            // `/api/v1/transcode` covers only `/api/v1/transcode/{jobId}/status`.
            '/hls',
            '/dash',
            '/media',
            '/api/v1/transcode',
        ],
        'HEAD' => [
            // Browse / metadata (JSON).
            '/api/v1/libraries',
            '/api/v1/media',
            '/api/v1/search',
            '/api/v1/collections',
            '/api/v1/genres',
            '/api/v1/studios',
            '/api/v1/people',
            '/api/v1/images',
            '/api/v1/opds',
            // Playback reads — mirror of the GET block (players issue HEAD to
            // probe segment size / range support before a ranged GET).
            '/hls',
            '/dash',
            '/media',
            '/api/v1/transcode',
        ],
    ];

    /**
     * Exact-match scope patterns for the narrow set of NON-prefix routes the
     * proxy may forward — currently the single permitted write.
     *
     * The prefix-based {@see self::BROWSE_SCOPE_ALLOWLIST} cannot express a route
     * whose variable segment sits in the MIDDLE of the path. Starting an
     * on-demand transcode is exactly such a route — the server registers it (in
     * `Phlix\Server\Http\Controllers\TranscodeController::start`) as
     * `POST /api/v1/media/{id}/transcode`, the id being a media UUID — and a
     * player must be able to start it over the hub before it can stream an
     * incompatible title. Each entry is a FULLY-ANCHORED (`^…$`) PCRE with the id
     * captured as a single `[^/]+` segment, so the match is exact: it accepts
     * ONLY `/api/v1/media/{id}/transcode` with nothing before or after it.
     *
     * This deliberately does NOT match the media item's OTHER mutating siblings —
     * `POST /api/v1/media/{id}/favorite`, `PUT …/rating`, `PUT …/like`,
     * `POST …/watched`|`/unwatched`, `POST …/match/apply`, `PUT …/poster` — nor
     * the admin `POST /api/v1/admin/media/merge`: the trailing literal `/transcode$`
     * plus the single-segment `[^/]+` id forbid every one of them, so they all stay 403
     * `proxy.scope_denied`. Path traversal is impossible here for the same reason
     * as the prefix allowlist: {@see self::hasTraversalSegment()} rejects any
     * dot-segment or encoded separator BEFORE this map is consulted.
     *
     * Keyed by HTTP method (upper-case); matched by
     * {@see self::isWithinBrowseScope()} AFTER the prefix allowlist.
     *
     * @var array<string, list<string>>
     */
    private const BROWSE_SCOPE_PATTERNS = [
        'POST' => [
            // Transcode-START ONLY. Anchored (`^…$`) + single-segment `[^/]+` id
            // so no other `/api/v1/media/{id}/*` POST (favorite/rating/like/
            // watched/unwatched/match/poster) and no `/api/v1/admin/media/merge`
            // can match.
            '#^/api/v1/media/[^/]+/transcode$#',
        ],
    ];

    /**
     * Response headers stripped before relaying back to the browser (the hub's
     * HTTP layer sets framing headers itself).
     *
     * @var list<string>
     */
    private const STRIPPED_RESPONSE_HEADERS = [
        'connection',
        'keep-alive',
        'transfer-encoding',
        'content-length',
    ];

    /**
     * Forward-path prefixes whose GET/HEAD responses can block on the paired
     * server's on-demand segment encoder, so they get the longer, encode-ceiling
     * aligned reply timeout ({@see RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS})
     * instead of the default.
     *
     * `/hls` and `/dash` cover the transcoded playback surface: a cold segment
     * (`seg-*.ts` / `*.m4s`) is fully decoded+encoded before the server emits its
     * first byte (up to {@see RelayProxyProtocol::SEGMENT_ENCODE_CEILING_SECONDS}
     * under load), so a 30s reply timeout — equal to that ceiling — races the
     * server and 504s a slow-but-successful first segment. Playlists under the
     * same prefixes respond in milliseconds, so the wider timeout is a harmless
     * upper bound for them.
     *
     * Deliberately EXCLUDED: `/media` (the direct-play byte stream is served from
     * disk via the server's `withFile()` — fast first byte, no encode — and is
     * range-requested, so it keeps the tighter default and its buffering window
     * is not widened) and `/api/v1/transcode` (status polling is a quick JSON
     * read). Matched with the same exact-or-`/`-subpath rule as
     * {@see self::BROWSE_SCOPE_ALLOWLIST} — never a bare sibling like `/hlsX`.
     *
     * @var list<string>
     */
    private const STREAMING_TIMEOUT_PREFIXES = [
        '/hls',
        '/dash',
    ];

    /**
     * Forward-path prefixes whose GET/HEAD responses are streamed straight to
     * the browser fragment-by-fragment ({@see self::buildStreamingResponse()})
     * instead of being buffered whole ({@see self::buildResponse()}).
     *
     * These are the byte-serving families whose bodies can be large: `/hls` and
     * `/dash` (per-variant playlists + on-demand `seg-*.ts`/`*.m4s` segments,
     * multiple MB each) and `/media` (the direct-play byte stream, which can be
     * an entire multi-GB file). Streaming them removes the per-request whole-body
     * memory spike the buffered proxy incurred on both the relay worker and the
     * HTTP worker, and lets a large direct-play response run past the old
     * total-body reply timeout (a slow un-ranged stream used to truncate). Tiny
     * playlists under `/hls`/`/dash` are matched too, but streaming them is
     * harmless — they emit a fragment or two and complete immediately.
     *
     * Deliberately EXCLUDED: JSON browse, `/api/v1/transcode/{jobId}/status`
     * polling, and the transcode-START POST — all small and simplest kept on the
     * buffered path. Matched with the same exact-or-`/`-subpath rule as
     * {@see self::BROWSE_SCOPE_ALLOWLIST}.
     *
     * @var list<string>
     */
    private const STREAMING_BODY_PREFIXES = [
        '/hls',
        '/dash',
        '/media',
    ];

    /**
     * @param ServerInfoHandler $serverInfo Resolves server ownership + relay status.
     * @param RelayProxyBridge  $bridge     Cross-process bridge to the relay worker.
     * @param StructuredLogger  $logger     Relay logger.
     * @param int               $timeoutSeconds Default seconds to await the server
     *        response for small/quick reads (JSON browse, transcode-job status,
     *        the transcode-START POST). GET/HEAD under a playback-read prefix
     *        ({@see self::STREAMING_TIMEOUT_PREFIXES}) — HLS/DASH segments AND
     *        their playlists alike, since the match is by path prefix, not
     *        filename — instead await the wider, encode-ceiling-aligned
     *        {@see RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS} so a slow first
     *        segment does not 504; playlists simply respond fast enough that the
     *        wider bound is harmless for them.
     */
    public function __construct(
        private readonly ServerInfoHandler $serverInfo,
        private readonly RelayProxyBridge $bridge,
        private readonly StructuredLogger $logger,
        private readonly int $timeoutSeconds = RelayProxyProtocol::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * Handle a proxy request.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Route params: `id` (server UUID) + `path` (catch-all).
     *
     * @return Response
     *
     * @since 0.10.0
     */
    public function proxy(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $serverId = $params['id'] ?? '';
        $server = $this->serverInfo->getServerInfo($serverId);
        if ($server === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'server.not_found',
            ]);
        }

        if ($server->userId !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'server.not_owned',
            ]);
        }

        if (!$server->relayActive) {
            // Distinguish "the server is heartbeating but its secure relay
            // tunnel isn't connected" from "the server is genuinely down".
            // `status` and `relayActive` are set by INDEPENDENT paths: a
            // heartbeat flips `status='online'` (HeartbeatHandler) but never
            // opens a relay session, while `relayActive` is derived from an
            // open `relay_sessions` row created only when the server's reverse
            // tunnel connects+authenticates on :8802 (Tunnel::handleHelloFrame
            // → RelaySessionManager::registerServer). So an online server can
            // legitimately have no tunnel. Either way we still REFUSE to proxy
            // (the tunnel is the trust boundary and there is nothing to forward
            // to) — only the code/message differ so the UI can explain why.
            if ($server->status === ServerInfoDto::STATUS_ONLINE) {
                return (new Response())->status(503)->json([
                    'error' => 'Relay tunnel unavailable',
                    'code' => 'server.relay_unavailable',
                    'message' => 'This server is online but its secure relay tunnel isn\'t connected. '
                        . 'Browsing over the hub isn\'t available until the tunnel reconnects.',
                ]);
            }

            return (new Response())->status(503)->json([
                'error' => 'Server offline',
                'code' => 'server.offline',
                'message' => 'Server is offline.',
            ]);
        }

        $path = '/' . ltrim($params['path'] ?? '', '/');

        // Defence-in-depth: the hub pipeline performs NO path normalisation
        // (FastRoute `{path:.*}`, Workerman path()/parse_url() leave dot
        // segments intact), so a path like `/api/v1/libraries/../admin/users`
        // would pass the prefix allowlist below yet resolve, server-side, to a
        // privileged admin endpoint. Reject any path containing a dot-segment
        // (raw or percent-encoded) or a back-slash BEFORE the allowlist runs so
        // the proxy can never be used to traverse out of the browse scope.
        if ($this->hasTraversalSegment($path)) {
            $this->logger->info('Relay proxy: rejected traversal attempt', [
                'server_id' => $serverId,
                'user_id' => $userId,
                'method' => $request->method,
                'path' => $path,
            ]);

            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'proxy.scope_denied',
                'message' => 'This method/path is not exposed through the relay proxy.',
            ]);
        }

        if (!$this->isWithinBrowseScope($request->method, $path)) {
            $this->logger->info('Relay proxy: rejected out-of-scope request', [
                'server_id' => $serverId,
                'user_id' => $userId,
                'method' => $request->method,
                'path' => $path,
            ]);

            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'proxy.scope_denied',
                'message' => 'This method/path is not exposed through the relay proxy.',
            ]);
        }

        $headers = $this->buildForwardHeaders($request, $userId);
        $body = $this->reconstructBody($request);

        $this->logger->info('Relay proxy: forwarding browser request', [
            'server_id' => $serverId,
            'user_id' => $userId,
            'method' => $request->method,
            'path' => $path,
        ]);

        $timeout = $this->replyTimeoutForPath($request->method, $path);

        // Large byte-serving reads (HLS/DASH segments, direct-play stream) are
        // streamed straight to the browser without buffering the whole body on
        // the hub; everything else keeps the simple buffered round-trip.
        if ($this->isStreamingPath($request->method, $path)) {
            return $this->buildStreamingResponse(
                $serverId,
                $request->method,
                $path,
                $request->queryString,
                $headers,
                $body,
                $timeout,
            );
        }

        $reply = $this->bridge->request(
            $serverId,
            $request->method,
            $path,
            $request->queryString,
            $headers,
            $body,
            $timeout,
        );

        if ($reply === null) {
            return (new Response())->status(504)->json([
                'error' => 'Gateway Timeout',
                'code' => 'gateway.timeout',
                'message' => 'The server did not respond over the relay in time.',
            ]);
        }

        return $this->buildResponse($reply);
    }

    /**
     * Whether a method + resolved path should be streamed to the browser
     * fragment-by-fragment rather than buffered whole.
     *
     * Only GET/HEAD under a {@see self::STREAMING_BODY_PREFIXES} family qualify;
     * these paths already passed the browse-scope gate (so a mutating method
     * never reaches here). The match uses the same exact-or-`/`-subpath rule as
     * the allowlist — never a bare sibling like `/hlsX`.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return bool True when the response should be streamed.
     */
    private function isStreamingPath(string $method, string $path): bool
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        foreach (self::STREAMING_BODY_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a streaming pass-through response.
     *
     * Returns a {@see Response} carrying a producer closure the HTTP worker
     * invokes with the live browser connection. The closure drives
     * {@see RelayProxyBridge::stream()}, which forwards each response fragment to
     * a {@see ConnectionResponseSink} the moment it arrives from the paired
     * server — so a multi-MB segment (or a whole direct-play file) never has to
     * be buffered on the hub before the first byte reaches the client.
     *
     * @param string                $serverId Target server UUID.
     * @param string                $method   HTTP method.
     * @param string                $path     Resolved `/`-prefixed forward path.
     * @param string                $query    Raw query string (no leading '?').
     * @param array<string, string> $headers  Forwarded request headers.
     * @param string                $body     Raw request body.
     * @param float                 $timeout  Per-phase relay wait (seconds).
     *
     * @return Response
     */
    private function buildStreamingResponse(
        string $serverId,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        float $timeout,
    ): Response {
        $bridge = $this->bridge;
        $producer = static function (TcpConnection $connection) use (
            $bridge,
            $serverId,
            $method,
            $path,
            $query,
            $headers,
            $body,
            $timeout,
        ): void {
            $bridge->stream(
                $serverId,
                $method,
                $path,
                $query,
                $headers,
                $body,
                $timeout,
                new ConnectionResponseSink($connection, $method),
            );
        };

        return (new Response())->status(200)->stream($producer);
    }

    /**
     * Detect any path-traversal / dot-segment smuggling in the raw forward
     * path, in either literal or percent-encoded form.
     *
     * The hub does not normalise paths before forwarding, so a single dot
     * segment (`.` / `..`) — or a percent-encoded variant such as `%2e`,
     * `%2E`, `..%2f`, or `%2f` used to smuggle an extra separator — must be
     * rejected outright. We decode percent-encoding once and re-scan, and also
     * reject back-slashes (alternative separators on some stacks).
     *
     * @param string $path The raw `/`-prefixed forward path (un-normalised).
     *
     * @return bool True when the path contains a traversal/dot-segment.
     */
    private function hasTraversalSegment(string $path): bool
    {
        // A `%2f`/`%2F` in the raw path is an encoded separator used to smuggle
        // an extra path segment past the single-pass split below; treat it as a
        // traversal attempt regardless of what surrounds it.
        $rawLower = strtolower($path);
        if (str_contains($rawLower, '%2f') || str_contains($rawLower, '%5c')) {
            return true;
        }

        // Back-slashes are never legitimate in our REST paths and act as
        // separators on some downstream stacks.
        if (str_contains($path, '\\')) {
            return true;
        }

        // Scan both the raw path and its once-decoded form so `.`/`..` survive
        // neither literally nor via `%2e`/`%2E` encoding.
        foreach ([$path, rawurldecode($path)] as $candidate) {
            if (str_contains($candidate, '\\')) {
                return true;
            }
            foreach (explode('/', $candidate) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether a method + resolved path is within the browse-scope the
     * proxy is permitted to forward.
     *
     * Two layers, checked in order: the prefix
     * {@see self::BROWSE_SCOPE_ALLOWLIST} (GET/HEAD read families) and the
     * exact-match {@see self::BROWSE_SCOPE_PATTERNS} (the sole permitted write,
     * transcode-START). A request is in scope when it matches EITHER layer for
     * its method; every other method/path returns false and fails closed.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return bool True when the request may be forwarded.
     */
    private function isWithinBrowseScope(string $method, string $path): bool
    {
        $method = strtoupper($method);

        $allowedPrefixes = self::BROWSE_SCOPE_ALLOWLIST[$method] ?? [];
        foreach ($allowedPrefixes as $prefix) {
            // Match either an exact collection path or a sub-path under it
            // (e.g. `/api/v1/media/abc`), never a sibling like
            // `/api/v1/mediaXYZ`.
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // Exact, fully-anchored patterns for the narrow non-prefix routes
        // (currently only the transcode-START POST). Anchoring + a single-segment
        // id guarantees no other `/api/v1/media/{id}/*` mutation can match.
        $allowedPatterns = self::BROWSE_SCOPE_PATTERNS[$method] ?? [];
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the reply timeout (seconds) to await for a given method + path.
     *
     * GET/HEAD under a playback-read prefix ({@see self::STREAMING_TIMEOUT_PREFIXES})
     * await the wider {@see RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS} —
     * provably above the server's on-demand segment-encode ceiling
     * ({@see RelayProxyProtocol::SEGMENT_ENCODE_CEILING_SECONDS}) plus
     * tunnel-transfer time — to avoid 504-ing a slow-but-successful first
     * segment. The match is by path PREFIX, not filename, so a prefix's
     * playlists ride the same wider bound as its segments — harmlessly, since
     * playlists respond in milliseconds either way. Every other read (JSON
     * browse, transcode-job status) and the transcode-START POST keep the
     * injected default (`$this->timeoutSeconds`). When a higher-than-streaming
     * default is injected it is honoured (we never SHORTEN a request by
     * classifying it as a stream).
     *
     * This same value is forwarded to the relay worker (via
     * {@see RelayProxyBridge::request()}) so its per-request completion timer
     * uses the identical ceiling — otherwise the relay worker's own timer would
     * 504 a streaming request at the default before the browser-facing wait
     * elapsed.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return float Seconds to await the relayed response.
     */
    private function replyTimeoutForPath(string $method, string $path): float
    {
        $default = (float) $this->timeoutSeconds;

        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $default;
        }

        foreach (self::STREAMING_TIMEOUT_PREFIXES as $prefix) {
            // Exact collection path or a `/`-delimited sub-path under it — never
            // a bare sibling like `/hlsX` (mirrors isWithinBrowseScope()).
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return max($default, (float) RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS);
            }
        }

        return $default;
    }

    /**
     * Build the header set forwarded to the server over the tunnel.
     *
     * @param Request $request The inbound request.
     * @param string  $userId  The authenticated hub user id.
     *
     * @return array<string, string>
     */
    private function buildForwardHeaders(Request $request, string $userId): array
    {
        $headers = [];
        // Request::$headers is typed array<string, string>, so no is_string()
        // guard is needed here (Psalm flags one as a dead contradiction).
        foreach ($request->headers as $name => $value) {
            if ($this->isStrippedRequestHeader($name)) {
                continue;
            }
            $headers[$name] = $value;
        }

        // Trust markers: the hub has authenticated the user and verified
        // ownership, so the server runs the request as this user.
        $headers['X-Phlix-Relay'] = '1';
        $headers['X-Phlix-Relay-User'] = $userId;
        if ($request->remoteIp !== '') {
            $headers['X-Forwarded-For'] = $request->remoteIp;
        }

        return $headers;
    }

    /**
     * Decide whether an inbound request header must be dropped before
     * forwarding. Combines the explicit {@see self::STRIPPED_REQUEST_HEADERS}
     * list with the {@see self::STRIPPED_REQUEST_HEADER_PREFIXES} families so no
     * client-supplied trust marker or forwarding header survives.
     *
     * @param string $name The inbound header name (any case).
     *
     * @return bool True when the header must not be forwarded.
     */
    private function isStrippedRequestHeader(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::STRIPPED_REQUEST_HEADERS, true)) {
            return true;
        }
        foreach (self::STRIPPED_REQUEST_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconstruct a request body to forward.
     *
     * Phase 1 is browse traffic (GET), so bodies are normally empty. When the
     * hub parsed a JSON body, re-encode it so POST proxies still work.
     *
     * @param Request $request The inbound request.
     *
     * @return string
     */
    private function reconstructBody(Request $request): string
    {
        if ($request->body === []) {
            return '';
        }

        try {
            return json_encode($request->body, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
    }

    /**
     * Build the browser-facing response from a relay reply payload.
     *
     * @param array<string, mixed> $reply Relay reply (status/headers/body_b64).
     *
     * @return Response
     */
    private function buildResponse(array $reply): Response
    {
        /** @var mixed $rawStatus */
        $rawStatus = $reply['status'] ?? null;
        $status = is_int($rawStatus) ? $rawStatus : 502;

        $response = (new Response())->status($status);

        if (isset($reply['headers']) && is_array($reply['headers'])) {
            foreach ($reply['headers'] as $name => $value) {
                if (!is_string($name) || !is_string($value)) {
                    continue;
                }
                if (in_array(strtolower($name), self::STRIPPED_RESPONSE_HEADERS, true)) {
                    continue;
                }
                $response->header($name, $value);
            }
        }

        /** @var mixed $rawBody */
        $rawBody = $reply['body_b64'] ?? null;
        $bodyB64 = is_string($rawBody) ? $rawBody : '';
        $decoded = $bodyB64 === '' ? '' : base64_decode($bodyB64, true);
        $response->body = is_string($decoded) ? $decoded : '';

        return $response;
    }
}
