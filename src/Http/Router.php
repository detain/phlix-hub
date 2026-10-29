<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
 * env-configured) before middleware runs, and dispatched responses are decorated
 * for allowlisted origins — see {@see self::dispatch()}.
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

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Dispatch a request to the matching route, or 404.
     *
     * CORS preflights (`OPTIONS`) never carry `Authorization` — by spec the
     * browser strips it — so they short-circuit here BEFORE any route
     * middleware runs, and an allowlisted preflight can never be rejected by
     * an auth gate that was never designed to see the browser's probe. Every
     * dispatched response (handler result, middleware short-circuit, and the
     * 404s) then passes through {@see CorsPolicy::decorate()} so the actual
     * cross-origin reply is readable too. Requests with no `Origin` header
     * see byte-identical responses to the pre-CORS router.
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
     * Deny is silent by design: a preflight from an origin that is not
     * allowlisted gets the exact same 404 envelope as a preflight for a path
     * the hub does not know, so a probe from a disallowed website cannot map
     * the hub's route table (the collections law — deny must not become an
     * oracle). An OPTIONS with no `Origin` header is not a browser preflight
     * at all — no fetch can send one without it — so it gets a truthful
     * body-less 204 naming the verbs this path answers.
     */
    private function dispatchPreflight(Request $request): Response
    {
        $verbs = $this->verbsForPath($request->path);
        if ($verbs === []) {
            return $this->cors->decorate($request, $this->notFound());
        }

        $origin = $request->getHeader('Origin');
        if ($origin !== null && $origin !== '' && !$this->cors->allowsOrigin($origin)) {
            return $this->cors->decorate($request, $this->notFound());
        }

        return $this->cors->preflightResponse($request, $verbs);
    }

    /**
     * Collect the HTTP verbs with a registered route matching `$path`
     * (excluding `OPTIONS` itself, which the policy reports separately).
     *
     * @return list<string>
     */
    private function verbsForPath(string $path): array
    {
        $verbs = [];
        foreach ($this->routes as $verb => $patterns) {
            if ($verb === 'OPTIONS') {
                continue;
            }
            foreach ($patterns as $pattern => $route) {
                /** @var non-empty-string $pattern */
                if (preg_match($pattern, $path) === 1) {
                    $verbs[] = $verb;
                    break;
                }
            }
        }

        return $verbs;
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
     * 404 response builder.
     */
    private function notFound(): Response
    {
        return (new Response())
            ->status(404)
            ->json([
                'error' => 'Not Found',
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
