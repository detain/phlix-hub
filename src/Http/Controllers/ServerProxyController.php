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
use function strtolower;

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
            if (in_array(strtolower($name), self::STRIPPED_REQUEST_HEADERS, true)) {
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
