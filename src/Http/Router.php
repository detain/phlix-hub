<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use BadMethodCallException;

/**
 * Lightweight regex-based HTTP router for `phlix-hub`.
 *
 * Supports `{param}` placeholders, callable handlers,
 * `[ControllerClass, 'method']` array handlers, and grouped routes
 * with shared middleware.
 *
 * CORS: `OPTIONS` preflights are answered by the {@see CorsPolicy} (default-deny,
 * env-configured) AFTER the matched path's route middleware chain has run —
 * an authed path answers nothing pre-auth — and dispatched responses are
 * decorated for allowlisted origins — see {@see self::dispatch()}.
 *
 * @package Phlix\Hub\Http
 */
class Router
{
    /**
     * @var array<string, array<string, array{
     *     handler: callable|array{0: class-string|object, 1: string},
     *     middleware: array<int, callable>,
     *     path: string
     * }>>
     */
    private array $routes = [];

    /** @var array<int, callable> */
    private array $groupMiddleware = [];

    private ?string $groupPrefix = null;

    private CorsPolicy $cors;

    /**
     * @param CorsPolicy|null $corsPolicy cross-origin policy; when omitted it is
     *                                    built from `PHLIX_CORS_ALLOW_ORIGINS`
     *                                    (default-deny). Construction-time reads
     *                                    the env once: resident workers pick the
     *                                    list up at boot, restart to change it.
     */
    public function __construct(?CorsPolicy $corsPolicy = null)
    {
        $this->cors = $corsPolicy ?? CorsPolicy::fromEnvironment();
    }

    /**
     * Register a GET route.
     *
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    public function get(string $path, callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    public function post(string $path, callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    public function put(string $path, callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    public function patch(string $path, callable|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    public function delete(string $path, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a HEAD route.
     *
     * ⚠ There is deliberately NO implicit HEAD→GET fallback in
     * {@see self::dispatch()}: a HEAD is only served where a handler was
     * registered for it explicitly, because the hub's buffered reply path would
     * otherwise return a BODY on a HEAD and desync a keep-alive client. A
     * handler registered here is responsible for suppressing the body — see
     * {@see Response::$headOnly} / {@see BodylessResponse}.
     *
     * S247: the relay proxy is the first (and so far only) caller — a media
     * player probes the direct-play byte stream with HEAD before it opens it.
     *
     * @param string                                            $path    Route path.
     * @param callable|array{0: class-string|object, 1: string} $handler Handler.
     *
     * @return self
     */
    public function head(string $path, callable|array $handler): self
    {
        return $this->addRoute('HEAD', $path, $handler);
    }

    /**
     * Internal helper to register a route under a single method.
     *
     * @param string                                                   $method  HTTP method.
     * @param string                                                   $path    Route path.
     * @param callable|array{0: class-string|object, 1: string}        $handler Handler.
     *
     * @return self
     */
    private function addRoute(string $method, string $path, callable|array $handler): self
    {
        $fullPath = ($this->groupPrefix !== null ? $this->groupPrefix : '') . $path;

        // `{name}` matches one path segment; `{name:regex}` lets a route opt into a
        // custom (possibly multi-segment) pattern, e.g. `{path:.*}` for an SPA shell
        // catch-all that must serve `/app/player/abc` and `/app/media/123`.
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_]+)(?::([^{}]+))?\}/',
            static function (array $m): string {
                // The optional `:regex` group is non-empty when matched ([^{}]+).
                $inner = isset($m[2]) ? $m[2] : '[^/]+';
                return '(?P<' . $m[1] . '>' . $inner . ')';
            },
            $fullPath
        );
        $pattern = '#^' . ($pattern ?? $fullPath) . '$#';

        $this->routes[$method][$pattern] = [
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'path' => $fullPath,
        ];

        return $this;
    }

    /**
     * Define a route group with shared prefix and optional middleware.
     *
     * The prefix/middleware restore runs in a `finally`, so a throw from inside
     * `$callback` cannot leave this router mid-group. It used to be able to, and
     * the consequence was a security one rather than a cosmetic one: the leaked
     * `groupMiddleware` is copied by {@see self::addRoute()} onto **every** route
     * registered afterwards. The live shape is `Application`'s route loaders —
     * groups such as `/api/v1` carry the auth gate, so one throw inside such a
     * group (a logger, a config read, a container miss) would silently attach
     * that gate to every route registered after it; on the server the incident
     * class was an allowlist middleware refusing every non-LAN caller for the
     * rest of the boot. The exception itself still propagates; only the
     * router's own bookkeeping is made unconditional.
     *
     * @param string             $prefix     Common prefix.
     * @param callable           $callback   Receives `$this`.
     * @param array<int, callable> $middleware Shared middleware.
     *
     * @return self
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        try {
            $callback($this);
        } finally {
            $this->groupPrefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }

        return $this;
    }

    /**
     * Dispatch a request to the matching route, or 404.
     *
     * CORS preflights (`OPTIONS`) take the {@see self::dispatchPreflight()}
     * path: the router matches the verbs registered for the path, applies the
     * default-deny origin gate, and — since review `hub__crossfe` M9r3 — runs
     * the matched routes' middleware chain BEFORE answering, so an authed
     * endpoint is never elicited by an unauthenticated probe. Every dispatched
     * response (handler result, middleware short-circuit, preflight challenge,
     * and the 404s) then passes through {@see CorsPolicy::decorate()} so the
     * actual cross-origin reply is readable too. Requests with no `Origin`
     * header see byte-identical responses to the pre-CORS router.
     *
     * @param Request $request Incoming request.
     *
     * @return Response Response from the handler or a 404 response.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'OPTIONS') {
            return $this->dispatchPreflight($request);
        }

        if (!isset($this->routes[$method])) {
            return $this->cors->decorate($request, $this->notFound());
        }

        foreach ($this->routes[$method] as $pattern => $route) {
            /** @var non-empty-string $pattern */
            if (preg_match($pattern, $path, $matches) === 1) {
                /** @var array<string, string> $params */
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                // S435 (ported): percent-decode once, here at the routing
                // boundary — after the match, before anyone downstream sees it.
                $params = $this->decodePathParams($params);
                $request->pathParams = $params;

                foreach ($route['middleware'] as $middleware) {
                    /** @var mixed $result */
                    $result = $middleware($request);
                    if ($result instanceof Response) {
                        return $this->cors->decorate($request, $result);
                    }
                }

                return $this->cors->decorate($request, $this->callHandler($route['handler'], $request, $params));
            }
        }

        return $this->cors->decorate($request, $this->notFound());
    }

    /**
     * Serve an OPTIONS request against the registered verbs for its path.
     *
     * Position law (review `hub__crossfe` M9r3 — cure of the 72707074
     * pre-AUTH oracle): the preflight answer sits BEHIND the matched path's
     * route middleware chain, not in front of it. The first cut short-circuited
     * `OPTIONS` before any middleware ran, so every authed endpoint answered
     * `204` + `Access-Control-Allow-Methods` to a credential-free probe (and
     * the no-`Origin` arm answered to any socket at all) — a pre-auth route-
     * and-verb enumeration surface plus an auth-free flood path. The arms now:
     *
     *  - unknown path → the 404 envelope (byte-identical deny — unchanged);
     *  - `Origin` present but not allowlisted → that same 404, WITHOUT running
     *    the chain (deny-before-work: a disallowed probe neither costs nor
     *    learns from the middleware);
     *  - the chain short-circuits — for authed routes this is the browser's
     *    normal outcome, since preflights carry no `Authorization` by spec and
     *    {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} therefore challenges
     *    exactly as it would for an unauthenticated GET (401 `auth.required`
     *    on `/api/`, login redirect otherwise; per the review's no-oracle
     *    adjudication the challenge discloses nothing beyond GET's own
     *    401-vs-404 existence tell, and the verb list never leaks pre-auth);
     *  - the chain passes — a public route, or a probe that really carries
     *    credentials the middleware accepts — gets the policy's preflight
     *    reply naming the registered verbs.
     *
     * Consequence, stated so nobody rediscovers it as a bug: cross-origin
     * browser access to authed hub REST is closed by construction (a failed
     * preflight blocks the actual request). Same-origin SPA traffic never
     * preflights and is untouched; the desktop clients keep their relay-WS
     * surfaces, which CORS does not govern.
     */
    private function dispatchPreflight(Request $request): Response
    {
        $matched = $this->matchRoutesForPath($request->path);
        if ($matched === []) {
            return $this->cors->decorate($request, $this->notFound());
        }

        $origin = $request->getHeader('Origin');
        if ($origin !== null && $origin !== '' && !$this->cors->allowsOrigin($origin)) {
            return $this->cors->decorate($request, $this->notFound());
        }

        // Mirror dispatch(): path params land on the request BEFORE its chain
        // runs, so middleware gated on `{param}` segments sees the same shape.
        // ($matched is non-empty here — the [] case returned above.)
        foreach ($matched as $match) {
            $request->pathParams = $match['params'];
            break;
        }

        foreach ($this->chainFor($matched) as $middleware) {
            /** @var mixed $result */
            $result = $middleware($request);
            if ($result instanceof Response) {
                return $this->cors->decorate($request, $result);
            }
        }

        return $this->cors->preflightResponse($request, array_keys($matched));
    }

    /**
     * Collect every route registered for `$path`, first pattern hit per verb,
     * with its captured params (excluding `OPTIONS` itself, which the policy
     * reports separately — and which no hub route ever registers).
     *
     * @return array<string, array{
     *     route: array{middleware: array<int, callable>, path: string},
     *     params: array<string, string>
     * }>
     *         verb (excluding `OPTIONS`) => matching route + captures
     */
    private function matchRoutesForPath(string $path): array
    {
        $matched = [];
        foreach ($this->routes as $verb => $patterns) {
            if ($verb === 'OPTIONS') {
                continue;
            }
            foreach ($patterns as $pattern => $route) {
                /** @var non-empty-string $pattern */
                if (preg_match($pattern, $path, $captures) === 1) {
                    /** @var array<string, string> $params */
                    $params = array_filter($captures, 'is_string', ARRAY_FILTER_USE_KEY);
                    // Same boundary law as dispatch(): these captures are
                    // published onto the request for the preflight chain
                    // (dispatchPreflight copies them into $request->pathParams),
                    // so a gate inspecting `{param}` segments must see the same
                    // decoded shape the eventual dispatched request will.
                    $params = $this->decodePathParams($params);
                    /** @var array{middleware: array<int, callable>, path: string} $shape */
                    $shape = ['middleware' => $route['middleware'], 'path' => $route['path']];
                    $matched[$verb] = ['route' => $shape, 'params' => $params];
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * The middleware a preflight must clear: the deduplicated union of the
     * chains of every verb registered for the path, in first-seen order.
     * Group middleware shared across verbs (the container resolves them as
     * singletons) run once. PHP strict comparison IS the identity test: the
     * same invokable object shared by reference dedupes; distinct instances of
     * the same class are distinct callables and each run (the hub's chains are
     * pure gates, so repeats are cheap and order-stable).
     *
     * @param array<string, array{
     *     route: array{middleware: array<int, callable>, path: string},
     *     params: array<string, string>
     * }> $matched
     *
     * @return list<callable>
     */
    private function chainFor(array $matched): array
    {
        $chain = [];
        foreach ($matched as $match) {
            foreach ($match['route']['middleware'] as $middleware) {
                if (!in_array($middleware, $chain, true)) {
                    $chain[] = $middleware;
                }
            }
        }

        return $chain;
    }

    /**
     * Invoke a route handler.
     *
     * @param callable|array{0: class-string|object, 1: string} $handler Handler.
     * @param Request                                            $request Request.
     * @param array<string, string>                              $params  Path params.
     *
     * @return Response
     *
     * @throws BadMethodCallException When handler signature is invalid.
     */
    private function callHandler(callable|array $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            /** @var mixed $rawClass */
            $rawClass = $handler[0] ?? null;
            /** @var mixed $rawMethod */
            $rawMethod = $handler[1] ?? null;
            if (!is_string($rawMethod)) {
                throw new BadMethodCallException('Array handler must have a string method name at index 1');
            }
            if (is_string($rawClass)) {
                if (!class_exists($rawClass)) {
                    throw new BadMethodCallException(sprintf('Handler class %s does not exist', $rawClass));
                }
                /**
                 * @psalm-suppress MixedMethodCall
                 * @var object $instance
                 */
                $instance = new $rawClass();
                $classLabel = $rawClass;
            } elseif (is_object($rawClass)) {
                $instance = $rawClass;
                $classLabel = $rawClass::class;
            } else {
                throw new BadMethodCallException('Array handler must have a class-string or object at index 0');
            }
            if (!method_exists($instance, $rawMethod)) {
                throw new BadMethodCallException(
                    sprintf('Handler %s::%s not found', $classLabel, $rawMethod)
                );
            }
            /** @var Response $response */
            $response = $instance->$rawMethod($request, $params);
            return $response;
        }

        /** @var Response $response */
        $response = $handler($request, $params);
        return $response;
    }

    /**
     * Percent-decodes extracted route parameters ONCE, at the routing boundary.
     *
     * Ported from the server's S435 (same shared-router lineage): the wire form
     * of a path segment is percent-encoded (a client asks for
     * `/api/v1/servers/Abbey%20Road`), but handlers consume NAMES — handing the
     * still-encoded segment to a lookup silently 404s every name with a space,
     * accent or unicode character.
     *
     * Decoding happens HERE — after pattern matching, before the handler sees a
     * value — and nowhere else, because:
     *  - routing itself must keep matching the RAW path: decoding earlier would
     *    turn an encoded `%2F` into a segment separator (forging routes) and
     *    would hand upstream traversal guards decoded `..` they never vetted;
     *  - handlers that decoded again after this boundary would double-decode —
     *    a literal `%20` inside a real name (`%2520` on the wire) would collapse
     *    into a space and resolve the WRONG row. One decode, exactly here.
     *
     * Policy decisions, pinned by `RouterPathParamDecodingTest`:
     *  - `rawurldecode()`, NOT `urldecode()`: in a PATH segment `+` is a literal
     *    plus (RFC 3986 sub-delim); `+`-means-space is the legacy
     *    `application/x-www-form-urlencoded` form of QUERY strings only. Using
     *    `urldecode()` here would silently rewrite a name like `Black+Sabbath`.
     *  - Malformed sequences (`%ZZ`, a trailing `%`) are left verbatim:
     *    `rawurldecode()` never throws and never corrupts them, so the handler
     *    receives the closest faithful reading and answers 404 — the honest
     *    outcome for a name nobody can have registered.
     *
     * @param array<string, string> $params Raw captured segments, keyed by placeholder name
     *
     * @return array<string, string> The same params, each value decoded exactly once
     */
    private function decodePathParams(array $params): array
    {
        $decoded = [];
        foreach ($params as $name => $value) {
            $decoded[$name] = rawurldecode($value);
        }
        return $decoded;
    }

    /**
     * 404 response builder — the unified member-facing envelope minted in
     * @phlix/contracts v0.5.5 (R-HUB-W2-C1): the registered machine word
     * `not_found` on `code`, and Router's own human sentence co-mirrored in
     * `error` and `message` (the pinned `extractError` read order is
     * error-then-message, so both keys carry the same sentence). The bare
     * HTTP phrase is off the member channel per the ban list enforced by
     * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\MemberErrorProseTest}.
     * Key order (error, code, message) is the canonical envelope shape,
     * byte-pinned by RouterTest::testUnmatchedRouteEmitsTheUnifiedNotFoundEnvelope.
     */
    private function notFound(): Response
    {
        return (new Response())
            ->status(404)
            ->json([
                'error' => 'The requested resource was not found',
                'code' => 'not_found',
                'message' => 'The requested resource was not found',
            ]);
    }

    /**
     * Get all registered routes (test inspection helper).
     *
     * @return array<string, array<string, array{
     *     handler: callable|array{0: class-string|object, 1: string},
     *     middleware: array<int, callable>,
     *     path: string
     * }>>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
