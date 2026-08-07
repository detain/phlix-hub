<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use function array_is_list;
use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function trim;

/**
 * Schema validation for the JSON-RPC envelope and the MCP method params (S63).
 *
 * ## Why this is a separate class from {@see JsonRpc}
 *
 * {@see JsonRpc} BUILDS responses and says so in its own docblock: "it does NOT
 * validate requests … that is S63's JSON-RPC schema validation, and pre-empting
 * it here would mean two validators disagreeing later." This is that validator,
 * and it is the only one. Nothing in
 * {@see \Phlix\Hub\Http\Controllers\McpController} re-checks a rule stated here.
 *
 * ## What "schema validation" means here — and what it deliberately does not
 *
 * Every rule below is one of two kinds:
 *
 *  1. **A structural rule of JSON-RPC 2.0 itself** — `jsonrpc` must be present
 *     and exactly `"2.0"` (§4); `id`, when present, must be a string, a number
 *     or null and never a structured value (§4); `method` must be a string.
 *     S62 checked NONE of these: it read `method`, coerced a bad `id` to null
 *     and never looked at `jsonrpc` at all. A coerced `id` is the dangerous one
 *     — the client is waiting on an id it will never see echoed.
 *  2. **A TYPE rule on a field this endpoint reads** — `params.protocolVersion`
 *     must be a string because {@see McpProtocol::negotiate()} is about to
 *     compare it; `params.name` must be a non-empty string because the registry
 *     is about to look a tool up by it.
 *
 * What is deliberately NOT enforced is the PRESENCE of fields the hub never
 * reads. The MCP schema marks `capabilities` and `clientInfo` required on
 * `initialize`; this endpoint uses neither, so refusing a client that omits
 * them would break a working session to enforce a field nobody consults. Their
 * TYPE is still checked when they ARE sent, because a wrong type is always a
 * client bug worth naming. That line — strict on type, lenient on unread
 * absence — is the rule; do not extend it to fields the hub starts reading
 * without also making them required.
 *
 * ## Errors carry `data`, which is new
 *
 * S62 recorded that {@see JsonRpc::error()}'s `$data` parameter had no
 * production caller anywhere. Every error this class returns fills it, because
 * "invalid params" without saying WHICH field costs the model a round trip it
 * cannot diagnose.
 *
 * @package Phlix\Hub\Mcp
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
final class McpRequestValidator
{
    /**
     * The methods {@see \Phlix\Hub\Http\Controllers\McpController} dispatches.
     *
     * Used for ONE thing: deciding whether a params schema applies. JSON-RPC
     * §5.1 orders `Method not found` before `Invalid params`, so params for an
     * unrecognised method must not be validated — the client's actual mistake
     * is the method name, and reporting a params error would send it looking in
     * the wrong place. Keeping the list here rather than pre-checking in the
     * controller avoids a second, unreachable "unknown method" branch.
     *
     * @var list<string>
     */
    public const array KNOWN_METHODS = [
        'initialize',
        'ping',
        'tools/list',
        'tools/call',
    ];

    /**
     * Whether `$method` is one this endpoint implements.
     *
     * @param string $method The `method` member of the envelope.
     */
    public static function isKnownMethod(string $method): bool
    {
        return in_array($method, self::KNOWN_METHODS, true);
    }

    /**
     * Validate the JSON-RPC envelope: `jsonrpc`, `id`, `method`.
     *
     * The caller has already established that the body decoded to a non-list
     * array (an object), so only the members are checked here.
     *
     * ⚠ The caller must decide the NOTIFICATION question before rendering
     * anything returned here. JSON-RPC §4.1 forbids answering a notification at
     * all, including to report that it was malformed, and a notification is
     * identified by the ABSENCE of `id` — which is knowable even when every
     * other member is wrong.
     *
     * @param array<array-key, mixed> $decoded The decoded request object.
     *
     * @return array{code: int, message: string, data: array<string, mixed>}|null
     *         The error to render, or null when the envelope is well-formed.
     */
    public static function envelopeError(array $decoded): ?array
    {
        if (($decoded['jsonrpc'] ?? null) !== JsonRpc::VERSION) {
            return self::error(
                JsonRpc::INVALID_REQUEST,
                sprintf('Every request must carry "jsonrpc": "%s".', JsonRpc::VERSION),
                ['field' => 'jsonrpc', 'expected' => JsonRpc::VERSION],
            );
        }

        if (array_key_exists('id', $decoded)) {
            if (!self::isUsableId($decoded['id'])) {
                // Not a cosmetic complaint. S62 coerced this to null and
                // answered anyway, so a client that sent `id: {...}` or a
                // fractional id got a reply it could not correlate with any
                // request it had outstanding, and waited forever.
                return self::error(
                    JsonRpc::INVALID_REQUEST,
                    '"id" must be a string, an integer, or null — never an object, array, '
                    . 'boolean or fractional number.',
                    ['field' => 'id'],
                );
            }
        }

        if (self::nonEmptyString($decoded['method'] ?? null) === null) {
            // Wording preserved from S62 — `McpControllerTest` pins it, and the
            // message is what an MCP client surfaces to its operator.
            return self::error(
                JsonRpc::INVALID_REQUEST,
                'Missing "method".',
                ['field' => 'method'],
            );
        }

        return null;
    }

    /**
     * Validate `params` for a method this endpoint implements.
     *
     * @param string $method    The (already validated) method name.
     * @param mixed  $rawParams The raw decoded `params` member, or null when
     *                          the member was absent.
     *
     * @return array{code: int, message: string, data: array<string, mixed>}|null
     */
    public static function paramsError(string $method, mixed $rawParams): ?array
    {
        if (!self::isKnownMethod($method)) {
            // See KNOWN_METHODS: `Method not found` must win.
            return null;
        }

        if ($rawParams === null) {
            $rawParams = [];
        }

        if (!is_array($rawParams)) {
            return self::error(
                JsonRpc::INVALID_PARAMS,
                '"params" must be a JSON object of named arguments.',
                ['field' => 'params'],
            );
        }

        if ($rawParams !== [] && array_is_list($rawParams)) {
            // JSON-RPC 2.0 permits positional params; MCP does not — every
            // method it defines takes a by-name object. An empty `[]` and an
            // empty `{}` decode identically in PHP, so only a NON-empty list is
            // detectable, and only that is refused.
            return self::error(
                JsonRpc::INVALID_PARAMS,
                'MCP params are by name: "params" must be a JSON object, not a positional array.',
                ['field' => 'params'],
            );
        }

        return match ($method) {
            'initialize' => self::initializeParamsError($rawParams),
            'tools/call' => self::toolsCallParamsError($rawParams),
            default => null,
        };
    }

    /**
     * `initialize`: `protocolVersion` is required and drives
     * {@see McpProtocol::negotiate()}; the other two members are type-checked
     * only when present (see the class docblock).
     *
     * @param array<array-key, mixed> $params
     *
     * @return array{code: int, message: string, data: array<string, mixed>}|null
     */
    private static function initializeParamsError(array $params): ?array
    {
        if (self::nonEmptyString($params['protocolVersion'] ?? null) === null) {
            return self::error(
                JsonRpc::INVALID_PARAMS,
                '"protocolVersion" is required on initialize and must be a non-empty string.',
                [
                    'field' => 'protocolVersion',
                    'supported' => McpProtocol::SUPPORTED,
                ],
            );
        }

        foreach (['capabilities', 'clientInfo'] as $member) {
            if (!array_key_exists($member, $params)) {
                continue;
            }
            if (!self::isJsonObject($params[$member])) {
                return self::error(
                    JsonRpc::INVALID_PARAMS,
                    sprintf('"%s" must be a JSON object when present.', $member),
                    ['field' => $member],
                );
            }
        }

        return null;
    }

    /**
     * `tools/call`: `name` names the tool; `arguments` is the by-name map handed
     * to it.
     *
     * ⚠ `arguments` is checked for SHAPE only. Its CONTENTS are each tool's own
     * `inputSchema()` to enforce, through {@see McpArguments} — validating them
     * twice, in two places, is how the two copies drift apart.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array{code: int, message: string, data: array<string, mixed>}|null
     */
    private static function toolsCallParamsError(array $params): ?array
    {
        if (self::nonEmptyString($params['name'] ?? null) === null) {
            return self::error(
                JsonRpc::INVALID_PARAMS,
                '"name" is required and must name a tool.',
                ['field' => 'name'],
            );
        }

        if (!array_key_exists('arguments', $params)) {
            return null;
        }

        if ($params['arguments'] === null) {
            // An explicit `"arguments": null` is treated as "absent", not as an
            // error. `arguments` is optional in the MCP schema, and clients
            // serialise "no arguments" both ways; refusing one spelling of a
            // thing that is allowed to be missing would be pedantry with a
            // failed tool call attached.
            return null;
        }

        if (!self::isJsonObject($params['arguments'])) {
            return self::error(
                JsonRpc::INVALID_PARAMS,
                '"arguments" must be a JSON object of named tool arguments.',
                ['field' => 'arguments'],
            );
        }

        return null;
    }

    /**
     * Whether a decoded `id` member is one JSON-RPC 2.0 §4 permits.
     *
     * Takes `mixed` as a DECLARED parameter type rather than binding the value
     * to a `mixed` variable at the call site: the two read the same but only the
     * latter is the untyped binding errorLevel 1 forbids.
     *
     * Note `is_int()` and not `is_numeric()`: `1.5` is a number JSON allows and
     * JSON-RPC does not (§4 forbids fractional parts).
     *
     * @param mixed $id The raw decoded `id`.
     */
    private static function isUsableId(mixed $id): bool
    {
        return $id === null || is_string($id) || is_int($id);
    }

    /**
     * A trimmed non-empty string, or null when the value is neither.
     *
     * @param mixed $value The raw decoded member.
     */
    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Whether a decoded value is a JSON OBJECT.
     *
     * PHP cannot distinguish `{}` from `[]` after `json_decode(..., true)` —
     * both are `[]` — so an empty array counts as an object. A NON-empty list
     * does not: that is a JSON array, which no MCP member accepts.
     *
     * @param mixed $value The raw decoded member.
     */
    private static function isJsonObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{code: int, message: string, data: array<string, mixed>}
     */
    private static function error(int $code, string $message, array $data): array
    {
        return ['code' => $code, 'message' => $message, 'data' => $data];
    }
}
