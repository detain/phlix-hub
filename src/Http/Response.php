<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
     *
     * @var (callable(TcpConnection): void)|null
     */
    public $streamProducer = null;

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
        $this->streamProducer = $producer;
        return $this;
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
     * JSON-encode `$data` as the response body and set Content-Type.
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
        $this->body = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return $this;
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
     * @return WorkermanResponse
     */
    public function toWorkermanResponse(): WorkermanResponse
    {
        $response = $this->headOnly
            ? new BodylessResponse($this->statusCode, $this->headers, $this->body)
            : new WorkermanResponse($this->statusCode, $this->headers, $this->body);
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
}
