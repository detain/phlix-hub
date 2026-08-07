<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

use function is_array;
use function json_decode;
use function ltrim;

/**
 * The ONLY capability surface an MCP tool is given (S62).
 *
 * ## The whole point of this class
 *
 * An MCP tool receives caller-controlled arguments from a JSON-RPC envelope. If
 * a tool could reach the relay bridge, the database, or a server row directly,
 * then "did the author remember the ownership check?" would be a per-tool
 * question — and the answer would eventually be no. So tools are given no such
 * reach. They get THIS object, which exposes exactly three operations, and every
 * one of them goes through the SAME production controllers the SPA's own
 * requests go through:
 *
 *  - {@see servers()}   → {@see ServerListController::listServers()}
 *  - {@see proxyGet()}  → {@see ServerProxyController::proxy()}
 *  - {@see proxyPost()} → {@see ServerProxyController::proxy()}
 *
 * ⚠ S63 added the third. It is a WRITE verb, so read what it did and did not
 * change. It did NOT add a second door: it is the same `proxy()` call as
 * {@see proxyGet()}, with `POST` in the method field and a JSON body, so it
 * passes through the identical ownership, quota, traversal and browse-scope
 * gates in the identical order — and `BROWSE_SCOPE_PATTERNS['POST']` is a much
 * NARROWER map than the GET one (a handful of fully-anchored per-action PCREs,
 * no prefixes at all). What it did change is that a tool can now cause a
 * server-side effect, which is why {@see \Phlix\Hub\Mcp\Tools\PlaybackControlTool}
 * ships behind a default-off flag and its own scope.
 *
 * All three are handed a request whose `userId` this class sets — on every call,
 * from {@see McpToken::$userId}, which came from the row the presented PAT
 * hashed to. There is no setter, no parameter and no argument path by which a
 * tool (or a JSON-RPC envelope, or a malicious `server_id`) can change whose
 * identity the call runs as. Forgetting the ownership check is therefore not a
 * mistake a tool author can make: the check lives on the far side of the only
 * door.
 *
 * ## What {@see proxyGet()} and {@see proxyPost()} inherit by construction
 *
 * Because they call the real `ServerProxyController::proxy()`, every gate that
 * controller already applies applies here too, in its own order and without
 * being restated:
 *
 *  1. 401 when no user id is present;
 *  2. the `rate_limiter.proxy` user-keyed limiter;
 *  3. 404 `server.not_found` for an unknown server;
 *  4. **403 `server.not_owned` when the server belongs to somebody else** —
 *     the acceptance criterion this step exists for;
 *  5. 503 when the relay tunnel is not connected, and the bandwidth quota check;
 *  6. dot-segment / encoded-traversal rejection;
 *  7. `BROWSE_SCOPE_ALLOWLIST` / `BROWSE_SCOPE_PATTERNS` / `SCOPE_DENY_PATTERNS`.
 *
 * ⚠ Gate 7 is the reason these methods take a PATH rather than a URL, and why
 * each is fixed to one verb. MCP tools wrap paths the proxy allows. If a tool
 * ever needs a path the allowlist does not cover, that is a finding to report,
 * not a reason to reach the bridge another way — a second route to the tunnel
 * silently re-opens everything those maps exist to close. Widening the allowlist
 * is possible, but it is a change to `ServerProxyController` with that
 * controller's own enumeration rule attached (S107/S238), reviewed there and
 * pinned by its own allow/deny provider pair — never a change made here.
 *
 * ## The request handed to the controllers is built here, not forwarded
 *
 * The inbound `POST /mcp` request is NOT passed through. A fresh {@see Request}
 * is minted per call carrying only method, path, query, the client IP and the
 * derived `userId`. In particular the inbound headers are dropped, so the PAT in
 * the caller's `Authorization` header is never in scope to be forwarded to a
 * media server. (`ServerProxyController` strips `authorization` anyway; not
 * having it there in the first place is the belt.)
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpToolContext
{
    /**
     * @param McpToken              $token       The validated PAT. Its `userId` is
     *        re-read on EVERY call below; it is never cached into a mutable field
     *        and never sourced from tool arguments.
     * @param ServerProxyController $proxy       The production relay proxy controller —
     *        the same instance type `/api/v1/servers/{id}/proxy/…` is served by.
     * @param ServerListController  $serverList  The production server-list controller.
     * @param string                $clientIp    Client IP of the inbound `/mcp` request,
     *        carried through so the proxy's `X-Forwarded-For` stamp is truthful.
     */
    public function __construct(
        private readonly McpToken $token,
        private readonly ServerProxyController $proxy,
        private readonly ServerListController $serverList,
        private readonly string $clientIp = '',
    ) {
    }

    /**
     * The scopes the presenting token holds. Read by {@see McpToolRegistry}
     * before a tool is invoked; tools themselves should not re-check.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->token->scopes;
    }

    /**
     * `GET /api/v1/me/servers` as the token's user.
     *
     * Delegates to the production controller, which gates on `userId` exactly as
     * it does for the SPA. A token whose user owns nothing gets an empty list —
     * never another user's rows.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function servers(): array
    {
        $request = $this->subRequest('GET', '/api/v1/me/servers', '');

        return self::decode($this->serverList->listServers($request));
    }

    /**
     * `GET` a path on an owned media server, over the relay, as the token's user.
     *
     * @param string $serverId Server UUID the caller named. NOT trusted: it is
     *        handed to the proxy controller, which resolves the row and answers
     *        404/403 when the token's user does not own it.
     * @param string $path     Server-side path, `/`-prefixed (e.g.
     *        `/api/v1/libraries`). Must already be inside the proxy's browse
     *        allowlist — a path outside it comes back as 403 `proxy.scope_denied`
     *        rather than being forwarded.
     * @param string $query    Raw query string, without the leading `?`.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function proxyGet(string $serverId, string $path, string $query = ''): array
    {
        return $this->relay('GET', $serverId, $path, $query, []);
    }

    /**
     * `POST` a JSON body to a path on an owned media server, over the relay, as
     * the token's user.
     *
     * ## Why a write verb is safe HERE and not safe in general (S63)
     *
     * This is the same `ServerProxyController::proxy()` call {@see proxyGet()}
     * makes, so ownership, quota, traversal and browse-scope are all decided by
     * the same code in the same order. The difference is which map decides gate
     * 7: for POST that is `BROWSE_SCOPE_PATTERNS['POST']` ONLY. There is no POST
     * key in `BROWSE_SCOPE_ALLOWLIST` at all, deliberately, so a write cannot
     * ride a broad read prefix — every writable path is a fully-anchored PCRE
     * naming one action on one server route. A path outside that map comes back
     * as 403 `proxy.scope_denied` without ever reaching the tunnel.
     *
     * @param string               $serverId Server UUID the caller named. NOT
     *        trusted: the proxy controller resolves the row and answers 404/403
     *        when the token's user does not own it.
     * @param string               $path     Server-side path, `/`-prefixed. Must
     *        match an anchored `BROWSE_SCOPE_PATTERNS['POST']` entry.
     * @param array<string, mixed> $body     JSON request body. Encoded by
     *        `ServerProxyController::reconstructBody()`; an empty array sends no
     *        body at all.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function proxyPost(string $serverId, string $path, array $body = []): array
    {
        return $this->relay('POST', $serverId, $path, '', $body);
    }

    /**
     * The single call site for `ServerProxyController::proxy()`.
     *
     * Kept private and kept singular on purpose: the streaming backstop and the
     * router-shaped `path` parameter below must apply to EVERY verb, and two
     * copies of this would eventually disagree about one of them.
     *
     * @param array<string, mixed> $body
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function relay(string $method, string $serverId, string $path, string $query, array $body): array
    {
        $normalisedPath = '/' . ltrim($path, '/');
        $request = $this->subRequest(
            $method,
            '/api/v1/servers/' . $serverId . '/proxy' . $normalisedPath,
            $query,
            $body,
        );

        // `{path:.*}` is captured WITHOUT its leading slash by the live router
        // (`/api/v1/servers/{id}/proxy/{path:.*}`), and proxy() re-adds one, so
        // the tail passed here is ltrim'd to match what the router would give.
        $response = $this->proxy->proxy($request, [
            'id' => $serverId,
            'path' => ltrim($normalisedPath, '/'),
        ]);

        // A streaming reply means the tool asked for a byte-serving family
        // (`/hls`, `/dash`, `/media`) whose body is written straight to a
        // browser socket by a producer callback. There is no socket here, so
        // $body is empty and decoding it would report an EMPTY SUCCESS. Say so
        // instead: no shipped tool targets those prefixes, and the day one does,
        // this must be a deliberate decision rather than a silent blank.
        if ($response->streamProducer !== null) {
            return [
                'status' => 501,
                'payload' => [
                    'error' => 'Not Implemented',
                    'code' => 'mcp.streaming_unsupported',
                    'message' => 'This path streams bytes and cannot be returned as an MCP tool result.',
                ],
            ];
        }

        return self::decode($response);
    }

    /**
     * Build the request a production controller is handed.
     *
     * `userId` is assigned HERE, from the validated token, on every single call.
     * That single assignment — and the absence of any other — is what makes the
     * ownership check unforgettable rather than merely tested.
     *
     * @param array<string, mixed> $body Request body, JSON-encoded downstream by
     *        `ServerProxyController::reconstructBody()`. `[]` sends no body.
     */
    private function subRequest(string $method, string $path, string $query, array $body = []): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->queryString = $query;
        $request->remoteIp = $this->clientIp;
        // Deliberately NOT copied from the inbound /mcp request: the caller's
        // PAT lives in its Authorization header and has no business travelling
        // any further.
        $request->headers = ['ACCEPT' => 'application/json'];
        $request->body = $body;
        $request->userId = $this->token->userId;

        return $request;
    }

    /**
     * Turn a controller {@see Response} into the status + decoded payload a tool
     * returns.
     *
     * A non-JSON or unparseable body becomes `{"raw": "…"}` rather than being
     * dropped: a tool result that silently loses the server's own error text is
     * worse than one that passes it through verbatim.
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
        // loop so the string key type is *derived* (from the mapper's `: string`
        // return) instead of merely asserted. A loop would also bind each decoded
        // value to a `mixed` variable, which is the one thing errorLevel 1 forbids.
        $payload = array_combine(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($decoded)),
            $decoded,
        );

        return ['status' => $response->statusCode, 'payload' => $payload];
    }
}
