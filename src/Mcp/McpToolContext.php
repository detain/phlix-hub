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
 * reach. They get THIS object, which exposes exactly two operations, and both of
 * them go through the SAME production controllers the SPA's own requests go
 * through:
 *
 *  - {@see servers()}   → {@see ServerListController::listServers()}
 *  - {@see proxyGet()}  → {@see ServerProxyController::proxy()}
 *
 * Both are handed a request whose `userId` this class sets — on every call, from
 * {@see McpToken::$userId}, which came from the row the presented PAT hashed to.
 * There is no setter, no parameter and no argument path by which a tool (or a
 * JSON-RPC envelope, or a malicious `server_id`) can change whose identity the
 * call runs as. Forgetting the ownership check is therefore not a mistake a tool
 * author can make: the check lives on the far side of the only door.
 *
 * ## What {@see proxyGet()} inherits by construction
 *
 * Because it calls the real `ServerProxyController::proxy()`, every gate that
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
 * ⚠ Gate 7 is the reason {@see proxyGet()} takes a path rather than a URL and is
 * GET-only. MCP tools wrap paths the proxy ALREADY allows. If a tool ever needs
 * a path the allowlist does not cover, that is a finding to report, not a reason
 * to widen the allowlist or to reach the bridge another way — a second route to
 * the tunnel silently re-opens everything those maps exist to close.
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

        // A streaming reply means the tool asked for a byte-serving family
        // (`/hls`, `/dash`, `/media`) whose body is written straight to a
        // browser socket by a producer callback. There is no socket here, so
        // $body is empty and decoding it would report an EMPTY SUCCESS. Say so
        // instead: no S62 tool targets those prefixes, and the day one does,
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
     */
    private function subRequest(string $method, string $path, string $query): Request
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
        $request->body = [];
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
