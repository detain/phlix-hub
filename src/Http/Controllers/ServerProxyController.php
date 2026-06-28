<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

use function base64_decode;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function ltrim;
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
 * Scope (Phase 1): JSON/browse traffic — libraries, media lists, detail. Binary
 * media streaming over the tunnel is a later phase.
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
     * The proxy exists to expose read-only browse traffic (libraries, media
     * lists/detail, search, images/posters, OPDS catalog) to an authenticated,
     * server-owning hub user. Anything outside this set — notably admin,
     * mutating, scan/transcode, or streaming endpoints — is rejected with 403
     * `proxy.scope_denied` BEFORE forwarding, so a compromised/confused client
     * cannot use the hub as a deputy to reach privileged server APIs.
     *
     * Keyed by HTTP method (upper-case); each value is a list of allowed path
     * prefixes (matched against the resolved `/`-prefixed forward path).
     *
     * @var array<string, list<string>>
     */
    private const BROWSE_SCOPE_ALLOWLIST = [
        'GET' => [
            '/api/v1/libraries',
            '/api/v1/media',
            '/api/v1/search',
            '/api/v1/collections',
            '/api/v1/genres',
            '/api/v1/studios',
            '/api/v1/people',
            '/api/v1/images',
            '/api/v1/opds',
        ],
        'HEAD' => [
            '/api/v1/libraries',
            '/api/v1/media',
            '/api/v1/search',
            '/api/v1/collections',
            '/api/v1/genres',
            '/api/v1/studios',
            '/api/v1/people',
            '/api/v1/images',
            '/api/v1/opds',
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
     * @param ServerInfoHandler $serverInfo Resolves server ownership + relay status.
     * @param RelayProxyBridge  $bridge     Cross-process bridge to the relay worker.
     * @param StructuredLogger  $logger     Relay logger.
     * @param int               $timeoutSeconds Seconds to await the server response.
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
            return (new Response())->status(503)->json([
                'error' => 'Server offline',
                'code' => 'server.offline',
                'message' => 'No active relay tunnel for this server.',
            ]);
        }

        $path = '/' . ltrim($params['path'] ?? '', '/');

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

        $reply = $this->bridge->request(
            $serverId,
            $request->method,
            $path,
            $request->queryString,
            $headers,
            $body,
            (float) $this->timeoutSeconds,
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
     * Determine whether a method + resolved path is within the browse-scope
     * allowlist the proxy is permitted to forward.
     *
     * @param string $method The inbound HTTP method.
     * @param string $path   The resolved `/`-prefixed forward path.
     *
     * @return bool True when the request may be forwarded.
     */
    private function isWithinBrowseScope(string $method, string $path): bool
    {
        $allowedPrefixes = self::BROWSE_SCOPE_ALLOWLIST[strtoupper($method)] ?? null;
        if ($allowedPrefixes === null) {
            return false;
        }

        foreach ($allowedPrefixes as $prefix) {
            // Match either an exact collection path or a sub-path under it
            // (e.g. `/api/v1/media/abc`), never a sibling like
            // `/api/v1/mediaXYZ`.
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
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
        foreach ($request->headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }
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
        $status = is_int($reply['status'] ?? null) ? $reply['status'] : 502;

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

        $bodyB64 = is_string($reply['body_b64'] ?? null) ? $reply['body_b64'] : '';
        $response->body = $bodyB64 === '' ? '' : (base64_decode($bodyB64, true) ?: '');

        return $response;
    }
}
