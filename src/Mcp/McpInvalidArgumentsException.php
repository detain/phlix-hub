<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use RuntimeException;

/**
 * A tool was called with arguments it cannot use (S62).
 *
 * Thrown by {@see McpArguments} and caught by
 * {@see \Phlix\Hub\Http\Controllers\McpController}, which renders it as a
 * JSON-RPC `-32602 Invalid params` error. Typed rather than returned so a tool
 * cannot accidentally continue past a failed argument read — the alternative,
 * a nullable return, is exactly the shape that produces an "empty but
 * successful" tool result.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpInvalidArgumentsException extends RuntimeException
{
}
