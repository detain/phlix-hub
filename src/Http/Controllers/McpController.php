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
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\JsonRpc;
use Phlix\Hub\Mcp\McpInvalidArgumentsException;
use Phlix\Hub\Mcp\McpToken;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Version;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function strlen;
use function trim;

use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * `POST /mcp` — the hub's Model Context Protocol endpoint (S62).
 *
 * A JSON-RPC 2.0 endpoint served by the EXISTING `:8800` HTTP worker (not a
 * sidecar, not a new port), authenticated with a personal access token minted by
 * {@see McpTokenController}.
 *
 * ## Why this route is registered with NO middleware
 *
 * `AuthMiddleware` authenticates a hub USER SESSION — an HS256 access JWT or the
 * `phlix_hub_token` cookie. An MCP client has neither; it has a PAT. So this
 * controller authenticates itself, exactly as `RelayController`,
 * `ClientMountController` and `SubdomainController` already do for their own
 * (Ed25519 enrollment) credential. `ApplicationRouteCompositionTest` pins the
 * ungated set, so the absence of a gate here is declared, not accidental.
 *
 * The credential is accepted ONLY from the `Authorization: Bearer` header. There
 * is deliberately no `?token=` query fallback: Workerman never populates `$_GET`
 * (S237 removed the last query-string credential in this repository), query
 * strings land in access logs, and an MCP client can always set a header.
 *
 * ## Ordering, and why it is this ordering
 *
 *  1. **Extract the bearer token.** Absent ⇒ 401 with `WWW-Authenticate`, and no
 *     limiter bucket is minted — an unauthenticated flood must not be able to
 *     fill the rate-limit table (the same reasoning as
 *     {@see ServerProxyController::proxy()}'s cheap-401-before-limiter note).
 *  2. **Rate-limit exactly the way login does.** `peek()` first and throw if the
 *     window is already spent; `hit()` only on a FAILED validation;
 *     `reset()` on success. That is
 *     {@see \Phlix\Hub\Auth\AuthManager}'s login flow verbatim, on the shared
 *     DB-backed limiter, because brute-forcing a PAT is the same threat as
 *     brute-forcing a password and deserves the same globally-counted budget —
 *     not a second, weaker, per-worker mechanism invented here.
 *  3. **Validate the PAT** ⇒ {@see McpToken}. Failure ⇒ 401.
 *  4. **Build the {@see McpToolContext} once**, from the validated token.
 *  5. **Only then** parse the JSON-RPC envelope and dispatch.
 *
 * Step 4 before step 5 is the structural half of this step's acceptance
 * criterion: the identity a tool runs as is fixed from the token BEFORE any
 * caller-controlled JSON is looked at, so nothing in the envelope can influence
 * it. The context exposes no way to change it afterwards.
 *
 * ## What is deliberately NOT here (S63 owns it)
 *
 * `GET /mcp` SSE transport; protocol-VERSION negotiation (this build answers
 * with {@see PROTOCOL_VERSION} regardless of what the client asked for, and says
 * so in the response); JSON-RPC/MCP schema validation; batch requests (rejected
 * with a named error rather than silently mishandled — MCP removed batching in
 * the 2025-06-18 revision); and the `playback_control` write tool.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpController
{
    /**
     * The MCP protocol revision this build implements.
     *
     * S62 ANSWERS with this constant unconditionally; it does not negotiate.
     * A client asking for a different revision is told which one it got rather
     * than being failed, so an older client still works for the tool calls that
     * did not change. Real negotiation is S63.
     */
    public const string PROTOCOL_VERSION = '2025-06-18';

    /** Limiter bucket key prefix. Mirrors `auth:login:<ip>`. */
    private const string RATE_LIMIT_KEY_PREFIX = 'mcp:auth:';

    /**
     * Largest JSON-RPC body accepted, in bytes.
     *
     * A tool call is a handful of short arguments; anything approaching this is
     * not a legitimate MCP request. Bounded here rather than left to the worker
     * so a large body is refused before it is parsed.
     */
    private const int MAX_BODY_BYTES = 262144;

    /**
     * @param McpTokenService       $tokens      Validates the presented PAT.
     * @param McpToolRegistry       $registry    The tool catalogue + invoker.
     * @param ServerProxyController $proxy       Production relay proxy; the ONLY
     *        route a tool has to a media server, and the holder of the ownership
     *        and browse-scope gates this endpoint relies on.
     * @param ServerListController  $serverList  Production server-list controller.
     * @param RateLimiterInterface  $rateLimiter The `rate_limiter.mcp` profile
     *        (shared DB-backed, like login).
     * @param StructuredLogger      $logger      Auth channel logger.
     */
    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly McpToolRegistry $registry,
        private readonly ServerProxyController $proxy,
        private readonly ServerListController $serverList,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * `POST /mcp`.
     *
     * @param Request               $request The inbound HTTP request.
     * @param array<string, string> $params  Unused; the route has no parameters.
     *
     * @return Response A JSON-RPC response, a 401, or a 202 for a notification.
     *
     * @throws RateLimitException When the client IP has spent its PAT-auth budget.
     */
    public function handle(Request $request, array $params = []): Response
    {
        $token = $this->authenticate($request);
        if ($token instanceof Response) {
            return $token;
        }

        $context = new McpToolContext(
            $token,
            $this->proxy,
            $this->serverList,
            $request->remoteIp,
        );

        return $this->dispatchJsonRpc($request, $context);
    }

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    /**
     * Resolve the presented PAT, or the 401 to answer with.
     *
     * @return McpToken|Response The validated identity, or the refusal.
     *
     * @throws RateLimitException When the window is already spent.
     */
    private function authenticate(Request $request): McpToken|Response
    {
        $presented = trim($request->bearerToken ?? '');
        if ($presented === '') {
            // No limiter bucket is minted for a credential-less request: this
            // 401 is cheaper than a limiter write, and letting an anonymous
            // flood create rows would turn the limiter into the amplifier.
            return $this->unauthorized('auth.required', 'An MCP personal access token is required.');
        }

        $key = self::RATE_LIMIT_KEY_PREFIX . $request->getTrustedClientIp();

        // Login's flow, verbatim: peek and refuse before doing any work, so a
        // spent window costs a lookup rather than a token hash + a query.
        $state = $this->rateLimiter->peek($key);
        if ($state->limited) {
            throw new RateLimitException(resetAt: $state->resetAt, remaining: 0);
        }

        $token = $this->tokens->validate($presented);
        if ($token === null) {
            // Count the FAILURE, as login does — a successful call must never
            // consume budget, or a busy agent would lock itself out.
            $this->rateLimiter->hit($key);
            $this->logger->info('MCP: rejected token', [
                'shaped_as_mcp_token' => McpTokenService::looksLikeMcpToken($presented),
            ]);

            return $this->unauthorized(
                'auth.invalid_token',
                McpTokenService::looksLikeMcpToken($presented)
                    ? 'This MCP token is unknown, expired, or revoked.'
                    : 'This is not an MCP personal access token. Mint one at '
                        . 'POST /api/v1/me/mcp-tokens and present it as a Bearer token.',
            );
        }

        $this->rateLimiter->reset($key);
        $this->tokens->touch($token->id);

        return $token;
    }

    /**
     * The 401 body, with the `WWW-Authenticate` challenge an MCP client looks
     * for.
     */
    private function unauthorized(string $code, string $message): Response
    {
        return (new Response())
            ->status(401)
            ->header('WWW-Authenticate', 'Bearer realm="phlix-hub-mcp"')
            ->json([
                'error' => 'Unauthorized',
                'code' => $code,
                'message' => $message,
            ]);
    }

    // ------------------------------------------------------------------
    // JSON-RPC
    // ------------------------------------------------------------------

    /**
     * Parse the envelope and route it to a method handler.
     */
    private function dispatchJsonRpc(Request $request, McpToolContext $context): Response
    {
        $raw = $request->rawBody;

        if ($raw === '') {
            return $this->rpc(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Empty request body.'));
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->rpc(JsonRpc::error(
                null,
                JsonRpc::INVALID_REQUEST,
                'Request body exceeds ' . self::MAX_BODY_BYTES . ' bytes.',
            ));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->rpc(JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Request body is not valid JSON.'));
        }

        if (!is_array($decoded)) {
            return $this->rpc(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Request must be a JSON object.'));
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            // A non-empty JSON array at the top level is a JSON-RPC BATCH. MCP
            // removed batching in its 2025-06-18 revision, and a batch silently
            // treated as one malformed request would look to the client like the
            // hub ignoring half its work. Name it. (`[]` and `{}` both decode to
            // the same empty array, so the empty case is left to the
            // missing-"method" branch below rather than guessed at here.)
            return $this->rpc(JsonRpc::error(
                null,
                JsonRpc::INVALID_REQUEST,
                'Batched JSON-RPC requests are not supported; send one request per POST.',
            ));
        }

        /** @var mixed $rawId */
        $rawId = $decoded['id'] ?? null;
        $id = is_string($rawId) || is_int($rawId) ? $rawId : null;
        $isNotification = !array_key_exists('id', $decoded);

        /** @var mixed $rawMethod */
        $rawMethod = $decoded['method'] ?? null;
        if (!is_string($rawMethod) || $rawMethod === '') {
            if ($isNotification) {
                return $this->accepted();
            }

            return $this->rpc(JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Missing "method".'));
        }

        /** @var mixed $rawParams */
        $rawParams = $decoded['params'] ?? [];
        $params = self::stringKeyed($rawParams);

        // A notification expects no response body at all, ever — not even an
        // error. `notifications/initialized` is the one an MCP client actually
        // sends after `initialize`; anything else is ignored just as quietly,
        // which is what JSON-RPC 2.0 §4.1 requires.
        if ($isNotification) {
            return $this->accepted();
        }

        try {
            return match ($rawMethod) {
                'initialize' => $this->rpc(JsonRpc::result($id, $this->initializeResult($params))),
                'ping' => $this->rpc(JsonRpc::result($id, [])),
                'tools/list' => $this->rpc(JsonRpc::result($id, ['tools' => $this->registry->describe()])),
                'tools/call' => $this->rpc(JsonRpc::result($id, $this->callTool($params, $context))),
                default => $this->rpc(JsonRpc::error(
                    $id,
                    JsonRpc::METHOD_NOT_FOUND,
                    'Unknown method "' . $rawMethod . '".',
                )),
            };
        } catch (McpInvalidArgumentsException $e) {
            return $this->rpc(JsonRpc::error($id, JsonRpc::INVALID_PARAMS, $e->getMessage()));
        }
    }

    /**
     * The `initialize` result.
     *
     * @param array<string, mixed> $params Client-supplied initialize params.
     *
     * @return array<string, mixed>
     */
    private function initializeResult(array $params): array
    {
        /** @var mixed $requested */
        $requested = $params['protocolVersion'] ?? null;
        $requestedVersion = is_string($requested) ? $requested : null;

        /** @var array<string, mixed> $result */
        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                // `listChanged: false` is the honest answer: the tool set is
                // fixed at container-build time, so the hub will never send a
                // list-changed notification. Claiming otherwise would have a
                // client waiting for one.
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'phlix-hub',
                'version' => Version::VERSION,
            ],
            'instructions' => 'Call list_servers first to get a server_id, then list_libraries or '
                . 'search_media against that server. Every call runs as the account that minted this '
                . 'token and can only reach servers that account has claimed.',
        ];

        if ($requestedVersion !== null && $requestedVersion !== self::PROTOCOL_VERSION) {
            // S62 does not negotiate (S63 does). Saying so beats letting a
            // client assume its requested revision was accepted.
            $result['_meta'] = [
                'phlix/protocolVersionRequested' => $requestedVersion,
                'phlix/protocolVersionNegotiated' => false,
            ];
        }

        return $result;
    }

    /**
     * Handle `tools/call`.
     *
     * @param array<string, mixed> $params  JSON-RPC params (`name`, `arguments`).
     * @param McpToolContext       $context Built from the validated PAT.
     *
     * @return array<string, mixed> The MCP `CallToolResult`.
     *
     * @throws McpInvalidArgumentsException When a tool rejects its arguments.
     */
    private function callTool(array $params, McpToolContext $context): array
    {
        /** @var mixed $rawName */
        $rawName = $params['name'] ?? null;
        if (!is_string($rawName) || $rawName === '') {
            throw new McpInvalidArgumentsException('"name" is required and must name a tool.');
        }

        $arguments = self::stringKeyed($params['arguments'] ?? []);

        $outcome = $this->registry->call($rawName, $arguments, $context);

        return self::toolResult($outcome['status'], $outcome['payload']);
    }

    /**
     * Render a tool outcome as an MCP `CallToolResult`.
     *
     * A tool that returns an upstream 4xx/5xx is NOT a JSON-RPC error: the call
     * itself succeeded, the answer was "no". MCP models that as `isError: true`
     * on a normal result so the model can read the reason and adjust, which is
     * exactly what should happen when the answer is "you do not own that
     * server". Turning it into a transport-level error would hide the reason.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function toolResult(int $status, array $payload): array
    {
        $text = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'structuredContent' => $payload,
            'isError' => $status >= 400,
            '_meta' => ['phlix/httpStatus' => $status],
        ];
    }

    /**
     * Coerce an arbitrary decoded value into a string-keyed map.
     *
     * JSON-RPC `params` may legitimately be an array (positional) or absent;
     * neither is usable by the by-name tool arguments MCP defines, and both
     * collapse to `[]` here rather than to something half-read.
     *
     * @param mixed $raw
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        // `ARRAY_FILTER_USE_KEY` guarantees every surviving key is a string, but
        // Psalm cannot derive that: its array_filter provider only applies the
        // callback's assertions when no third argument is present, so with a
        // flag it hands back the *original* key type. The `@var` states the
        // fact the filter already enforces. Same idiom as `Http\Router`.
        /** @var array<string, mixed> $out */
        $out = array_filter($raw, 'is_string', ARRAY_FILTER_USE_KEY);

        return $out;
    }

    /**
     * Wrap a JSON-RPC envelope in an HTTP 200.
     *
     * A JSON-RPC ERROR still rides a 200: the HTTP status describes the
     * transport, and the transport worked. The two statuses that are NOT 200 —
     * 401 and 429 — are the ones where no JSON-RPC processing happened at all.
     *
     * @param array<string, mixed> $envelope
     */
    private function rpc(array $envelope): Response
    {
        return (new Response())->status(200)->json($envelope);
    }

    /**
     * The empty 202 a JSON-RPC notification gets.
     */
    private function accepted(): Response
    {
        return (new Response())->status(202);
    }
}
