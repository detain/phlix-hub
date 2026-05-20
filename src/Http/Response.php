<?php

declare(strict_types=1);

namespace Phlix\Hub\Http;

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
 * @since 0.1.0
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
     * @param bool   $httpOnly HttpOnly flag. Default true.
     * @param bool   $secure   Secure flag. Default false in dev.
     * @param string $sameSite SameSite policy ("Strict"/"Lax"/"None"). Default "Lax".
     *
     * @return self
     */
    public function cookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        bool $httpOnly = true,
        bool $secure = false,
        string $sameSite = 'Lax',
    ): self {
        $this->cookies[] = [
            'name'      => $name,
            'value'     => $value,
            'max_age'   => $maxAge,
            'path'      => $path,
            'http_only' => $httpOnly,
            'secure'    => $secure,
            'same_site' => $sameSite,
        ];
        return $this;
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
     * Convert this builder into a Workerman response object.
     *
     * @return WorkermanResponse
     */
    public function toWorkermanResponse(): WorkermanResponse
    {
        $response = new WorkermanResponse($this->statusCode, $this->headers, $this->body);
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
