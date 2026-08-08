<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use Phlix\Hub\Common\Http\TrustedProxyResolver;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Lightweight HTTP request DTO used by the hub router.
 *
 * Two constructors are exposed:
 *
 * - {@see Request::fromGlobals()} — populated from PHP superglobals,
 *   used by traditional FPM contexts and tests.
 * - {@see Request::fromWorkerman()} — populated from a Workerman
 *   `Workerman\Protocols\Http\Request`, used by the live worker.
 *
 * The shape mirrors `phlix-server`'s `Phlix\Server\Http\Request` so the
 * codebases stay symmetrical.
 *
 * @package Phlix\Hub\Http
 */
class Request
{
    public string $method = 'GET';

    public string $path = '/';

    public string $queryString = '';

    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<string, mixed> */
    public array $query = [];

    /** @var array<string, mixed> */
    public array $body = [];

    /**
     * The undecoded request body exactly as it arrived.
     *
     * {@see $body} is a lossy view of it: {@see decodeJsonBody()} returns `[]`
     * for a payload that is empty, malformed, or a JSON ARRAY rather than an
     * object, and drops non-string keys. Three very different inputs therefore
     * collapse to the same `[]`, which is fine for form-shaped controllers and
     * useless for a JSON-RPC endpoint that must answer "parse error" and
     * "invalid request" differently
     * ({@see \Phlix\Hub\Http\Controllers\McpController}).
     *
     * Always a string: `''` when no body was sent, and `''` for a request built
     * by hand (tests, sub-requests) rather than from the wire.
     */
    public string $rawBody = '';

    /** @var array<string, mixed> */
    public array $files = [];

    public string $remoteIp = '0.0.0.0';

    public int $remotePort = 0;

    public string $protocol = 'HTTP/1.1';

    public ?string $bearerToken = null;

    public ?string $userId = null;

    /**
     * Hydrated user row (sans password_hash) populated by
     * {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}. Null until the
     * middleware runs.
     *
     * @var array<string, mixed>|null
     */
    public ?array $user = null;

    /**
     * Decoded JWT claims populated by
     * {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}. Null until the
     * middleware runs.
     */
    public ?\Phlix\Shared\Auth\JwtClaims $claims = null;

    /**
     * The OAuth 2.0 grant a `phlix-oat-…` access token resolved to, populated by
     * {@see \Phlix\Hub\Http\Middleware\OAuthResourceMiddleware} (S286). Null on
     * every request that did not authenticate with an OAuth access token —
     * including every session-JWT request, which carries no scopes at all.
     *
     * ⚠ A controller behind that middleware must read its capabilities from
     * HERE and never from {@see $claims}: the two credentials mean different
     * things, and a controller that fell back to the session claims when the
     * grant was null would serve an unscoped credential on a scoped surface.
     */
    public ?\Phlix\Hub\OAuth\OAuthGrant $oauthGrant = null;

    /** @var array<string, string> */
    public array $pathParams = [];

    /**
     * Server UUID set by {@see \Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware}
     * when a server-facing route with enrollment JWT auth is dispatched.
     */
    public ?string $serverId = null;

    /**
     * Creates a request from PHP global variables.
     *
     * @return self Populated request.
     *
     */
    public static function fromGlobals(): self
    {
        $request = new self();

        $request->method = self::stringFromServer('REQUEST_METHOD', 'GET');
        $uri = self::stringFromServer('REQUEST_URI', '/');
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $request->path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
        $parsedQuery = parse_url($uri, PHP_URL_QUERY);
        $request->queryString = is_string($parsedQuery) ? $parsedQuery : '';

        $request->headers = self::parseHeadersFromServer();
        /** @var array<string, mixed> $query */
        $query = $_GET;
        $request->query = $query;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        $request->files = $files;

        $input = file_get_contents('php://input');
        $request->rawBody = $input !== false ? $input : '';
        $request->body = self::decodeJsonBody($request->rawBody);

        $request->remoteIp = self::stringFromServer('REMOTE_ADDR', '0.0.0.0');
        $portValue = $_SERVER['REMOTE_PORT'] ?? 0;
        $request->remotePort = is_numeric($portValue) ? (int) $portValue : 0;
        $request->protocol = self::stringFromServer('SERVER_PROTOCOL', 'HTTP/1.1');
        $request->bearerToken = $request->extractBearerToken();

        return $request;
    }

    /**
     * Read a string value from `$_SERVER`, falling back to `$default`.
     */
    private static function stringFromServer(string $key, string $default): string
    {
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        $value = $_SERVER[$key];
        return is_string($value) ? $value : $default;
    }

    /**
     * Creates a request from a Workerman HTTP request.
     *
     * Optionally accepts the {@see TcpConnection} so `remoteIp` / `remotePort`
     * are populated from the direct TCP peer — the live worker passes it so the
     * trusted-proxy-aware {@see getTrustedClientIp()} resolution has a real peer
     * address to reason about (without it, `remoteIp` stays the `'0.0.0.0'`
     * default and every IP-keyed rate limiter collapses into one bucket).
     *
     * @param WorkermanRequest      $wr   Workerman's HTTP request abstraction.
     * @param TcpConnection|null    $conn The connection the request arrived on,
     *                                    or null (e.g. tests / detached parsing).
     *
     * @return self Populated request.
     *
     */
    public static function fromWorkerman(WorkermanRequest $wr, ?TcpConnection $conn = null): self
    {
        $request = new self();
        $request->method = $wr->method();
        $request->path = $wr->path();
        $queryString = parse_url($wr->uri(), PHP_URL_QUERY);
        $request->queryString = is_string($queryString) ? $queryString : '';

        $request->headers = self::collectHeadersFromWorkerman($wr);
        $request->query = self::collectArrayFromWorkerman($wr->get());
        $request->files = self::collectArrayFromWorkerman($wr->file());

        $rawBody = $wr->rawBody();
        $request->rawBody = $rawBody;
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (str_contains($contentType, 'application/json')) {
            $request->body = self::decodeJsonBody($rawBody);
        } else {
            $request->body = self::collectArrayFromWorkerman($wr->post());
        }

        $request->remoteIp = $conn?->getRemoteIp() ?? '0.0.0.0';
        $request->remotePort = $conn?->getRemotePort() ?? 0;
        $request->bearerToken = $request->extractBearerToken();

        return $request;
    }

    /**
     * Parse HTTP headers from `$_SERVER`.
     *
     * @return array<string, string>
     */
    private static function parseHeadersFromServer(): array
    {
        $headers = [];
        /** @var mixed $value */
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        /** @var mixed $contentType */
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        if (is_string($contentType)) {
            $headers['CONTENT-TYPE'] = $contentType;
        }
        /** @var mixed $contentLength */
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength)) {
            $headers['CONTENT-LENGTH'] = $contentLength;
        }
        return $headers;
    }

    /**
     * Coerce Workerman `header()` output into a `string`-keyed string map.
     *
     * @return array<string, string>
     */
    private static function collectHeadersFromWorkerman(WorkermanRequest $wr): array
    {
        $headers = [];
        $rawHeaders = $wr->header();
        if (!is_array($rawHeaders)) {
            return $headers;
        }
        /**
         * @var mixed $value
         */
        foreach ($rawHeaders as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $headers[strtoupper($key)] = $value;
            }
        }
        return $headers;
    }

    /**
     * Coerce a Workerman accessor return value into a `string`-keyed
     * mixed map. Non-array inputs collapse to an empty array.
     *
     * @param mixed $raw The raw Workerman accessor return.
     *
     * @return array<string, mixed>
     */
    private static function collectArrayFromWorkerman(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Decode a JSON request body to an array, returning an empty array
     * when the payload is empty or malformed.
     *
     * @param string $raw Raw body bytes.
     *
     * @return array<string, mixed>
     */
    private static function decodeJsonBody(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Get a header by name (case-insensitive).
     *
     * @param string $name Header name.
     *
     * @return string|null Header value or null when missing.
     */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Resolve the REAL client IP for security-sensitive keys (rate limiting) in a
     * trusted-proxy-aware way (mirrors the server's SV-4.15 fix).
     *
     * Delegates to {@see TrustedProxyResolver}, which walks `X-Forwarded-For`
     * RIGHT-TO-LEFT past trusted-proxy hops (default: loopback, matching the
     * shipped HAProxy front) and returns the first untrusted address — so a
     * forged leftmost XFF value can no longer mint a fresh rate-limit bucket, and
     * the loopback proxy peer no longer collapses every client into one bucket.
     * The returned value is always a validated IP (≤45 chars).
     *
     * @param TrustedProxyResolver|null $resolver Optional resolver (inject a
     *        configured one in tests); defaults to the `TRUSTED_PROXIES`-env one.
     *
     * @return string The trusted client IP.
     */
    public function getTrustedClientIp(?TrustedProxyResolver $resolver = null): string
    {
        $resolver ??= new TrustedProxyResolver();
        return $resolver->resolve(
            $this->remoteIp,
            $this->getHeader('X-Forwarded-For'),
            $this->getHeader('X-Real-IP'),
        );
    }

    /**
     * Extract the Bearer token from the Authorization header.
     */
    private function extractBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
