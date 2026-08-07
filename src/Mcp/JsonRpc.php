<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

/**
 * JSON-RPC 2.0 envelope construction for the MCP endpoint (S62).
 *
 * ## Scope, deliberately small
 *
 * This builds responses. It does NOT validate requests against the JSON-RPC or
 * MCP schemas — that is S63's "JSON-RPC schema validation", and pre-empting it
 * here would mean two validators disagreeing later.
 * {@see \Phlix\Hub\Http\Controllers\McpController} does the minimum structural
 * checking needed to answer at all (is it an object, does it name a method) and
 * defers the rest.
 *
 * ## The `id` rules that matter
 *
 * Per JSON-RPC 2.0 §5, an error response carries `id: null` when the id could
 * not be determined (a parse error, or a request that is not an object). A
 * NOTIFICATION — a request with no `id` at all — gets no response body ever, not
 * even an error; the caller sends HTTP 202 with an empty body instead. Getting
 * that wrong makes an MCP client wait for a reply that is not coming, so the
 * `id`-carrying and `id`-less paths are separated here rather than left to each
 * call site.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class JsonRpc
{
    /** The only `jsonrpc` version string this endpoint speaks. */
    public const string VERSION = '2.0';

    /** Invalid JSON was received. */
    public const int PARSE_ERROR = -32700;

    /** The payload is not a valid JSON-RPC request object. */
    public const int INVALID_REQUEST = -32600;

    /** The named method does not exist. */
    public const int METHOD_NOT_FOUND = -32601;

    /** The method exists but the params are unusable. */
    public const int INVALID_PARAMS = -32602;

    /** Something failed inside the handler. */
    public const int INTERNAL_ERROR = -32603;

    /**
     * A successful response.
     *
     * @param string|int|null      $id     The request id, echoed verbatim.
     * @param array<string, mixed> $result The method result.
     *
     * @return array<string, mixed>
     */
    public static function result(string|int|null $id, array $result): array
    {
        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * An error response.
     *
     * @param string|int|null      $id      The request id, or null when it could
     *                                      not be determined (JSON-RPC 2.0 §5).
     * @param int                  $code    One of the `*_ERROR` / `INVALID_*`
     *                                      constants above.
     * @param string               $message Short human-readable summary.
     * @param array<string, mixed> $data    Optional machine-readable detail.
     *
     * @return array<string, mixed>
     */
    public static function error(string|int|null $id, int $code, string $message, array $data = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($data !== []) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => $error,
        ];
    }
}
