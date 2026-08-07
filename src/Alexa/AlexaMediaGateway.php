<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

use function array_combine;
use function array_keys;
use function array_map;
use function http_build_query;
use function is_array;
use function json_decode;
use function ltrim;

/**
 * The ONLY capability surface the Alexa skill is given (S91).
 *
 * ## Prior art, deliberately copied
 *
 * This is the {@see \Phlix\Hub\Mcp\McpToolContext} shape applied to a second
 * caller. That class exists because an MCP tool receives caller-controlled
 * arguments and must not be able to reach the relay bridge, the database or a
 * server row directly — otherwise "did the author remember the ownership check?"
 * becomes a per-call-site question whose answer is eventually no. An Alexa intent
 * is the same threat with a different envelope: the slot values are whatever a
 * stranger said to a microphone, and the request arrives at a public HTTPS
 * endpoint. So the skill gets THIS object, exposing exactly three operations,
 * every one of which goes through the SAME production controllers the SPA's own
 * requests go through:
 *
 *  - {@see servers()} → {@see ServerListController::listServers()}
 *  - {@see search()}  → {@see ServerProxyController::proxy()}
 *  - {@see media()}   → {@see ServerProxyController::proxy()}
 *
 * All three are handed a request whose `userId` this class sets, on every call,
 * from the value {@see AlexaAccountLink} resolved out of the linked-account
 * token. There is no setter and no argument path by which a slot value can change
 * whose identity the call runs as.
 *
 * ## What the two proxy methods inherit by construction
 *
 * Because they call the real `ServerProxyController::proxy()`, every gate that
 * controller already applies applies here too, in its own order and without being
 * restated: the 401 when no user id is present, the `rate_limiter.proxy`
 * user-keyed limiter, 404 `server.not_found`, **403 `server.not_owned` when the
 * server belongs to somebody else**, the 503 when the relay tunnel is down, the
 * bandwidth-quota check, dot-segment / encoded-traversal rejection, and finally
 * `BROWSE_SCOPE_ALLOWLIST` / `BROWSE_SCOPE_PATTERNS` / `SCOPE_DENY_PATTERNS`.
 *
 * ⚠ That last gate is why these methods take no free-form path. The skill reaches
 * exactly two server routes, both already inside the proxy's read-only GET
 * allowlist:
 *
 *  - `GET /api/v1/media/search` — a `/`-delimited sub-path of the allowed
 *    `/api/v1/media` prefix; and
 *  - `GET /api/v1/media/{id}` — the same prefix.
 *
 * **Widening that allowlist is a change to `ServerProxyController`, with that
 * controller's own enumeration rule attached (S107/S238), reviewed there and
 * pinned by its own allow/deny provider pair — never a change made here, and
 * never a second route to the tunnel opened from this file.** A skill feature
 * that needs a path the allowlist does not cover is a finding to report, not a
 * reason to reach the bridge another way: a second door silently re-opens
 * everything those maps exist to close.
 *
 * ## The request handed to the controllers is built here, not forwarded
 *
 * The inbound `POST /alexa/skill` request is NOT passed through. A fresh
 * {@see Request} is minted per call carrying only method, path, query, the client
 * IP and the derived `userId`. Every inbound header is dropped — in particular
 * Amazon's `Signature-256` and `SignatureCertChainUrl`, and the linked-account
 * bearer token, none of which has any business travelling to a media server.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaMediaGateway
{
    /** Server-side search route. Inside the proxy's `/api/v1/media` GET prefix. */
    public const SEARCH_PATH = '/api/v1/media/search';

    /** Server-side detail route prefix. Same allowlisted prefix as the search path. */
    public const MEDIA_PATH_PREFIX = '/api/v1/media/';

    /**
     * @param ServerProxyController $proxy      The production relay proxy controller —
     *        the same instance type `/api/v1/servers/{id}/proxy/…` is served by.
     * @param ServerListController  $serverList The production server-list controller.
     * @param string                $userId     Hub user id resolved from the linked
     *        account by {@see AlexaAccountLink}. Assigned to every sub-request; never
     *        sourced from a slot value.
     * @param string                $clientIp   Trusted client IP of the inbound Alexa
     *        request, carried through so the proxy's `X-Forwarded-For` stamp is truthful.
     */
    public function __construct(
        private readonly ServerProxyController $proxy,
        private readonly ServerListController $serverList,
        private readonly string $userId,
        private readonly string $clientIp = '',
    ) {
    }

    /**
     * `GET /api/v1/me/servers` as the linked user.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function servers(): array
    {
        $request = $this->subRequest('GET', '/api/v1/me/servers', '');

        return self::decode($this->serverList->listServers($request));
    }

    /**
     * `GET /api/v1/media/search` on an owned server.
     *
     * The term is passed through {@see http_build_query()} rather than
     * concatenated, so a spoken title containing `&`, `=` or a space cannot
     * invent a second query parameter.
     *
     * @param string $serverId Server UUID. NOT trusted: the proxy controller
     *        resolves the row and answers 404/403 when the linked user does not
     *        own it.
     * @param string $query    Free-text search term (a slot value).
     * @param int    $limit    Result cap.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function search(string $serverId, string $query, int $limit): array
    {
        return $this->relay(
            $serverId,
            self::SEARCH_PATH,
            http_build_query(['q' => $query, 'limit' => $limit]),
        );
    }

    /**
     * `GET /api/v1/media/{id}` on an owned server.
     *
     * @param string $serverId Server UUID. NOT trusted; see {@see search()}.
     * @param string $mediaId  Media item id, as returned by {@see search()}.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function media(string $serverId, string $mediaId): array
    {
        return $this->relay($serverId, self::MEDIA_PATH_PREFIX . $mediaId, '');
    }

    /**
     * The single call site for `ServerProxyController::proxy()`.
     *
     * Kept private and kept singular on purpose: the streaming backstop and the
     * router-shaped `path` parameter below must apply to every call, and two
     * copies of this would eventually disagree about one of them. It is also
     * GET-only — this skill reads, it never writes — so there is no verb
     * parameter for a future intent to widen by accident.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function relay(string $serverId, string $path, string $query): array
    {
        $normalisedPath = '/' . ltrim($path, '/');
        $request = $this->subRequest(
            'GET',
            '/api/v1/servers/' . $serverId . '/proxy' . $normalisedPath,
            $query,
        );

        // `{path:.*}` is captured WITHOUT its leading slash by the live router
        // (`/api/v1/servers/{id}/proxy/{path:.*}`), and proxy() re-adds one, so
        // the tail passed here is ltrim'd to match what the router would give.
        $response = $this->proxy->proxy($request, [
            'id' => $serverId,
            'path' => ltrim($normalisedPath, '/'),
        ]);

        // A streaming reply means a byte-serving family (`/hls`, `/dash`,
        // `/media`) whose body is written straight to a browser socket by a
        // producer callback. There is no socket here, so the body is empty and
        // decoding it would report an EMPTY SUCCESS. Neither path this class can
        // reach streams, so this is unreachable today and says so rather than
        // returning a silent blank if that ever changes.
        if ($response->streamProducer !== null) {
            return [
                'status' => 501,
                'payload' => [
                    'error' => 'Not Implemented',
                    'code' => 'alexa.streaming_unsupported',
                ],
            ];
        }

        return self::decode($response);
    }

    /**
     * Build the request a production controller is handed.
     *
     * `userId` is assigned HERE, on every single call, from the constructor value
     * the account link produced. That single assignment — and the absence of any
     * other — is what makes the ownership check unforgettable rather than merely
     * tested.
     */
    private function subRequest(string $method, string $path, string $query): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->queryString = $query;
        $request->remoteIp = $this->clientIp;
        // Deliberately NOT copied from the inbound /alexa/skill request: Amazon's
        // signature headers and the linked-account bearer token have no business
        // travelling any further.
        $request->headers = ['ACCEPT' => 'application/json'];
        $request->userId = $this->userId;

        return $request;
    }

    /**
     * Turn a controller {@see Response} into a status + decoded payload.
     *
     * A non-JSON or unparseable body becomes `{"raw": "…"}` rather than being
     * dropped, so the server's own error text survives into the log rather than
     * vanishing behind a generic apology.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private static function decode(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return ['status' => $response->statusCode, 'payload' => ['raw' => $response->body]];
        }

        // Built via array_combine rather than a `$payload[(string) $key] = $value`
        // loop so the string key type is DERIVED (from the mapper's `: string`
        // return) instead of merely asserted — the same reason McpToolContext does
        // it this way, and the one shape psalm's errorLevel 1 accepts.
        $payload = array_combine(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($decoded)),
            $decoded,
        );

        return ['status' => $response->statusCode, 'payload' => $payload];
    }
}
