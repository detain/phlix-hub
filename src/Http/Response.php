<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Fluent HTTP response builder.
 *
 * Mirrors the public surface of `phlix-server`'s
 * `Phlix\Server\Http\Response` so the two repos can share idioms.
 * {@see Response::toWorkermanResponse()} converts the builder into the
 * Workerman response object the worker sends down the socket.
 *
 * @package Phlix\Hub\Http
 */
class Response
{
    /**
     * HSTS value emitted on https-scheme responses (HUB-4).
     *
     * Static mirror of the phlix-server policy default: the server composes
     * the identical literal from `SecurityHeadersPolicy::DEFAULT_HSTS_MAX_AGE`
     * (one year) plus its non-configurable `includeSubDomains` limb
     * (`src/Server/Http/Middleware/SecurityHeaders.php`). The server additionally
     * re-reads `security.hsts_max_age_seconds` live behind a 60s memo — that
     * settings coupling is server-specific; the hub header surface has none, so
     * the default is frozen as a constant here. Folding the two hosts onto one
     * canonical (shared library) is the wave-3 extraction, deliberately not
     * this lane's stopgap.
     */
    public const string STRICT_TRANSPORT_SECURITY_VALUE = 'max-age=31536000; includeSubDomains';

    public int $statusCode = 200;

    /** @var array<string, string> */
    public array $headers = [];

    /**
     * Cookies queued for emission as `Set-Cookie` headers when this
     * response is converted with {@see self::toWorkermanResponse()}.
     *
     * @var list<array{name:string,value:string,max_age:int,path:string,http_only:bool,secure:bool,same_site:string}>
     */
    public array $cookies = [];

    public string $body = '';

    /**
     * True when this response answers a `HEAD` and must therefore be rendered
     * with NO body while keeping the `Content-Length` a `GET` would have
     * returned (RFC 9110 §9.3.2).
     *
     * Selects {@see BodylessResponse} in {@see self::toWorkermanResponse()}.
     * Deliberately NOT inferred from `$body === ''`: a GET with an empty body
     * and a stale non-zero `Content-Length` is a keep-alive framing desync, not
     * a HEAD — see {@see BodylessResponse} for why the selector has to be
     * explicit.
     */
    public bool $headOnly = false;

    /**
     * Optional incremental-streaming producer.
     *
     * When set, the HTTP worker invokes it with the live browser
     * {@see TcpConnection} to write the response body directly to the socket in
     * fragments, instead of sending the buffered {@see self::$body}. The relay
     * proxy uses this to pass a large media body (HLS/DASH segment, direct-play
     * stream) straight through without buffering the whole body on the hub. The
     * producer owns the entire on-the-wire response once invoked (status line,
     * headers, body, terminator).
     */
    private ?\Closure $streamProducer = null;

    /**
     * Mark this response as an incrementally-streamed response.
     *
     * @param callable(TcpConnection): void $producer Writes the response to the
     *        browser connection fragment-by-fragment.
     *
     * @return self
     */
    public function stream(callable $producer): self
    {
        // Review hub Major 33: the property is no longer a public untyped
        // slot on the object that doubles as the wire response envelope —
        // only this setter moves it, and the stored shape is a real Closure
        // regardless of what callable was handed in. Closure::fromCallable
        // passes an existing Closure through by identity, so this single call
        // IS the whole normalization — no instanceof fork needed (and psalm
        // rightly rejects claiming a `callable` docblock type "contains"
        // Closure before the check narrows it).
        $this->streamProducer = \Closure::fromCallable($producer);
        return $this;
    }

    /**
     * The live streaming producer, or null when this response takes the
     * buffered path. Read-only view for the HTTP worker and admission gates;
     * the only writer is {@see self::stream()}.
     */
    public function getStreamProducer(): ?\Closure
    {
        return $this->streamProducer;
    }

    /**
     * Set the HTTP status code.
     *
     * @param int $code Status code.
     *
     * @return self
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Add a response header.
     *
     * @param string $name  Header name.
     * @param string $value Header value.
     *
     * @return self
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Is a header already present, regardless of the spelling it was set with?
     *
     * HTTP field names are case-insensitive (RFC 9110 §5.1), but `$headers`
     * is a plain PHP array whose keys are case-SENSITIVE — a caller writing
     * `header('cache-control', ...)` would otherwise slip past a bare
     * `isset($this->headers['Cache-Control'])` check and get a DUPLICATE
     * header line on the wire. This is the phlix-server Response twin of the
     * same private guard (A-03: the two repos' Cache-Control seed checks
     * drifted exactly here; the hub now shares the server's case-insensitive
     * law).
     */
    private function hasHeader(string $name): bool
    {
        $needle = strtolower($name);
        foreach (array_keys($this->headers) as $key) {
            if (strtolower((string) $key) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * JSON-encode `$data` as the response body and set Content-Type.
     *
     * The encode is COMPACT (minors campaign M-HUB-09): the hub's REST wire
     * is a machine contract consumed by @phlix/ui, the mobile/console/roku
     * clients and the MCP chain — no consumer parses whitespace, and
     * pretty-printing ships needless indentation bytes on every payload.
     * Read a live response with `curl ... | jq` when you want indentation.
     * `error()` delegates here, so error frames follow the same law; the
     * relay-frame helper `errorBody()` was already compact and is untouched.
     *
     * @param array<string, mixed> $data       Data to encode.
     * @param int|null             $statusCode Optional status override.
     *
     * @return self
     *
     * @throws \JsonException If encoding fails.
     */
    public function json(array $data, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'application/json';
        // Review hub Minor 48: hub JSON is session-scoped or
        // per-user data, but before this the responses carried NO freshness
        // directive at all — browsers then cache them HEURISTICALLY (a 401 or
        // a member list surviving a logout/re-login is the observable harm).
        // `no-store` is the honest default for data responses (unlike the SPA
        // shell's `no-cache`, revalidation itself needs an identity here).
        // Like html() above this only SEEDS a default: a caller that set its
        // own Cache-Control beforehand keeps it (e.g. HubJwksController's
        // `public, max-age=3600`), and one setting it afterwards overwrites
        // via header(). Mirrors the phlix-server twin landed the same day.
        if (! $this->hasHeader('Cache-Control')) {
            $this->headers['Cache-Control'] = 'no-store';
        }
        $this->body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this;
    }

    /**
     * Build a standard error frame: `{error, code}` (+ any `$extra` keys).
     *
     * The single shared home for the hub's JSON error envelope (promoted from
     * the private `errorBody()` helpers in the relay classes). `$code` MUST be
     * a stable machine code registered in the `@phlix/contracts` error-code
     * vocabulary (`src/errors.ts` / `dist/error-codes.json`) — enforced on the
     * wire literals by {@see \Phlix\Hub\Tests\Unit\Contracts\ErrorCodesContractTest}.
     * `$message` is the human/debug text that rides the `error` field.
     *
     * @param int                  $status  HTTP status code.
     * @param string               $code    Registered stable machine code.
     * @param string               $message Human-readable error text.
     * @param array<string, mixed> $extra   Additional top-level keys (e.g. a
     *                                      legacy `message` field) merged after
     *                                      `error`/`code`.
     *
     * @return self
     *
     * @throws \JsonException If encoding fails.
     */
    public function error(int $status, string $code, string $message, array $extra = []): self
    {
        return $this->json(['error' => $message, 'code' => $code] + $extra, $status);
    }

    /**
     * Encode a compact (non-pretty) relay error body.
     *
     * Byte-compatible with the historical `RelayProxyManager::errorBody()` /
     * `RelayProxyBridge::errorBody()` output — these bodies travel inside
     * relay frames, not HTTP responses, so they stay compact JSON. Falls back
     * to a fixed minimal body if encoding ever throws.
     *
     * @param string $code    Registered stable machine code.
     * @param string $message Human-readable error text.
     *
     * @return string JSON `{"error":...,"code":...}`.
     */
    public static function errorBody(string $code, string $message): string
    {
        try {
            return json_encode(['error' => $message, 'code' => $code], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{"error":"relay error"}';
        }
    }

    /**
     * Canonical `{error, code}` envelope body for a tripped limiter (M-16).
     *
     * W4 PREP: the single home of the two literals the hub once hand-rolled at
     * five dispatch/catch sites ({@see \Phlix\Hub\Application::rateLimitResponse()},
     * ServerController heartbeat catch, AlexaSignatureMiddleware, and the two
     * auth throttles' additive 3-key frames). Byte-identical to the server's
     * twin seam (A-04 / M-16): the W3 shared `Envelope` adoption then becomes a
     * mechanical swap of this method's body.
     *
     * @return array<string, string>
     */
    private static function rateLimitedBody(): array
    {
        return ['error' => 'Too Many Requests', 'code' => 'rate_limited'];
    }

    /**
     * The single `Retry-After` formatter for limiter replies.
     *
     * The exception/state layers already clamp the value non-negative; the
     * wire law (RFC 9110 §10.2.3 delta-seconds) is a plain integer string.
     */
    private static function retryAfterHeader(int $retryAfterSeconds): string
    {
        return (string) $retryAfterSeconds;
    }

    /**
     * The canonical HTTP 429 envelope: status + `Retry-After` + `{error, code}`.
     *
     * `Retry-After` rides BEFORE the JSON encode so the header order on the
     * wire stays `Retry-After`, `Content-Type`, `Cache-Control` — the shape the
     * corpus pins ({@see \Phlix\Hub\Tests\Unit\Http\EnvelopeWireCorpusTest}).
     * `$extra` merges additive keys after `error`/`code` (left-biased union,
     * exactly like {@see self::error()}) — the auth throttles use it for their
     * `message` sentence (HUB-19) without re-hand-rolling the frame.
     *
     * @param int                  $retryAfterSeconds Seconds until the window resets.
     * @param array<string, mixed> $extra             Additive top-level keys.
     *
     * @return self
     *
     * @throws \JsonException If encoding fails.
     */
    public static function rateLimited(int $retryAfterSeconds, array $extra = []): self
    {
        $response = (new self())
            ->status(429)
            ->header('Retry-After', self::retryAfterHeader($retryAfterSeconds));

        return $response->json(self::rateLimitedBody() + $extra);
    }

    /**
     * HTML response shortcut.
     *
     * @param string   $html       HTML body.
     * @param int|null $statusCode Optional status override.
     *
     * @return self
     */
    public function html(string $html, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        // Dynamic HTML (the SPA shell in particular) must revalidate on every
        // load: without a Cache-Control header browsers cache it heuristically
        // and keep referencing old hashed asset chunks after a deploy (stale UI
        // until a hard refresh). The hashed assets themselves stay immutable
        // (see the serving layer). This only seeds a default — a caller that set
        // its own Cache-Control beforehand keeps its value, and one that sets it
        // afterwards overwrites this via header(). Mirrors phlix-server Response.
        if (! $this->hasHeader('Cache-Control')) {
            $this->headers['Cache-Control'] = 'no-cache';
        }
        $this->body = $html;
        return $this;
    }

    /**
     * Plain text response shortcut.
     *
     * @param string   $text       Text body.
     * @param int|null $statusCode Optional status override.
     *
     * @return self
     */
    public function text(string $text, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'text/plain; charset=utf-8';
        $this->body = $text;
        return $this;
    }

    /**
     * Queue a cookie to be emitted on this response.
     *
     * @param string $name     Cookie name.
     * @param string $value    Cookie value (empty string + max_age=0 to clear).
     * @param int    $maxAge   Lifetime in seconds.
     * @param string $path     Path scope. Default "/".
     * @param bool      $httpOnly HttpOnly flag. Default true.
     * @param bool|null $secure   Secure flag. When null (the default) the
     *                            cookie is emitted with `Secure` unless the
     *                            `HUB_COOKIE_INSECURE=1` env override is set
     *                            (local plain-HTTP dev only). Pass an explicit
     *                            bool to force the flag regardless of env.
     * @param string    $sameSite SameSite policy ("Strict"/"Lax"/"None"). Default "Lax".
     *
     * @return self
     */
    public function cookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        bool $httpOnly = true,
        ?bool $secure = null,
        string $sameSite = 'Lax',
    ): self {
        $this->cookies[] = [
            'name'      => $name,
            'value'     => $value,
            'max_age'   => $maxAge,
            'path'      => $path,
            'http_only' => $httpOnly,
            'secure'    => $secure ?? self::secureCookiesDefault(),
            'same_site' => $sameSite,
        ];
        return $this;
    }

    /**
     * Resolve the default `Secure` cookie flag.
     *
     * Cookies are emitted with `Secure` by default (the hub is served over
     * HTTPS in every real deployment). The single escape hatch is the
     * `HUB_COOKIE_INSECURE=1` environment variable, intended ONLY for local
     * plain-HTTP development where the browser would otherwise refuse to
     * store a `Secure` cookie over `http://`.
     *
     * HUB-ENVBOOL census row — do-NOT-merge ruling (brief-mandated posture):
     * this is a ratified-security surface, and its strict exact-`'1'`/`'true'`
     * spelling is the LAW. The estate EnvConfig::bool grammar would widen the
     * accept set (yes/on/case-fold/padded) on a knob that DROPS the Secure
     * attribute — a loosening, which convergence may never do (server E-04's
     * own rule). The two readers of this knob (here and
     * CookiePolicy::insecureOverride()) share exactly this strict vocabulary
     * BY DESIGN; migrating one alone would split their answers for the same
     * spelling, which is worse than two local copies of one law. Any future
     * change here must move both at once.
     */
    private static function secureCookiesDefault(): bool
    {
        $override = getenv('HUB_COOKIE_INSECURE');
        if ($override === '1' || $override === 'true') {
            return false;
        }
        return true;
    }

    /**
     * 302-redirect shortcut.
     */
    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Location'] = $url;
        return $this;
    }

    /**
     * Mark this response as a `HEAD` reply: no body on the wire, and the
     * `Content-Length` already on `$headers` is authoritative.
     *
     * @param bool $headOnly Whether the response answers a HEAD.
     *
     * @return self
     */
    public function headOnly(bool $headOnly = true): self
    {
        $this->headOnly = $headOnly;
        return $this;
    }

    /**
     * Convert this builder into a Workerman response object.
     *
     * A `HEAD` reply ({@see self::$headOnly}) is rendered by
     * {@see BodylessResponse} so the relayed server's real `Content-Length`
     * survives instead of being overwritten by Workerman's generated
     * `Content-Length: 0`. The selector is narrowed twice — here on the explicit
     * flag, and again inside that class on the exact response shape — so no
     * ordinary response changes encoder.
     *
     * @param bool $hstsEligible Whether the browser-visible scheme of this
     *                           exchange is https (HUB-4). Only then is
     *                           {@see self::STRICT_TRANSPORT_SECURITY_VALUE}
     *                           attached; the gate lives at the call sites
     *                           ({@see \Phlix\Hub\Application}), which read the
     *                           https first-hop limb of the hub's CookiePolicy
     *                           scheme law — plaintext localhost/dev posture
     *                           ships no HSTS.
     *
     * @return WorkermanResponse
     */
    public function toWorkermanResponse(bool $hstsEligible = false): WorkermanResponse
    {
        $headers = $this->withDefaultSecurityHeaders($this->headers, $hstsEligible);
        $response = $this->headOnly
            ? new BodylessResponse($this->statusCode, $headers, $this->body)
            : new WorkermanResponse($this->statusCode, $headers, $this->body);
        foreach ($this->cookies as $cookie) {
            $response->cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['max_age'],
                $cookie['path'],
                '',
                $cookie['secure'],
                $cookie['http_only'],
                $cookie['same_site'],
            );
        }
        return $response;
    }

    /**
     * Baseline security headers for every hub-emitted response (F3).
     *
     *  - `X-Content-Type-Options: nosniff` everywhere: stops browsers
     *    re-sniffing a mislabelled body into script/executable territory.
     *  - `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` on HTML:
     *    hub pages (SPA shell, OAuth consent, error screens) must never be
     *    click-jacked inside an attacker frame. Same pairing the consent
     *    screen applies manually in `OAuthController::secure()`.
     *  - `Strict-Transport-Security` (HUB-4) on eligible responses only:
     *    the hub sits behind the same HAProxy front end as the server, and a
     *    user-facing admin/SSO flow without HSTS is exactly the downgrade
     *    hole bug #9 closed on the server side. Emission is conditional
     *    because the hub's scheme truth arrives per-request (https first
     *    hop); the server twin emits unconditionally — disclosed posture
     *    delta, both documented at their respective surfaces.
     *
     * Explicitly-set headers always win — each default is applied only when
     * the field is absent (case-insensitively, per RFC 9110 §5), so callers
     * with stricter or looser bespoke values are never overwritten or
     * duplicated.
     *
     * @param array<string, string> $headers
     * @param bool                  $hstsEligible See {@see self::toWorkermanResponse()}.
     *
     * @return array<string, string>
     */
    private function withDefaultSecurityHeaders(array $headers, bool $hstsEligible = false): array
    {
        $present = [];
        $contentType = '';
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            $present[$lower] = true;
            if ($lower === 'content-type') {
                $contentType = strtolower($value);
            }
        }

        if (!isset($present['x-content-type-options'])) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        if ($hstsEligible && !isset($present['strict-transport-security'])) {
            $headers['Strict-Transport-Security'] = self::STRICT_TRANSPORT_SECURITY_VALUE;
        }

        if (str_starts_with($contentType, 'text/html')) {
            if (!isset($present['x-frame-options'])) {
                $headers['X-Frame-Options'] = 'DENY';
            }
            if (!isset($present['content-security-policy'])) {
                $headers['Content-Security-Policy'] = "frame-ancestors 'none'";
            }
        }

        return $headers;
    }
}
