<?php

declare(strict_types=1);

namespace Phlix\Hub\Http;

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
        $request->body = $input !== false ? self::decodeJsonBody($input) : [];

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
     * @param WorkermanRequest $wr Workerman's HTTP request abstraction.
     *
     * @return self Populated request.
     *
     */
    public static function fromWorkerman(WorkermanRequest $wr): self
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
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (str_contains($contentType, 'application/json')) {
            $request->body = self::decodeJsonBody($rawBody);
        } else {
            $request->body = self::collectArrayFromWorkerman($wr->post());
        }

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
