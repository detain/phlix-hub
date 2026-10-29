<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use InvalidArgumentException;

/**
 * Default-deny Cross-Origin Resource Sharing (CORS) policy for the hub's REST surface.
 *
 * Review `hub__crossfe` MAJOR-1: the hub vendors `@phlix/ui`, whose bundle the
 * Windows client runs in an Electron renderer at origin `app://-` against an
 * absolute `https://` hub base — a cross-origin context in which Chromium
 * enforces CORS on every `Authorization`-bearing or JSON-body call. Until this
 * class existed the hub answered no `OPTIONS` preflight at all (the router keys
 * routes by method and nothing registers `OPTIONS`), so every hub-mode REST
 * read died at the preflight while the CORS-exempt relay WebSockets kept
 * working — a half-alive platform mode.
 *
 * ## The policy this ships
 *
 * - **Default-deny.** With `PHLIX_CORS_ALLOW_ORIGINS` unset the allowlist is
 *   empty and no cross-origin request is ever granted `Access-Control-Allow-Origin`.
 *   Operators opt in by listing origins, comma-separated:
 *   `PHLIX_CORS_ALLOW_ORIGINS=https://hub.example.com,app://-`.
 *   `app://-` is the origin desktop (Electron) hub-mode consumers present;
 *   include it when the hub serves the packaged Windows client. Nothing is
 *   allowlisted by default — the hub does not guess who may call it.
 * - **Never a wildcard, never credentials.** Only the exact requesting origin
 *   is echoed back, and `Access-Control-Allow-Credentials` is never emitted,
 *   so a browser-side cross-origin read can never ride a hub session cookie.
 *   Hub REST authenticates with bearer tokens, which CORS does not govern.
 * - **Boot-scoped.** Resident Workerman workers read the environment once, at
 *   router construction (`src/Application.php`), so changing the list requires
 *   a hub restart — like every other env-driven knob on this surface.
 * - **Mirror, don't invent.** A preflight echoes the requested headers
 *   (`Access-Control-Request-Headers` → `Access-Control-Allow-Headers`) and
 *   reports exactly the verbs the router has registered for that path.
 * - **`Vary: Origin` correctness.** Every response that a request with an
 *   `Origin` header could see carries `Vary: Origin`, merged onto any existing
 *   `Vary`, so an intermediary can never serve a cached allow-response to a
 *   different origin. A request with no `Origin` header (same-origin traffic,
 *   CLI, health probes) leaves every response byte-identical to the
 *   pre-CORS hub.
 *
 * @package Phlix\Hub\Http
 */
final class CorsPolicy
{
    /**
     * Environment variable naming the comma-separated origin allowlist.
     * Unset or empty means deny-all — the shipped default.
     */
    public const ENV_ALLOW_ORIGINS = 'PHLIX_CORS_ALLOW_ORIGINS';

    /** Seconds a browser may cache a successful preflight (Chromium honors at most this). */
    public const MAX_AGE_SECONDS = 600;

    /**
     * An origin is `scheme://host[:port]` with no path, no whitespace, no
     * comma (the list delimiter), no `*` (a wildcard grant is exactly what
     * this policy refuses) — and lowercase, because browsers serialize
     * scheme and host lowercase and an allowlist entry that never matches the
     * wire is a misconfiguration, not a style choice. Enforcing it here turns
     * a silent deny-at-runtime into a loud boot failure.
     */
    private const ORIGIN_PATTERN = '/^[a-z][a-z0-9+.\-]*:\/\/[^\/?#\s,*A-Z]+$/';

    /** @var array<string, true> lowercased exact-match set; empty means deny-all */
    private array $allowedOrigins;

    /**
     * @param list<string> $allowList origins to trust, exact match.
     *
     * @throws InvalidArgumentException when any non-blank entry is not a
     *                                  well-formed lowercase origin.
     */
    public function __construct(array $allowList)
    {
        $allowed = [];
        foreach ($allowList as $entry) {
            $origin = trim($entry);
            if ($origin === '') {
                continue;
            }
            if (preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid CORS allow-origin entry "%s": expected a lowercase origin like ' .
                        'https://hub.example.com or app://- with no path, wildcard, or space',
                        $origin
                    ),
                );
            }
            $allowed[$origin] = true;
        }

        $this->allowedOrigins = $allowed;
    }

    /**
     * Build the policy from `PHLIX_CORS_ALLOW_ORIGINS`.
     *
     * Unset or empty env yields the default-deny policy; a malformed entry
     * throws at construction, i.e. at worker boot — fail loud at deploy time
     * rather than silently dropping to deny-everything on a typo.
     */
    public static function fromEnvironment(): self
    {
        $raw = getenv(self::ENV_ALLOW_ORIGINS);
        if (!is_string($raw) || trim($raw) === '') {
            return new self([]);
        }

        return new self(explode(',', $raw));
    }

    public function allowsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '' || $this->allowedOrigins === []) {
            return false;
        }

        return isset($this->allowedOrigins[$origin]);
    }

    /**
     * Build the successful-preflight reply for a path the router knows.
     *
     * 204 No Content — a preflight carries no body, and none is invented.
     * The caller (Router) has already established the path exists; the origin
     * gate lives here so a disallowed origin never even sees this shape.
     *
     * @param Request       $request the OPTIONS preflight
     * @param list<string>  $verbs   HTTP verbs registered for the path
     *
     * @return Response
     */
    public function preflightResponse(Request $request, array $verbs): Response
    {
        $methods = $verbs;
        $methods[] = 'OPTIONS';
        sort($methods);

        $response = (new Response())
            ->status(204)
            ->header('Access-Control-Allow-Methods', implode(', ', $methods))
            ->header('Access-Control-Max-Age', (string) self::MAX_AGE_SECONDS)
            ->header('Vary', 'Origin');

        $origin = $request->getHeader('Origin');
        if ($this->allowsOrigin($origin)) {
            $response = $response->header('Access-Control-Allow-Origin', (string) $origin);

            $requestedHeaders = $request->getHeader('Access-Control-Request-Headers');
            if ($requestedHeaders !== null && trim($requestedHeaders) !== '') {
                $response = $response->header('Access-Control-Allow-Headers', $requestedHeaders);
            }
        }

        return $response;
    }

    /**
     * Decorate any dispatched (non-preflight) response for cross-origin reads.
     *
     * A preflight alone is not enough: the browser re-checks the ACTUAL
     * response for `Access-Control-Allow-Origin`, so GET/POST replies to an
     * allowlisted origin must carry it too — including error replies, or the
     * caller sees an opaque network failure instead of the hub's 401/404.
     *
     * Requests with no `Origin` header (same-origin browsers, curl, internal
     * probes) pass through completely untouched.
     */
    public function decorate(Request $request, Response $response): Response
    {
        $origin = $request->getHeader('Origin');
        if ($origin === null || $origin === '') {
            return $response;
        }

        $this->appendVaryOrigin($response);

        if ($this->allowsOrigin($origin) && $this->findHeader($response, 'Access-Control-Allow-Origin') === null) {
            $response = $response->header('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    /**
     * Add `Origin` to the response's `Vary` list without clobbering entries
     * a handler already set (case-insensitive, deduplicated).
     */
    private function appendVaryOrigin(Response $response): void
    {
        $existing = $this->findHeader($response, 'Vary');
        if ($existing === null) {
            $response->header('Vary', 'Origin');
            return;
        }

        [$name, $value] = $existing;
        $parts = array_map('trim', explode(',', $value));
        foreach ($parts as $part) {
            if (strcasecmp($part, 'Origin') === 0) {
                return;
            }
        }

        $response->header($name, $value . ', Origin');
    }

    /**
     * Case-insensitive header lookup over the response's raw header map.
     *
     * @return array{0: string, 1: string}|null [name-as-set, value] or null
     */
    private function findHeader(Response $response, string $name): ?array
    {
        foreach ($response->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return [$key, $value];
            }
        }

        return null;
    }
}
