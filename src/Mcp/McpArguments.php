<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use function implode;
use function in_array;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Argument extraction for MCP tools (S62).
 *
 * MCP arguments arrive inside a JSON-RPC envelope and are entirely
 * caller-controlled, so every read is a validation. Two rules are enforced here
 * once rather than in each tool:
 *
 *  - **An id is a SINGLE path segment.** {@see id()} rejects anything containing
 *    `/`, a dot-segment, a percent sign or whitespace. This is defence in depth,
 *    not the primary defence: an id is interpolated into a proxied path, and
 *    `ServerProxyController` already rejects dot-segments outright and then
 *    applies `BROWSE_SCOPE_ALLOWLIST` / `SCOPE_DENY_PATTERNS` to whatever
 *    survives. But an id allowed to carry `/` could still steer a `get_media`
 *    call at a DIFFERENT (still allow-listed, still owner-gated) sub-path than
 *    the tool advertises, and a tool that lies about which endpoint it reads is
 *    a bad tool even when it is not a vulnerability.
 *  - **Failures are typed.** Every accessor throws
 *    {@see McpInvalidArgumentsException}, which the registry's caller renders as
 *    a `mcp.invalid_arguments` error, so a missing argument can never read as an
 *    empty-but-successful call.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpArguments
{
    /**
     * Shape an id must have: one path segment of URL-safe characters.
     *
     * Hub and server ids are 8-4-4-4-12 hex UUIDs, but media ids on phlix-server
     * are not always UUIDs, so this is a character-class rule rather than a UUID
     * rule. Deliberately excludes `%` so a percent-encoded separator cannot be
     * smuggled past the single-segment check and re-emerge after decoding.
     */
    private const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._~-]{0,127}$/';

    /**
     * Read a required, non-empty string argument.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments.
     * @param string               $key       Argument name.
     *
     * @throws McpInvalidArgumentsException When absent, not a string, or blank.
     */
    public static function requiredString(array $arguments, string $key): string
    {
        /** @var mixed $value */
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new McpInvalidArgumentsException(
                sprintf('"%s" is required and must be a non-empty string.', $key),
            );
        }

        return trim($value);
    }

    /**
     * Read a required id argument: a single URL-safe path segment.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments.
     * @param string               $key       Argument name.
     *
     * @throws McpInvalidArgumentsException When absent or not a single segment.
     */
    public static function id(array $arguments, string $key): string
    {
        $value = self::requiredString($arguments, $key);
        if (preg_match(self::ID_PATTERN, $value) !== 1) {
            throw new McpInvalidArgumentsException(sprintf(
                '"%s" must be a single identifier segment (letters, digits, ".", "_", "~" or "-"); '
                . 'it may not contain "/", "%%", whitespace or a dot-segment.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * Read an optional positive integer argument, clamped into `[1, $max]`.
     *
     * A clamp rather than a rejection: a model asking for 5000 results wants
     * "as many as you'll give me", and answering that with an error wastes a
     * round trip. Absent / non-numeric falls back to `$default`.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments.
     * @param string               $key       Argument name.
     * @param int                  $default   Value when the argument is absent.
     * @param int                  $max       Upper bound applied to any value.
     */
    public static function boundedInt(array $arguments, string $key, int $default, int $max): int
    {
        /** @var mixed $value */
        $value = $arguments[$key] ?? null;
        if (is_int($value)) {
            $candidate = $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $candidate = (int) $value;
        } else {
            return $default;
        }

        if ($candidate < 1) {
            return 1;
        }

        return $candidate > $max ? $max : $candidate;
    }

    /**
     * Read a required argument that must be one of a closed set of literals
     * (S63).
     *
     * Rejected rather than defaulted: an unrecognised `action` silently falling
     * back to a default action is how a model asking to `pause` ends up doing
     * something else. The allowed values are listed in the message so the model
     * can correct itself in one round trip instead of guessing.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments.
     * @param string               $key       Argument name.
     * @param list<string>         $allowed   Permitted values.
     *
     * @throws McpInvalidArgumentsException When absent or outside the set.
     */
    public static function oneOf(array $arguments, string $key, array $allowed): string
    {
        $value = self::requiredString($arguments, $key);
        if (!in_array($value, $allowed, true)) {
            throw new McpInvalidArgumentsException(sprintf(
                '"%s" must be one of: %s.',
                $key,
                implode(', ', $allowed),
            ));
        }

        return $value;
    }

    /**
     * Read a required integer argument that must be zero or greater (S63).
     *
     * Distinct from {@see boundedInt()}, which CLAMPS. Clamping is right for a
     * result-count hint ("as many as you'll give me"); it is wrong for a seek
     * position, where silently moving the request to a different timestamp is a
     * worse answer than refusing it. A numeric string is accepted because JSON
     * from some clients quotes numbers, but a fractional value is not — a
     * half-second is not expressible in either unit the two upstream seek
     * endpoints take.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments.
     * @param string               $key       Argument name.
     * @param int                  $max       Inclusive upper bound.
     *
     * @throws McpInvalidArgumentsException When absent, non-integral, negative
     *         or above `$max`.
     */
    public static function nonNegativeInt(array $arguments, string $key, int $max): int
    {
        /** @var mixed $value */
        $value = $arguments[$key] ?? null;
        if (is_int($value)) {
            $candidate = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $candidate = (int) trim($value);
        } else {
            throw new McpInvalidArgumentsException(
                sprintf('"%s" is required and must be a whole number of seconds, zero or greater.', $key),
            );
        }

        if ($candidate < 0 || $candidate > $max) {
            throw new McpInvalidArgumentsException(
                sprintf('"%s" must be between 0 and %d.', $key, $max),
            );
        }

        return $candidate;
    }
}
