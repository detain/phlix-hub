<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\ConnectionResponseSink;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\TokenBucket;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\ServerInfoDto;
use Workerman\Connection\TcpConnection;

use function base64_decode;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function preg_replace;
use function rawurldecode;
use function rtrim;
use function str_contains;
use function str_replace;
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
 * search, music library) PLUS playback reads (HLS/DASH playlists + segments, the
 * direct-play byte stream, and transcode-job status polling) PLUS the image reads
 * inline browse renders (chapter thumbnails, artwork/posters and user avatars —
 * S238) — with ONE
 * narrowly-scoped write exception: starting an on-demand transcode
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
     * hub user in two families: (1) JSON browse — libraries, media lists/detail
     * (which is also where SEARCH lives: `GET /api/v1/media/search`),
     * collections, music library (artists/albums/tracks); and (2)
     * playback reads — the HLS and DASH playlists + segments, the
     * direct-play byte stream, and transcode-job status polling. Anything
     * outside this set — notably admin, mutating, or scan endpoints — is
     * rejected with 403 `proxy.scope_denied` BEFORE forwarding, so a
     * compromised/confused client cannot use the hub as a deputy to reach
     * privileged server APIs. (The one permitted write — the transcode-START
     * POST — lives in {@see self::BROWSE_SCOPE_PATTERNS}, since its id is a
     * variable middle segment a prefix cannot express.)
     *
     * Keyed by HTTP method (upper-case); each value is a list of allowed path
     * prefixes matched against the resolved `/`-prefixed forward path by
     * {@see self::isWithinBrowseScope()} (exact match, or a `/`-delimited
     * sub-path — never a bare sibling like `/hlsX`). Path traversal can never
     * ride a widened prefix: {@see self::hasTraversalSegment()} rejects any
     * dot-segment, path-parameter (`..;`), control byte or encoded separator —
     * in the raw path AND in every successive percent-decoding of it — BEFORE
     * this allowlist is consulted. {@see self::SCOPE_DENY_PATTERNS} is consulted
     * before BOTH maps and carries the SWEPT set (S107) of real phlix-server
     * mutating ACTION routes that sit INSIDE an allowlisted read prefix and must
     * never be forwarded under any method. ⚠ Widening this allowlist re-opens
     * that sweep for the new prefix — see the enumeration rule on
     * {@see self::SCOPE_DENY_PATTERNS}.
     *
     * ### Every prefix MUST name a real upstream family (S107 follow-up)
     * A prefix that matches no phlix-server route is not harmless: it is relay
     * surface the hub will happily forward without delivering any feature, and
     * it is surface that a future server route lands INSIDE without ever passing
     * the S107 enumeration. SIX such entries shipped with the original
     * allowlist and have been removed — `/api/v1/images`, `/api/v1/opds`,
     * `/api/v1/genres`, `/api/v1/studios`, `/api/v1/people` (the first five, in
     * the S107 follow-up) and `/api/v1/search` (S165). None of them
     * exists on phlix-server: a boot of BOTH production registrars
     * (`Server\Core\Application::loadRoutes()`, invoked from that class's
     * constructor, and `Server\WebPortal\WebPortalRouter::registerRoutes()`,
     * likewise — the exact pair `Hub\RelayRequestDispatcher::dispatch()` consults
     * for a relayed request) produces 379 routes and not one of them lies under
     * any of the six. Four of the six have a REAL upstream twin at a DIFFERENT
     * path:
     *   - images/posters  → `GET /api/v1/artwork/{id}` (a pre-router fast path in
     *     `Server\Workerman\HttpHandler::serveArtwork()`, which the relay
     *     dispatcher does not even reach). ⚠ **S238 changed this disposition**:
     *     the user decided relayed browse must render images, so artwork IS in
     *     scope now — but as an ANCHORED entry in
     *     {@see self::BROWSE_SCOPE_PATTERNS}`['GET']`, never as the
     *     `/api/v1/artwork` PREFIX the note below still forbids;
     *   - OPDS catalog    → `GET /opds/v1.2[/…]`, mounted at the ROOT per the OPDS
     *     1.2 spec, never under `/api/v1` (note `Server\Http\Router::opds()` also
     *     registers it, but that registrar has ZERO production callers — only a
     *     unit test — so `Application::loadBookRoutes()` is the live one) —
     *     deliberately still OUT of scope;
     *   - genre facets    → `GET /api/v1/media/facets`, i.e. already inside the
     *     `/api/v1/media` prefix, so already IN scope and unaffected;
     *   - SEARCH          → `GET /api/v1/media/search` (`WebPortalRouter`, inside
     *     its auth group), plus the sibling `GET /api/v1/media/search/by-marker`.
     *     Both are already inside the `/api/v1/media` prefix, so search over the
     *     relay is unaffected by dropping `/api/v1/search` — the SPA's
     *     `SearchPage.vue` calls `/api/v1/media/search` and always has.
     * `/api/v1/studios` and `/api/v1/people` have no upstream twin at all.
     * ⚠ Do NOT re-add any of them, and do not "fix" the omission by allowlisting
     * the twin as a PREFIX: exposing `/api/v1/artwork` or `/opds/v1.2` over the
     * relay is a deliberate product decision that must run the S107 enumeration
     * first. `/opds/v1.2` is still OUT (it has its own Basic-auth story and no
     * product decision has been taken). Artwork was taken out of that bucket by
     * S238 — deliberately, by the user, with the enumeration run — and landed as
     * an ANCHORED single-segment pattern in {@see self::BROWSE_SCOPE_PATTERNS},
     * NOT as a prefix here. `/api/v1/images` is still dead surface either way:
     * the real path is `/api/v1/artwork/{id}`.
     * `ServerProxyControllerTest::s107FollowupDeadPrefixProvider()` and
     * `test_browse_scope_allowlist_matches_the_pinned_upstream_backed_set()` pin
     * both halves.
     *
     * ### HEAD is not exposed through the proxy (deliberate, and inert by design)
     * {@see \Phlix\Hub\Http\Router} has no `head()` registrar and
     * {@see \Phlix\Hub\Http\Router::dispatch()} 404s an unregistered method with
     * NO HEAD→GET fallback (unlike phlix-server's router), and
     * {@see \Phlix\Hub\Application} registers the proxy for GET/POST/PUT/PATCH/
     * DELETE only — so a HEAD can never reach this controller. The `HEAD` key
     * below therefore documents INTENT for the playback families that already
     * have SOME HEAD-aware machinery (HB-0.3). Be precise about what that
     * machinery is, because it is less than it sounds: the buffered bridge path
     * completes a HEAD on END without waiting for body frames,
     * {@see self::replyTimeoutForPath()} branches on `GET`/`HEAD`, and
     * {@see ConnectionResponseSink::forceCloseIfShort()} EXEMPTS a HEAD from the
     * short-body force-close safeguard. That is all of it. There is NO body
     * suppression anywhere in the hub, and the sink is only reached on the
     * STREAMING path — which {@see self::isStreamingPath()} restricts to GET —
     * so that exemption is doubly inert. The key grants nothing today. Do NOT
     * read it as working behaviour, and do not add a family to it expecting HEAD
     * to work. Making HEAD live needs three things landed TOGETHER: (1) a
     * `Router::head()` registrar + a HEAD proxy route, (2) body suppression
     * ADDED to the BUFFERED reply path
     * ({@see self::buildResponse()} would otherwise return a body on a HEAD,
     * desyncing keep-alive clients), and (3) an accepted cost for buffered
     * families — a HEAD to a JSON browse prefix makes the server produce the
     * WHOLE body (and, for music, mint an HMAC URL per row) only for the hub to
     * throw it away. That cost is why the S100 music prefix is GET-only.
     *
     * NOTE: only GET read families are reachable HERE. Every WRITE action
     * (HB-3.1 write-over-relay) is an ANCHORED per-action PCRE in
     * {@see self::BROWSE_SCOPE_PATTERNS} (POST/PUT/DELETE keys) — never a broad
     * prefix — so a future non-intended `/api/v1/media/{id}/…` write route
     * cannot ride a prefix into the relay. PATCH has NO entry in EITHER map: the
     * media server exposes no PATCH write route, so every PATCH fails closed with
     * 403 `proxy.scope_denied`. Denied mutating routes (incl. PATCH) are still
     * registered in {@see \Phlix\Hub\Application} so they route to this controller
     * and get a deliberate 403 via {@see self::isWithinBrowseScope()} rather than
     * a bare 404.
     *
     * @var array<string, list<string>>
     */
    private const BROWSE_SCOPE_ALLOWLIST = [
        'GET' => [
            // Browse / metadata (JSON). Each of these names a REAL phlix-server
            // family — see the "Every prefix MUST name a real upstream family"
            // section above before adding a fifth.
            '/api/v1/libraries',
            // Also carries SEARCH: the real endpoint is
            // `GET /api/v1/media/search` (+ `/search/by-marker`), a `/`-delimited
            // sub-path of this prefix. There is no `/api/v1/search` route on
            // phlix-server — that spelling was allowlisted here for a route that
            // never existed and was dropped in S165.
            '/api/v1/media',
            '/api/v1/collections',
            // S100: music library browse (Artist→Album→Track). One prefix covers
            // every music READ the SPA issues — `/artists`, `/artists/{mbid}`,
            // `/albums`, `/albums/{mbid}`, `/tracks`, `/tracks/{id}` (called
            // lazily at play time to mint a `stream_url`) and `/now-playing` —
            // because each is a `/`-delimited sub-path of `/api/v1/music`.
            // Without this entry every one of them was 403 `proxy.scope_denied`,
            // which the SPA renders as an EMPTY music library rather than an
            // error. Server-side these are all inside an `AuthMiddleware` group
            // on BOTH dispatch paths (`Application::loadMusicRoutes()` and
            // `WebPortalRouter::registerRoutes()`), so no unauthenticated
            // surface is exposed by allowing them here.
            //
            // GET ONLY, deliberately (S100 fix round 1: not mirrored under HEAD
            // either — see the HEAD block). The server also registers a
            // `POST /api/v1/music/scan` under this SAME prefix (a library-scan
            // trigger). Adding `/api/v1/music` to any write-method key — or
            // converting this to a broad write prefix — would expose that scan
            // trigger over the relay. Browse scope stays read-only; the scan POST
            // has no entry in EITHER map, and `/api/v1/music/scan` is
            // additionally pinned in {@see self::SCOPE_DENY_PATTERNS} so the READ
            // verbs cannot reach it either (it 404s server-side today only
            // because that route happens to be registered POST-only — the hub
            // must not depend on that accident).
            '/api/v1/music',
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
            // Browse / metadata (JSON). Mirror of the GET block's browse family —
            // all six upstream-less prefixes were removed from BOTH keys, since
            // a dead entry under an inert method key is dead twice over.
            '/api/v1/libraries',
            '/api/v1/media',
            '/api/v1/collections',
            // S100 fix round 1: `/api/v1/music` is deliberately NOT mirrored
            // here. HEAD cannot reach this controller at all (see the HEAD
            // section of this docblock), no Phlix client issues HEAD through the
            // proxy, and a HEAD to this BUFFERED family would cost the server a
            // whole music payload (plus one HMAC signed URL per track row) for a
            // body the hub then discards. Music browse is GET-only.
            //
            // Playback reads — mirror of the GET block. These are the families a
            // player WOULD probe with HEAD for size/range support before a ranged
            // GET, and their HEAD machinery exists (HB-0.3), but no HEAD is
            // routed to this controller today, so these grant nothing yet.
            '/hls',
            '/dash',
            '/media',
            '/api/v1/transcode',
        ],
        // Write methods (HB-3.1 write-over-relay) are deliberately NOT listed
        // here as broad prefixes. Each write action is an ANCHORED per-action
        // PCRE in {@see self::BROWSE_SCOPE_PATTERNS} (POST/PUT/DELETE keys), so a
        // broad `/api/v1/media` prefix can never expose an unintended future
        // `/api/v1/media/{id}/…` write route. PATCH is absent from both maps and
        // fails closed (no server PATCH write route exists).
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
     * HB-3.1 write-over-relay: every write action is an anchored entry HERE (not
     * a broad prefix), each aligned to a REAL phlix-server route:
     *   - POST  /api/v1/media/{id}/transcode   (transcode-START)
     *   - POST  /api/v1/media/{id}/watched|unwatched  (MediaUserDataController)
     *   - POST  /api/v1/media/{id}/favorite    (add favorite)
     *   - POST  /api/v1/playlists              (create playlist → collection)
     *   - PUT   /api/v1/media/{id}/rating|like|poster
     *   - DELETE /api/v1/media/{id}/favorite|rating
     * Because each id is a single `[^/]+` segment and each pattern is fully
     * anchored, NO other `/api/v1/media/{id}/…` verb+sub-path can match — the
     * admin `POST /api/v1/admin/media/merge`, `POST …/match/apply`, a future
     * `PUT /api/v1/media/{id}` update, and all scan endpoints stay 403
     * `proxy.scope_denied`. PATCH has no key here (no server PATCH write route),
     * so every PATCH fails closed. Path traversal is impossible here for the
     * same reason as the prefix allowlist: {@see self::hasTraversalSegment()}
     * rejects any dot-segment or encoded separator BEFORE this map is consulted.
     *
     * ### The GET key also carries the reads a PREFIX would over-admit (S238)
     * A prefix is the right shape only when every path under it is in scope. Three
     * reads fail that test and live here instead, each anchored to exactly the
     * server's own matcher: the chapter thumbnail (`{index}` in the middle),
     * `GET /api/v1/artwork/{itemId}` (single-segment id — a `/api/v1/artwork`
     * prefix would admit an arbitrary sub-tree) and
     * `GET /api/v1/users/{userId}/avatar` (id in the MIDDLE — a `/api/v1/users`
     * prefix would admit the entire user surface: `me/settings`,
     * `me/continue-watching`, `me/next-up`, `me/recently-watched`, `me/favorites`,
     * `me/history`). Being anchored GET-only entries they add NO new prefix, so
     * they do not re-open the {@see self::SCOPE_DENY_PATTERNS} sweep — the
     * enumeration rule is keyed on `BROWSE_SCOPE_ALLOWLIST['GET']` prefixes, and
     * nothing new lies under one. The S238 enumeration was still run: the only
     * write routes at or under either new path are
     * `POST`/`DELETE /api/v1/users/me/avatar`, and both are refused by the hub's
     * own method gate (no avatar entry under any write key), so there is no peer
     * route-table dependency to remove.
     *
     * ### S63: cast/DLNA transport control, same shape and same reasoning
     * `playback_control` (the flagged MCP write tool) needs four Chromecast and
     * three DLNA session endpoints. Every one of them landed here as an anchored
     * per-action PCRE, for the identical reason S238 refused a
     * `/api/v1/users` prefix: `/api/v1/cast` as a prefix would admit the session
     * START `POST /api/v1/cast/devices/{id}/cast`, and `/api/v1/dlna` as a
     * prefix sits beside the entire UPnP surface (`/dlna/description.xml`,
     * `/cds/control`, `/scpd/…`) that `dlnaStillDeniedProvider()` pins DENIED —
     * a prefix would be the one change that could make those two providers
     * contradict each other. Because they add NO prefix, the
     * {@see self::SCOPE_DENY_PATTERNS} enumeration is not re-opened: that sweep
     * is keyed on `BROWSE_SCOPE_ALLOWLIST['GET']` prefixes, and nothing new lies
     * under one. The enumeration was still RUN — the only write routes at or
     * under the new paths are the two session STARTs named in the POST block
     * below, and both are refused by this map's own omission rather than by any
     * peer route-table accident, so there is no dependency to remove.
     *
     * Keyed by HTTP method (upper-case); matched by
     * {@see self::isWithinBrowseScope()} AFTER the prefix allowlist.
     *
     * @var array<string, list<string>>
     */
    private const BROWSE_SCOPE_PATTERNS = [
        'GET' => [
            // P3B-S7: Audio track selection is handled by the URL-driven HLS selection mechanism.
            // The server emits audio-only HLS playlists as media_a{N}.m3u8 (flat files in job dir),
            // e.g. /hls/{job_id}/media_a0.m3u8. The master manifest references them via
            // URI="media_a0.m3u8" (relative URL resolved from master manifest directory).
            // Audio segments are seg-a{A}-NNNNN.ts (e.g. seg-a0-00001.ts).
            // The /hls prefix already covers these; explicit patterns below document actual structure.
            '#^/hls/[^/]+/media_a[0-9]+\.m3u8$#',
            '#^/hls/[^/]+/seg-a[0-9]+-[0-9]+\.ts$#',

            // Chapter thumbnails — served via the server API at GET /api/v1/media/{id}/chapters/{index}/thumbnail
            '#^/api/v1/media/[^/]+/chapters/\d+/thumbnail$#',

            // ---- S238: the two IMAGE fast paths -----------------------------
            // Posters/backdrops/logos: GET /api/v1/artwork/{itemId}?size={size}.
            // Served by `Server\Workerman\HttpHandler::serveArtwork()`, a
            // PRE-ROUTER fast path (it runs before `Router::dispatch()`), so it is
            // in NEITHER production route table. Until the phlix-server half of
            // S238 lands it therefore 404s over the relay rather than 403-ing
            // here; that is the peer's gate, not this one, and the hub entry is
            // correct on its own.
            //
            // ANCHORED, not a `/api/v1/artwork` PREFIX, deliberately: the server
            // matcher is `#^/api/v1/artwork/([^/]+)$#` — exactly ONE segment — so
            // a prefix would admit `/api/v1/artwork/a/b/c` and every future
            // sub-tree under it without re-running the S107 enumeration, while
            // this pattern admits exactly the shape the server serves and nothing
            // else. The `?size=` (and the signed-URL `exp`/`sig`) live in the
            // QUERY STRING, which never reaches this matcher: the scope gate and
            // `hasTraversalSegment()` both take the PATH only, and
            // `$request->queryString` is forwarded to the bridge byte-for-byte
            // (S108b) — so a signed artwork URL verifies server-side unchanged
            // (`serveArtwork()` rebuilds `'/api/v1/artwork/'.$id.'?size='.$size`
            // from `$wr->path()` + `$wr->get('size')`).
            '#^/api/v1/artwork/[^/]+$#',
            // Avatars: GET /api/v1/users/{userId}/avatar, served by
            // `HttpHandler::serveUserAvatar()` — the same pre-router fast path
            // class, matcher `#^/api/v1/users/([^/]+)/avatar$#`.
            //
            // ⚠ This is why it is an anchored PATTERN and NOT a prefix: the id is
            // a MIDDLE segment, and `/api/v1/users` as a prefix would admit the
            // whole `WebPortalRouter` user surface — `/api/v1/users/me/settings`,
            // `/continue-watching`, `/next-up`, `/recently-watched`, `/favorites`,
            // `/history` — none of which S238 asks for. Anchored on the literal
            // `avatar` tail, the ONLY path this admits is the avatar image itself.
            // The upload/delete twins (`POST`/`DELETE /api/v1/users/me/avatar`,
            // WebPortalRouter.php:372-373) stay refused by the hub's own method
            // gate: this entry is under the GET key and neither write key carries
            // an avatar entry, so no peer route-table accident is being relied on.
            '#^/api/v1/users/[^/]+/avatar$#',

            // Trickplay sprite sheet and timeline JSON served by TrickplayController.
            // Covers: /trickplay/{jobId}/sprite.jpg|png
            '#^/trickplay/[^/]+/sprite\.(jpg|png)$#',
            // Covers: /trickplay/{jobId}/timeline.json
            '#^/trickplay/[^/]+/timeline\.json$#',
            // Covers: /trickplay/{jobId}/thumb-{index}.jpg (BIF thumbnails)
            '#^/trickplay/[^/]+/thumb-[0-9]+\.(jpg|png)$#',
            // Covers: /trickplay/{jobId}/index.xml (BIF index)
            '#^/trickplay/[^/]+/index\.xml$#',

            // ---- S63: the cast/DLNA session READS ---------------------------
            // The two device catalogues and the two per-device status reads
            // that `playback_control` needs before it can control anything.
            // Server (`Server\Core\Application::loadChromecastRoutes()` /
            // `loadDlnaRendererRoutes()`):
            //   GET /api/v1/cast/devices               (static route)
            //   GET /api/v1/cast/devices/{id}/status
            //   GET /api/v1/dlna/renderers             (static route)
            //   GET /api/v1/dlna/renderers/{id}/status
            //
            // ⚠ ANCHORED, never `/api/v1/cast` or `/api/v1/dlna` as a PREFIX.
            // That is the S238 lesson applied: a `/api/v1/cast` prefix would
            // admit `POST /api/v1/cast/devices/{id}/cast` — session START, which
            // takes a caller-chosen media item — under any future read verb, and
            // a `/api/v1/dlna` prefix would sit next to the WHOLE UPnP surface
            // (`/dlna/description.xml`, `/cds/control`, `/scpd/…`) that
            // `dlnaStillDeniedProvider()` pins denied. Each pattern below mirrors
            // the server's own matcher byte-for-byte: `Server\Http\Router` turns
            // `{id}` into `(?P<id>[^/]+)` and wraps the whole thing in `#^…$#`,
            // and a static path is matched exactly out of its O(1) map.
            '#^/api/v1/cast/devices$#',
            '#^/api/v1/cast/devices/[^/]+/status$#',
            '#^/api/v1/dlna/renderers$#',
            '#^/api/v1/dlna/renderers/[^/]+/status$#',
        ],
        'POST' => [
            // Transcode-START ONLY. Anchored (`^…$`) + single-segment `[^/]+` id
            // so no other `/api/v1/media/{id}/*` POST (match/apply) and no
            // `/api/v1/admin/media/merge` can match.
            '#^/api/v1/media/[^/]+/transcode$#',
            // HB-3.1: watched/unwatched toggles — anchored to prevent any other
            // sub-path (e.g. match/apply) from matching via a broad prefix.
            // Server: POST /api/v1/media/{id}/watched|unwatched.
            '#^/api/v1/media/[^/]+/watched$#',
            '#^/api/v1/media/[^/]+/unwatched$#',
            // HB-3.1: add-favorite. Server: POST /api/v1/media/{id}/favorite.
            '#^/api/v1/media/[^/]+/favorite$#',
            // HB-3.1: create playlist. Server: POST /api/v1/playlists (alias that
            // creates a collection). Anchored EXACT — no sub-path — so admin/scan
            // and any future `/api/v1/playlists/…` route stays denied.
            '#^/api/v1/playlists$#',

            // ---- S63: cast/DLNA TRANSPORT CONTROL ---------------------------
            // The four Chromecast transport actions and the three DLNA ones,
            // each anchored to exactly one real server route with a
            // single-segment `[^/]+` device id:
            //   POST /api/v1/cast/devices/{id}/play|pause|stop|seek
            //        (`ChromecastController`, Application.php:3327-3330)
            //   POST /api/v1/dlna/renderers/{id}/pause|stop|seek
            //        (`Dlna\RendererListController`, Application.php:3267-3273)
            //
            // ⚠ TWO real server routes at these paths are DELIBERATELY ABSENT,
            // and their absence is the point of anchoring rather than prefixing:
            //   - `POST /api/v1/cast/devices/{id}/cast` (Application.php:3324) —
            //     session START. It takes the media item to cast, so it is a
            //     "begin playing X" action, not transport control.
            //   - `POST /api/v1/dlna/renderers/{id}/play` (Application.php:3264)
            //     — despite the name this is `playTo()`, also a session START,
            //     and its body carries a caller-supplied `uri` the renderer is
            //     told to fetch. Handing a model a field that makes a device on
            //     the operator's LAN dereference an arbitrary URL is not
            //     something this step is opening.
            // Neither is reachable: no prefix covers them and no pattern names
            // them, so both stay 403 `proxy.scope_denied`. Pinned by
            // `s63MustStayDeniedProvider()`.
            //
            // The Roku (`/api/v1/roku/devices/{id}/send|launch|key`) and AirPlay
            // (`/api/v1/airplay/devices/{id}/stream|pause|resume|stop`) surfaces
            // are likewise absent: they are different shapes (a keypress relay, a
            // stream START) and no tool wraps them.
            '#^/api/v1/cast/devices/[^/]+/play$#',
            '#^/api/v1/cast/devices/[^/]+/pause$#',
            '#^/api/v1/cast/devices/[^/]+/stop$#',
            '#^/api/v1/cast/devices/[^/]+/seek$#',
            '#^/api/v1/dlna/renderers/[^/]+/pause$#',
            '#^/api/v1/dlna/renderers/[^/]+/stop$#',
            '#^/api/v1/dlna/renderers/[^/]+/seek$#',
        ],
        // HB-3.1: PUT write actions, each anchored to a REAL server route with a
        // single-segment `[^/]+` id. Server (MediaUserDataController /
        // MediaPosterController): PUT /api/v1/media/{id}/rating|like|poster. Any
        // other `PUT /api/v1/media/{id}/…` (e.g. a future media-update route) and
        // every admin/scan path stay 403 `proxy.scope_denied`.
        'PUT' => [
            '#^/api/v1/media/[^/]+/rating$#',
            '#^/api/v1/media/[^/]+/like$#',
            '#^/api/v1/media/[^/]+/poster$#',
        ],
        // HB-3.1: DELETE write actions. Server (MediaUserDataController):
        // DELETE /api/v1/media/{id}/favorite (remove favorite) and
        // DELETE /api/v1/media/{id}/rating (clear rating). Anchored per-action so
        // no other DELETE sub-path is exposed.
        'DELETE' => [
            '#^/api/v1/media/[^/]+/favorite$#',
            '#^/api/v1/media/[^/]+/rating$#',
        ],
    ];

    /**
     * Hard denies, consulted by {@see self::isWithinBrowseScope()} BEFORE both
     * scope maps and for EVERY method: real phlix-server routes that sit INSIDE
     * an allowlisted read prefix but must never be forwarded over the relay.
     *
     * The prefix allowlist is a coarse instrument — allowing `/api/v1/music`
     * necessarily allows every sub-path under it, including sub-paths the server
     * registers for a WRITE verb. Today `POST /api/v1/music/scan`
     * (`WebPortalRouter`, an arbitrary-path `is_dir()` + blocking
     * `scanDirectory()` that is auth-gated but NOT admin-gated) is refused
     * because it appears in no scope map, and `GET`/`HEAD /api/v1/music/scan`
     * 404 on the server only because that route happens to be registered
     * POST-only. That is the SERVER's route table doing the work, not this gate:
     * one `$r->get('/api/v1/music/scan', …)` or one
     * `GET /api/v1/music/{action}` catch-all on the server would silently turn
     * the hub into a deputy for a scan trigger. Pinning it here makes the hub's
     * own gate authoritative.
     *
     * ### Matched against every SPELLING, not just the literal one (S100 fix r2)
     * A deny list compared only against the raw path is trivially evaded, and the
     * evasions all land back on the SAME accidental peer behaviour this pin
     * exists to stop relying on. `/api/v1/music/%73can`, `/%2573can`,
     * `//scan`, `/scan;x`, `/scan.` and `/scan%20` used to be forwarded, and
     * 404'd only because phlix-server (a) never `rawurldecode()`s
     * `Request::$path`, (b) never collapses duplicate `/`, and (c) never strips
     * path parameters. {@see self::isWithinBrowseScope()} therefore matches each
     * pattern against EVERY candidate form {@see self::decodeCandidates()}
     * produces (the raw path plus each successive percent-decoding) after
     * {@see self::normaliseForDenyMatch()} applies the normalisations a downstream
     * HTTP stack, router or filesystem plausibly applies: `;` treated as a segment
     * terminator, trailing `.`/space stripped from every segment, and duplicate
     * `/` collapsed. That is what makes the claim above TRUE rather than
     * aspirational.
     *
     * Scope of the guarantee, stated precisely — this is the exact bound, not an
     * aspiration. The pin is authoritative for these four transformations and for
     * **any composition of them, in any order**:
     *  1. percent-decoding, up to {@see self::MAX_TRAVERSAL_DECODE_PASSES} passes
     *     (a form still decoding at the cap is refused outright by
     *     {@see self::hasTraversalSegment()}, so nothing escapes past the bound);
     *  2. `;` as a segment terminator — which subsumes path-parameter stripping;
     *  3. trailing `.` and space trimmed from EVERY segment (not merely from the
     *     end of the path — see {@see self::normaliseForDenyMatch()}, where a
     *     tail-only trim let `scan./` and `scan.;x` through in r2);
     *  4. duplicate `/` collapsed.
     * It does NOT model a normaliser the hub has no reason to expect: IIS-style
     * overlong-UTF-8 (`%c0%ae`), `%uXXXX` or fullwidth-`．` folding, because
     * neither PHP, Workerman nor phlix-server performs any of them, so such a form
     * can never BECOME `scan` downstream either. `+`→space is likewise not
     * modelled: that is `urldecode()`, not `rawurldecode()`, and no component in
     * the chain applies it to a path (verified by grep against phlix-server's
     * `Router`/`Request`/`WebPortalRouter`/`Application`) — so `scan+` never
     * becomes a trimmable `scan `. If a peer ever adds one of these, this deny
     * matcher must be widened in the same commit. Encoded/literal separators
     * (`%2f`, `%5c`, `\`) need no handling here: they are rejected outright,
     * earlier, by {@see self::hasTraversalSegment()}.
     *
     * Each entry is a FULLY-ANCHORED PCRE covering the route and any sub-path
     * (`(/|$)`), matched case-INsensitively because the allowlist match is
     * case-sensitive and would otherwise let `/api/v1/music/SCAN` past while the
     * server's route table decides for us. Deliberately anchored on the FULL
     * path (never a bare `scan` segment): music artist/album ids are the artist
     * and album NAMES, so a bare segment rule would 403 a band called "Scan"
     * (`/api/v1/music/artists/Scan` — pinned allowed by a test).
     *
     * ### S107: the whole class, not one prefix (the enumeration rule)
     * S100 pinned `/api/v1/music/scan`. The SAME accident holds for every other
     * phlix-server write route that happens to live under a GET-allowlisted
     * browse prefix, and `GET /api/v1/libraries/{id}/scan` was the live proof: the
     * hub FORWARDS it (it is a `/`-sub-path of the `/api/v1/libraries` read
     * prefix) and it 404s only because `Application.php` registers scan/rescan
     * POST-only. Half-applying the principle leaves a gate that LOOKS
     * authoritative and is not, so the sweep below is derived mechanically, not
     * route-by-route. A path is pinned here when ALL of the following hold:
     *
     *  (a) it lies under a prefix in {@see self::BROWSE_SCOPE_ALLOWLIST}`['GET']`
     *      (so a GET to it is forwarded on the prefix's authority);
     *  (b) phlix-server registers it for a WRITE verb (POST/PUT/PATCH/DELETE);
     *  (c) phlix-server registers NO GET at that same path — so today's refusal of
     *      a READ verb comes from the server's route table, not from this gate;
     *  (d) it is a mutating ACTION endpoint (it changes server-side state that is
     *      not the calling user's own playback/user-data), so a hypothetical GET
     *      twin would plausibly TRIGGER it rather than describe it.
     *
     * Every write route under an allowlisted read prefix that is NOT listed below
     * fails one of those tests, and each disposition is deliberate:
     *  - **has a real GET twin at the same path** — `POST /api/v1/libraries`,
     *    `PUT|DELETE /api/v1/libraries/{id}`, `DELETE /api/v1/libraries/{id}/theme-media`,
     *    `POST /api/v1/collections`, `PUT|DELETE /api/v1/collections/{id}`,
     *    `DELETE /api/v1/media/{id}`, `POST /api/v1/media/{id}/markers`,
     *    `POST /api/v1/media/{id}/ratings`. Pinning these would 403 the browse READ
     *    that shares the path, which is a worse bug than the one being fixed. The
     *    hub's own METHOD gate already refuses the write: no write method has a
     *    prefix entry, and no {@see self::BROWSE_SCOPE_PATTERNS} entry matches — so
     *    there is no dependency on the peer's route table to remove.
     *  - **resource-shaped, no GET twin** — `PATCH /api/v1/media/{id}/metadata`,
     *    `DELETE /api/v1/media/{id}/markers/{markerId}`,
     *    `POST|DELETE /api/v1/collections/{id}/items/{mediaItemId}`. Fails (d): a
     *    GET at a resource path reads that resource, it does not mutate it, so the
     *    hypothetical twin is a browse read and denying it would pre-emptively
     *    break a legitimate future read. (`markers/{markerId}` additionally shares
     *    its shape with the REAL reads `markers/intro`, `markers/outro` and
     *    `markers/search`, which a segment-wildcard pin would 403 outright.)
     *  - **intentionally allowed writes** — the HB-3.1 user-data actions
     *    (`…/watched`, `…/unwatched`, `…/favorite`, `…/rating`, `…/like`,
     *    `…/poster`, `…/transcode`, `/api/v1/playlists`). These are ALREADY
     *    authoritative: each is an anchored entry in
     *    {@see self::BROWSE_SCOPE_PATTERNS} for exactly the verb the server
     *    registers, so the hub decides them on its own. A deny here would be
     *    consulted BEFORE the allow maps and would break the feature.
     *
     * ⚠ Adding a prefix to {@see self::BROWSE_SCOPE_ALLOWLIST} re-opens this
     * question for that prefix. Re-run the enumeration against phlix-server's
     * route table in the same commit.
     *
     * ### S107: two matcher extensions, both load-bearing for a `{id}` segment
     * S100's patterns had no variable segment, which hid two under-denies that
     * appear the moment one does:
     *
     *  1. **The id segment is `[^/]*`, not `[^/]+`.** `normaliseForDenyMatch()`
     *     collapses duplicate `/`, so `/api/v1/libraries//scan` normalises to
     *     `/api/v1/libraries/scan` and a `[^/]+` id can no longer match it.
     *     `[^/]*` matches the empty id in the RAW form while still NOT matching
     *     the collapsed `/api/v1/libraries/scan` (that needs a `/` after the id
     *     group, and the collapsed form has none) — so a library whose id is
     *     literally `scan` stays browsable. Both directions are pinned by tests.
     *  2. **Each pattern is matched against the raw candidate AND its normalised
     *     form** (see {@see self::isWithinBrowseScope()}). Normalisation can
     *     DESTROY a match as well as create one: `/api/v1/libraries/%20/scan`
     *     decodes to `/api/v1/libraries/ /scan`, whose id segment trims to empty
     *     and then collapses away, leaving `/api/v1/libraries/scan` — which is
     *     not the scan route. The literal spelling IS the scan route on any stack
     *     that does not trim, so the raw form must be matched too. Matching more
     *     forms can only ever over-deny, and no legitimate read can match an
     *     anchored action pattern in its raw spelling.
     *
     * @var list<non-empty-string>
     */
    private const SCOPE_DENY_PATTERNS = [
        // --- under the `/api/v1/music` read prefix (S100) ------------------
        // POST /api/v1/music/scan — WebPortalRouter.php:389.
        '#^/api/v1/music/scan(/|$)#i',

        // --- under the `/api/v1/libraries` read prefix (S107) --------------
        // POST /api/v1/libraries/{id}/scan   — Application.php:1698
        // POST /api/v1/libraries/{id}/rescan — Application.php:1699
        // The `(/|$)` anchor is what keeps the two REAL reads
        // `/scan-status` (Application.php:1653) and `/scan-history` (:1654)
        // working — `scan-status` has no `/` or end-of-string after `scan`.
        '#^/api/v1/libraries/[^/]*/(re)?scan(/|$)#i',
        // POST /api/v1/libraries/{id}/match-metadata   — Application.php:1712
        '#^/api/v1/libraries/[^/]*/match-metadata(/|$)#i',
        // POST /api/v1/libraries/{id}/refresh-metadata — Application.php:1717
        '#^/api/v1/libraries/[^/]*/refresh-metadata(/|$)#i',
        // POST /api/v1/libraries/{id}/prune          — Application.php:1727
        '#^/api/v1/libraries/[^/]*/prune(/|$)#i',
        // POST /api/v1/libraries/{id}/clear-metadata — Application.php:1728
        '#^/api/v1/libraries/[^/]*/clear-metadata(/|$)#i',
        // POST /api/v1/libraries/{id}/clear-artwork  — Application.php:1729
        '#^/api/v1/libraries/[^/]*/clear-artwork(/|$)#i',
        // POST /api/v1/libraries/{id}/delete-all     — Application.php:1730
        // (DESTRUCTIVE: removes every item in the library.)
        '#^/api/v1/libraries/[^/]*/delete-all(/|$)#i',
        // POST /api/v1/libraries/{id}/theme-media/scan — Application.php:1734.
        // Needs its own entry: the `(re)?scan` pattern above cannot reach it,
        // because `[^/]*` never crosses a `/`. GET /theme-media (:1733) and
        // DELETE /theme-media (:1735) share a path, so only the `/scan` ACTION
        // is pinned — see the disposition list above.
        '#^/api/v1/libraries/[^/]*/theme-media/scan(/|$)#i',

        // --- under the `/api/v1/collections` read prefix (S107) ------------
        // POST /api/v1/collections/{id}/bulk-add — Application.php:1783
        '#^/api/v1/collections/[^/]*/bulk-add(/|$)#i',
        // POST /api/v1/collections/{id}/refresh  — Application.php:1784
        // (re-evaluates a smart collection's membership).
        '#^/api/v1/collections/[^/]*/refresh(/|$)#i',

        // --- under the `/api/v1/media` read prefix (S107) ------------------
        // POST /api/v1/media/{id}/match/apply — Application.php:587. The sibling
        // READ `/match/search` (:586) is a different literal segment and stays
        // allowed.
        '#^/api/v1/media/[^/]*/match/apply(/|$)#i',
        // POST /api/v1/media/{id}/subtitles/download — Application.php:660: it
        // FETCHES a subtitle from a remote provider and attaches it as an
        // external track, i.e. a server-side mutation, not a read. The sibling
        // reads `/subtitles`, `/subtitles/search` and `/subtitles/{index}` stay
        // allowed (pinned by tests); `{index}` is an integer track index, so no
        // real read addresses the literal `download` segment.
        '#^/api/v1/media/[^/]*/subtitles/download(/|$)#i',
    ];

    /**
     * Maximum percent-decoding passes {@see self::hasTraversalSegment()} applies
     * while normalising a forward path.
     *
     * A legitimate path reaches a fixed point in ONE pass (`Pink%20Floyd` →
     * `Pink Floyd`); two or more passes only ever appear in a double-encoding
     * attack (`%252e%252e%252f` → `%2e%2e%2f` → `../`). The cap bounds the loop
     * so a pathologically nested path cannot spin the worker; a path still
     * changing at the cap is rejected outright.
     *
     * ⚠ Do NOT lower this value and do NOT "simplify" the reject-on-unstable
     * branch in {@see self::hasTraversalSegment()} — that branch is the SOLE
     * defence against an encoding nested deeper than the cap, and lowering the
     * cap starts refusing real names. Both directions are pinned by tests
     * (S100 fix r2, MED-2): `traversalPathProvider`'s
     * "encoding nested past the decode cap" row fails if the branch fails OPEN,
     * and `legitimateMusicReadProvider`'s `%2525252520` row (an artist whose
     * name needs exactly 5 decodings) fails if the cap is lowered.
     */
    private const MAX_TRAVERSAL_DECODE_PASSES = 5;

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
     * @param ServerInfoHandler    $serverInfo    Resolves server ownership + relay status.
     * @param RelayProxyBridge     $bridge        Cross-process bridge to the relay worker.
     * @param StructuredLogger     $logger        Relay logger.
     * @param RelaySessionManager  $sessionManager Tracks per-user bandwidth quotas.
     * @param RateLimiterInterface $rateLimiter   Bounded, TTL-windowed rate limiter keyed by client IP.
     * @param int                  $timeoutSeconds Default seconds to await the server
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
        private readonly RelaySessionManager $sessionManager,
        private readonly RateLimiterInterface $rateLimiter,
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
            // HB-4.6b LANDMINE: unauthenticated floods take this cheap 401 —
            // NOT a limiter write. The proxy limiter is deliberately placed
            // AFTER this auth gate so an unauthed request never mints a bucket.
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        // HB-4.6b: rate limit AFTER the auth gate, keyed by the proven user id
        // (the `rate_limiter.proxy` profile is generous — 600/60s — so a normal
        // multi-segment HLS playback burst across variants never trips it).
        // We key by userId (never IP) because the 401 gate above guarantees an
        // unauthenticated request never reaches here — it takes the cheap 401
        // instead of minting a per-IP bucket. The hit runs synchronously here,
        // before any streaming producer starts, so a trip throws
        // RateLimitException into the OUTER Application catch, not the
        // streaming-producer catch.
        $state = $this->rateLimiter->hit('proxy:' . $userId);
        if ($state->limited) {
            throw new RateLimitException(
                resetAt: $state->resetAt,
                remaining: 0,
            );
        }

        $serverId = $params['id'] ?? '';
        $owner = $this->serverInfo->getOwnerAndStatus($serverId);
        if ($owner === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code' => 'server.not_found',
            ]);
        }

        if ($owner['userId'] !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'server.not_owned',
            ]);
        }

        if (!$owner['relayActive']) {
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
            if ($owner['status'] === ServerInfoDto::STATUS_ONLINE) {
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

        // HB-3.4: check per-user bandwidth quota before forwarding.
        // Best-effort check: rejects users who are already over their monthly
        // upload cap. A refined per-request estimate may be added later.
        $quotaCheck = $this->sessionManager->checkUserQuota($userId, 0);
        if (!$quotaCheck['allowed']) {
            return (new Response())->status(503)->json([
                'error' => [
                    'code' => 'quota.exceeded',
                    'message' => $quotaCheck['reason'] ?? 'Bandwidth quota exceeded.',
                ],
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
            // HB-3.4 G3: per-user concurrent-stream cap. Enforced BEFORE the
            // stream is admitted so an over-limit request never occupies a slot.
            // The live count is in-memory per worker (RelaySessionManager owns
            // it); the operator-configured maximum (0 = unlimited → skip) was
            // already read as part of the checkUserQuota() row read above, so we
            // consume it from that ONE row read instead of issuing a second
            // identical SELECT on every HLS/DASH segment (HB-3.4 hot-path fix).
            $maxStreams = $quotaCheck['maxConcurrentStreams'];
            if ($maxStreams > 0 && $this->sessionManager->activeUserStreams($userId) >= $maxStreams) {
                $this->logger->info('Relay proxy: rejected — per-user concurrent-stream cap reached', [
                    'server_id' => $serverId,
                    'user_id' => $userId,
                    'active_streams' => $this->sessionManager->activeUserStreams($userId),
                    'max_streams' => $maxStreams,
                ]);

                return (new Response())->status(503)->json([
                    'error' => [
                        'code' => 'stream.limit',
                        'message' => 'You have reached your maximum number of concurrent streams.',
                    ],
                ]);
            }

            return $this->buildStreamingResponse(
                $userId,
                $serverId,
                $request->method,
                $path,
                $request->queryString,
                $headers,
                $body,
                $timeout,
            );
        }

        // HB-3.4: record outbound bytes before sending the request.
        // Headers are ~500-2000 bytes; using a conservative estimate.
        $this->sessionManager->recordUserBandwidth($userId, 0, strlen($body) + 1024);

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

        // HB-3.4: record inbound bytes after receiving the response.
        // At this point $reply is array (null case returned early at line 519).
        // Use array_key_exists to satisfy PHPStan without triggering
        // "already narrowed" while also verifying the structure.
        if (array_key_exists('body', $reply) && is_string($reply['body'])) {
            $this->sessionManager->recordUserBandwidth($userId, strlen($reply['body']) + 512, 0);
        }

        return $this->buildResponse($reply);
    }

    /**
     * Whether a method + resolved path should be streamed to the browser
     * fragment-by-fragment rather than buffered whole.
     *
     * Only **GET** under a {@see self::STREAMING_BODY_PREFIXES} family qualifies.
     * HEAD is deliberately EXCLUDED so that IF it ever becomes routable it flows
     * through the buffered {@see RelayProxyBridge::request()} path (HB-0.3): a
     * server `withFile()` HEAD emits a head frame + zero-body END with no body
     * frames, and the buffered path completes promptly on that END — streaming a
     * body-less response would add no value. (No HEAD reaches this controller
     * today — {@see self::BROWSE_SCOPE_ALLOWLIST} explains why.) These paths
     * already passed the browse-scope
     * gate (so a mutating method never reaches here). The match uses the same
     * exact-or-`/`-subpath rule as the allowlist — never a bare sibling like
     * `/hlsX`.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return bool True when the response should be streamed.
     */
    private function isStreamingPath(string $method, string $path): bool
    {
        $method = strtoupper($method);
        if ($method !== 'GET') {
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
     * ### Per-user bandwidth throttle (S43, updates.md #50)
     * The owning user's durable throttle (`throttle_bps`; `0` = Unlimited) is read
     * ONCE here and turned into a {@see TokenBucket} the {@see ConnectionResponseSink}
     * paces the response body against, mirroring the WS relay path (S42). Unlimited
     * passes a null bucket so the sink streams unthrottled. Pacing is a coroutine
     * yield inside the sink (never a blocking sleep) and rides the existing bounded
     * back-pressure, so it adds no unbounded buffering.
     *
     * ### Bandwidth accounting + concurrency (HB-3.4 G1/G3)
     * The user has already cleared the concurrent-stream cap in {@see self::proxy()};
     * here we OCCUPY a stream slot for them ({@see RelaySessionManager::beginUserStream()})
     * and guarantee it is RELEASED exactly once on every exit path. The producer's
     * `finally` is the release point because it is the true "stream is over"
     * boundary: {@see RelayProxyBridge::stream()} always either returns (normal
     * completion, browser-gone mid-body, timeout) or throws (a pre-head error it
     * re-raises), and `finally` runs in every one of those cases — so the slot can
     * never leak on error/disconnect. The one-shot `$release` guard makes the
     * decrement idempotent. In the same `finally` we meter the AUTHORITATIVE bytes
     * actually delivered to the browser — read from the sink's own on-the-wire
     * counter ({@see ConnectionResponseSink::bytesStreamed()}), NOT a header
     * estimate — as the user's monthly DOWNLOAD, plus the real request body as
     * UPLOAD.
     *
     * @param string                $userId   Authenticated hub user id (for accounting).
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
        string $userId,
        string $serverId,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        float $timeout,
    ): Response {
        $sessionManager = $this->sessionManager;

        // Per-user bandwidth throttle (S43, updates.md #50): resolve the owning
        // user's DURABLE cap (bits/sec; `0` = Unlimited) ONCE at stream admission
        // — never per fragment — and build the token bucket the response-body sink
        // paces against. Unlimited yields a null bucket so the sink takes its
        // unthrottled fast path. This is the SAME cap the WS relay path enforces.
        $throttleBucket = TokenBucket::fromThrottleBps(
            $sessionManager->getUserThrottleBps($userId),
        );

        // HB-3.4 G3: occupy a concurrent-stream slot for this admitted stream.
        $sessionManager->beginUserStream($userId);

        // One-shot slot release. Idempotent so it is safe to call from the
        // producer's finally regardless of how the stream ended.
        $released = false;
        $release = static function () use (&$released, $sessionManager, $userId): void {
            if ($released) {
                return;
            }
            $released = true;
            $sessionManager->endUserStream($userId);
        };

        $bridge = $this->bridge;
        $requestBodyBytes = strlen($body);
        $producer = static function (TcpConnection $connection) use (
            $bridge,
            $serverId,
            $method,
            $path,
            $query,
            $headers,
            $body,
            $timeout,
            $sessionManager,
            $userId,
            $requestBodyBytes,
            $release,
            $throttleBucket,
        ): void {
            $sink = new ConnectionResponseSink($connection, $method, $throttleBucket);
            try {
                $bridge->stream(
                    $serverId,
                    $method,
                    $path,
                    $query,
                    $headers,
                    $body,
                    $timeout,
                    $sink,
                );
            } finally {
                // HB-3.4 G1: meter the real bytes streamed (authoritative
                // data-plane total, not a header estimate) as the user's
                // DOWNLOAD, and the real request body as their UPLOAD.
                $bytesStreamed = $sink->bytesStreamed();
                if ($bytesStreamed > 0 || $requestBodyBytes > 0) {
                    $sessionManager->recordUserBandwidth($userId, $bytesStreamed, $requestBodyBytes);
                }
                // HB-3.4 G3: free the concurrent-stream slot on EVERY exit
                // (completion, browser-gone, error) — no leak.
                $release();
            }
        };

        return (new Response())->status(200)->stream($producer);
    }

    /**
     * Detect any path-traversal / dot-segment smuggling in the raw forward
     * path, in literal, percent-encoded, DOUBLE-percent-encoded,
     * path-parameter (`..;`) or NUL/control-byte form.
     *
     * The hub does not normalise paths before forwarding, so a single dot
     * segment (`.` / `..`) — or an encoded variant such as `%2e`, `%2E`,
     * `..%2f`, `%2f`, or `%252e%252e%252f` — must be rejected outright.
     *
     * ### Why decode UNTIL STABLE, not once (S100 fix round 1, MED-3)
     * The previous guard tested the raw path plus exactly ONE `rawurldecode()`,
     * so `%252e%252e%252fadmin` (whose FIRST decoding is the `%2e%2e%2f` the
     * guard does catch) sailed through. Nothing but phlix-server's own habits
     * stopped it there: `Request::$path` is never decoded and every route is an
     * exact static-map key or an anchored `#^…$#` with `[^/]+` params, so it
     * 404s — but one future `rawurldecode()` or one multi-segment route on the
     * server turns that into a live traversal out of browse scope. A security
     * gate must not depend on a peer repo's incidental behaviour, so we now
     * decode to a FIXED POINT (bounded by
     * {@see self::MAX_TRAVERSAL_DECODE_PASSES}) and run every check over the raw
     * path AND each intermediate decoding.
     *
     * Decode-until-stable is chosen over the simpler "reject any literal `%`"
     * because percent-encoding is LEGITIMATE inside the allowlisted prefixes:
     * `/api/v1/music/artists/{mbid}` and `/albums/{mbid}` are keyed by the
     * artist/album NAME (`ApiClient` sends `encodeURIComponent(name)`), so
     * `GET /api/v1/music/artists/Pink%20Floyd` is a well-formed browse read that
     * this hub must forward and a blanket `%` rejection would 403. (An encoded
     * SEPARATOR — `%2f`/`%5c` — has no legitimate use in any allowlisted family
     * and is still rejected outright, so a name containing `/` remains
     * unreachable, exactly as before.)
     *
     * ⚠ **"The hub forwards it" is NOT "it works" — do not read that into the
     * paragraph above.** Measured by execution on 2026-08-05 (S108): phlix-server
     * never percent-decodes a route parameter (`Router::dispatch()` fills
     * `$params` straight out of `preg_match()`; `Request::$path` is Workerman's
     * `path()`, i.e. `parse_url()`, which does not decode), so
     * `MusicController::getArtist()` receives the literal string `Pink%20Floyd`
     * and `MusicLibraryService::findArtistByName()` runs `WHERE a.name = ?`
     * against it. On the live production database that matches **0** rows while
     * `'Pink Floyd'` matches 1, and the endpoint 404s. The bound documented in
     * {@see self::hasTraversalSegment()} as "names containing `/`" is therefore
     * the SMALL half of the real defect: on production `music_artists`, 296 of
     * 4,679 names contain `/` but **4,006 contain a space** — so the artist-detail
     * endpoint is dead for ~86% of the library, direct AND over the relay, and
     * that is a phlix-server defect this gate neither causes nor can fix.
     *
     * Checks applied to every candidate form:
     *  - encoded separators `%2f` / `%5c` (case-insensitive) and literal `\`;
     *  - NUL and other control bytes (`%00../` truncates a path in any
     *    C-string consumer, and no legitimate REST path contains one);
     *  - `.` / `..` segments, splitting on `;` as well as `/` so the
     *    path-parameter trick `..;/admin` cannot hide a dot-segment inside one
     *    `/`-delimited segment.
     *
     * ### Two KNOWN, DELIBERATE limitations (S100 fix r2, LOW-3 + LOW-4)
     * Both are documented at their check sites below and pinned by
     * `ServerProxyControllerTest::knownUnreachableMusicNameProvider`. Neither is
     * to be "fixed" by relaxing the check — each relaxation reopens traversal:
     *  1. An artist/album/track whose NAME contains `/` or `\` is unreachable
     *     over the relay (`AC/DC` → `AC%2FDC` → 403). Not a hub-only bound:
     *     phlix-server does not decode route params either, so the name is
     *     unreachable direct as well. Tracked as its own step; the correct fix is
     *     upstream (key music by id with the name as a QUERY parameter), never a
     *     weaker separator check here. ⚠ And it must be the query parameter, not
     *     a `rawurldecode()` of the path before routing: the server's route
     *     pattern is `#^/api/v1/music/artists/(?P<mbid>[^/]+)$#`, so a decoded
     *     `AC/DC` stops matching the route entirely (measured: 404 with the
     *     handler never entered). Only decoding the EXTRACTED param, or moving
     *     the name off the path, can work — and only the latter also gets past
     *     this guard. The hub-side half of that design is pinned by
     *     `ServerProxyControllerTest::musicNameInQueryStringProvider`.
     *  2. An artist/album named exactly `.` or `..` is unreachable, because a
     *     dot is unreserved so `encodeURIComponent()` leaves it literal and the
     *     segment arrives as a real dot-segment. Names that merely CONTAIN dots
     *     are unaffected — the test is a strict whole-segment `=== '.'`/`=== '..'`,
     *     never a `str_contains`, so `...`, `S.C.I.E.N.C.E.`,
     *     `... And Justice For All` and `Vol%2E%201` all forward (pinned).
     *
     * @param string $path The raw `/`-prefixed forward path (un-normalised).
     *
     * @return bool True when the path contains a traversal/dot-segment.
     */
    private function hasTraversalSegment(string $path): bool
    {
        // Every form a downstream consumer could see: the raw path plus each
        // successive decoding, up to the fixed point.
        $candidates = $this->decodeCandidates($path);
        $current = $candidates[count($candidates) - 1];
        if (rawurldecode($current) !== $current) {
            // Still changing at the cap: pathologically nested encoding that no
            // legitimate browse path uses. Fail closed. ⚠ This branch is the ONLY
            // thing standing between the relay and an encoding nested deeper than
            // MAX_TRAVERSAL_DECODE_PASSES — see that constant's docblock for the
            // two tests that pin it in both directions.
            return true;
        }

        foreach ($candidates as $candidate) {
            // An encoded separator smuggles an extra path segment past the split
            // below; a literal back-slash is a separator on some downstream
            // stacks. Neither is legitimate in any allowlisted family.
            //
            // KNOWN LIMITATION (LOW-3), accepted deliberately: music artist and
            // album ids are NAMES, so a band whose name contains `/` or `\`
            // (`AC/DC`, `N/A`, `+/-`, `AC\DC`) arrives as `AC%2FDC` and is
            // refused here — the SPA renders that 403 as an empty page. The name
            // is equally unreachable DIRECT (phlix-server does not decode route
            // params either), so this is not a relay-only regression, and it must
            // NOT be fixed by narrowing this check: an encoded separator is how
            // every double-decode traversal in the attack matrix travels. The fix
            // belongs upstream — key music by id, name as a query parameter.
            $lower = strtolower($candidate);
            if (str_contains($lower, '%2f') || str_contains($lower, '%5c')) {
                return true;
            }
            if (str_contains($candidate, '\\')) {
                return true;
            }

            // NUL / control bytes (raw, or arriving via `%00`, `%0a`, …).
            if (preg_match('/[\x00-\x1f\x7f]/', $candidate) === 1) {
                return true;
            }

            // Treat `;` as a segment terminator too, so `..;` is scanned as the
            // `..` it becomes on any stack that strips path parameters.
            //
            // BY DESIGN (LOW-4): the comparison is a strict WHOLE-segment
            // `=== '.'`/`=== '..'`, never a `str_contains`. Consequence in both
            // directions, and both are pinned by tests: an artist/album named
            // exactly `.` or `..` is unreachable (a dot is unreserved, so
            // `encodeURIComponent()` leaves it literal and it arrives as a real
            // dot-segment), while a name that merely CONTAINS dots — `...`,
            // `S.C.I.E.N.C.E.`, `... And Justice For All`, `Vol%2E%201` — passes
            // untouched. Do NOT widen this to a substring test to "harden" it;
            // that 403s a large slice of real album titles.
            foreach (explode('/', str_replace(';', '/', $candidate)) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build every form of a forward path a downstream consumer could see: the
     * raw path plus each successive percent-decoding, up to the fixed point or
     * {@see self::MAX_TRAVERSAL_DECODE_PASSES}, whichever comes first.
     *
     * Shared by BOTH gates on purpose (S100 fix r2, MED-1). Before this was
     * extracted, {@see self::hasTraversalSegment()} decoded to a fixed point
     * while {@see self::SCOPE_DENY_PATTERNS} was matched against the raw path
     * only — so one commit shipped two different ideas of what "the path" is, and
     * `/api/v1/music/%73can` walked straight through the deny pin. Every path
     * gate in this controller must reason over the SAME candidate set.
     *
     * The caller decides what an unstable tail means: a still-decoding last
     * candidate is a rejection for the traversal guard (see that method), and the
     * deny matcher needs no such rule because it is matching for a DENY, so
     * examining fewer forms can only ever under-deny, never over-deny.
     *
     * @param string $path The raw `/`-prefixed forward path (un-normalised).
     *
     * @return non-empty-list<string> Raw path first, then each decoding in order.
     */
    private function decodeCandidates(string $path): array
    {
        $candidates = [$path];
        $current = $path;
        for ($pass = 0; $pass < self::MAX_TRAVERSAL_DECODE_PASSES; $pass++) {
            $decoded = rawurldecode($current);
            if ($decoded === $current) {
                break;
            }
            $candidates[] = $decoded;
            $current = $decoded;
        }

        return $candidates;
    }

    /**
     * Normalise one candidate form before matching it against
     * {@see self::SCOPE_DENY_PATTERNS}.
     *
     * Applies only normalisations a real downstream consumer plausibly applies,
     * each of which was a live evasion of the raw-path-only deny check
     * (S100 fix r2, MED-1):
     *  - `;` → `/`: some routers/containers treat a semicolon as a segment
     *    terminator, so `/api/v1/music/scan;x` addresses the scan route. Split
     *    rather than truncated on purpose: splitting subsumes path-parameter
     *    STRIPPING for an anchored whole-segment pattern (stripping truncates a
     *    segment exactly where splitting inserts a `/`), and it additionally
     *    catches `/api/v1/music;scan` and `/api/v1/music/;scan`, which
     *    truncation would not;
     *  - trailing `.` and space stripped from EVERY segment: `scan.` / `scan%20`
     *    (the decoded candidate ends in a space) resolve to `scan` on stacks that
     *    trim them;
     *  - duplicate `/` collapsed: `/api/v1/music//scan` and `///scan` address it
     *    too (`proxy()` normalises only LEADING slashes).
     *
     * ### Per SEGMENT, not on the path tail (S100 fix r3)
     * The trim runs on each segment because a single tail `rtrim()` is evaded by
     * a COMPOSITION of two rules this method already claims: as soon as anything
     * follows the `scan` segment its trailing dot/space is no longer trailing on
     * the path. `/api/v1/music/scan.` was denied while `/api/v1/music/scan./`,
     * `scan.;x`, `scan /` and `scan%2e/status` were forwarded — 210 of the 1 464
     * suffixes of length ≤3 over `{. ␠ ; / x %20 %2e %2E %3b %25 status}`.
     * Ordering matters and is load-bearing: `;`→`/` must run BEFORE the trim (so
     * `scan.;x` exposes `scan.` as its own segment) and the `/+` collapse must run
     * AFTER it (trimming a segment down to nothing manufactures a `//` of its own).
     *
     * The result is idempotent and closed under composition of the four modelled
     * rules in any order: the output contains no `;`, no `//`, and no segment
     * ending in `.` or a space, and normalising it again is a no-op. Combined with
     * matching EVERY candidate from {@see self::decodeCandidates()}, that also
     * covers a downstream that interleaves trimming with decoding, since none of
     * these rules can complete a `%XX` escape and so none can unlock a decoding
     * pass the candidate chain has not already taken.
     *
     * ⚠ This output is a SUPPLEMENT to the raw candidate, never a replacement for
     * it (S107). Normalisation is lossy by construction, and for a deny pattern
     * with a variable `{id}` segment it can DESTROY a match: the id of
     * `/api/v1/libraries/%20/scan` trims to empty and is then collapsed away,
     * yielding `/api/v1/libraries/scan`, which is the library-SHOW route rather
     * than the scan action. {@see self::isWithinBrowseScope()} therefore matches
     * the deny patterns against the raw candidate as well as this form.
     *
     * Deliberately NOT a general-purpose path canonicaliser: it never resolves
     * `.`/`..` segments and never touches separators other than the two above,
     * because anything carrying a dot-segment or an encoded/literal separator has
     * already been refused by {@see self::hasTraversalSegment()} before this runs.
     * It is also used ONLY for deny matching — never for the allowlist, and never
     * for the path actually forwarded, which stays byte-for-byte as sent, so
     * `/api/v1/music/albums/Etc.` still reaches the server with its dot intact.
     *
     * @param string $candidate One form from {@see self::decodeCandidates()}.
     *
     * @return string The form to match deny patterns against.
     */
    private function normaliseForDenyMatch(string $candidate): string
    {
        $segments = explode('/', str_replace(';', '/', $candidate));
        foreach ($segments as $index => $segment) {
            $segments[$index] = rtrim($segment, '. ');
        }

        $normalised = implode('/', $segments);

        return preg_replace('#/+#', '/', $normalised) ?? $normalised;
    }

    /**
     * Determine whether a method + resolved path is within the browse-scope the
     * proxy is permitted to forward.
     *
     * Three layers, checked in order: the method-independent hard denies
     * {@see self::SCOPE_DENY_PATTERNS} (routes that sit inside an allowlisted
     * read prefix but must never be forwarded), then the prefix
     * {@see self::BROWSE_SCOPE_ALLOWLIST} (GET read families) and the
     * anchored-PCRE {@see self::BROWSE_SCOPE_PATTERNS} (every HB-3.1 write action
     * — favorite/rating/like/watched/unwatched/poster/playlist/transcode — plus a
     * few non-prefix reads). A request is in scope when it matches NO deny
     * pattern AND matches EITHER allow layer for its method; every other
     * method/path (incl. all PATCH) returns false and fails closed.
     *
     * The deny layer is matched against every candidate form of the path
     * ({@see self::decodeCandidates()}), in BOTH its raw spelling and the form
     * {@see self::normaliseForDenyMatch()} produces — see
     * {@see self::SCOPE_DENY_PATTERNS} for why that is what makes the pin
     * authoritative instead of dependent on phlix-server's route table, and why
     * neither form alone is sufficient once a pattern carries a variable `{id}`
     * segment. The ALLOW layers stay deliberately literal: they are matched
     * against the path that will actually be forwarded, and a decoded form must
     * never be able to WIDEN scope — only to deny.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return bool True when the request may be forwarded.
     */
    private function isWithinBrowseScope(string $method, string $path): bool
    {
        $method = strtoupper($method);

        // Hard denies win over every allow entry, for every method: a read verb
        // must not reach a scan trigger just because it shares a browse prefix.
        // Matched against EVERY decoding of the path, each in BOTH its raw and
        // its normalised spelling — the raw spelling alone left `%73can`,
        // `//scan`, `scan;x` and `scan.` forwarded (S100 r2), and the normalised
        // spelling ALONE lets `/api/v1/libraries/%20/scan` through, because the
        // per-segment trim empties the id segment and the `/+` collapse then
        // deletes it, leaving a path that is no longer the scan route (S107).
        // Neither form dominates the other, so both are matched.
        foreach ($this->decodeCandidates($path) as $candidate) {
            $forms = [$candidate, $this->normaliseForDenyMatch($candidate)];
            foreach ($forms as $form) {
                foreach (self::SCOPE_DENY_PATTERNS as $denied) {
                    if (preg_match($denied, $form) === 1) {
                        return false;
                    }
                }
            }
        }

        $allowedPrefixes = self::BROWSE_SCOPE_ALLOWLIST[$method] ?? [];
        foreach ($allowedPrefixes as $prefix) {
            // Match either an exact collection path or a sub-path under it
            // (e.g. `/api/v1/media/abc`), never a sibling like
            // `/api/v1/mediaXYZ`.
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // Exact, fully-anchored patterns for every write action + the narrow
        // non-prefix reads. Anchoring + a single-segment id guarantees no other
        // `/api/v1/media/{id}/*` mutation (or admin/scan path) can match.
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
        // The relayed user identity. CAVEAT (do not read more into this header
        // than it delivers): this is the HUB user's UUID, which matches no row in
        // the paired server's `users` table. `RelayConsumer::buildRequest()`
        // applies it as `$request->userId`, which is enough to satisfy the
        // server's `AuthMiddleware` presence check and to attribute the request
        // in logs — but any server-side protection that RESOLVES the id against
        // its own user rows is inert for relay traffic: `RatingGate` finds no
        // parental-control cap for it, and per-user session lookups (music
        // `now-playing`) find no sessions. Mapping the hub identity to a server
        // identity is tracked as its own step; until it lands, do NOT claim the
        // server applies this user's parental-control profile to relayed
        // requests. The hub-side gate (authenticated user → server ownership →
        // traversal → browse scope) is what actually protects this surface.
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
     * @param array<string, mixed> $reply Relay reply (status/headers/body).
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
        $rawBody = $reply['body'] ?? null;
        $response->body = is_string($rawBody) ? $rawBody : '';

        return $response;
    }
}
