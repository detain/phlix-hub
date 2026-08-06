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
 * One MCP tool (S62).
 *
 * ## The signature is the security boundary
 *
 * {@see call()} receives caller-controlled `$arguments` and an
 * {@see McpToolContext}. It does NOT receive a user id, a database connection, a
 * relay bridge, or a server row — and there is no way to obtain one from here.
 * Everything a tool can reach goes through the context, which runs it as the
 * presenting token's user and through the production ownership + browse-scope
 * gates. An implementation that "forgets" to check ownership is therefore not
 * possible; there is nothing to forget.
 *
 * Keep it that way. A tool that takes a `Connection`, a `ServerInfoHandler`, a
 * `RelayProxyBridge`, or the inbound `Request` in its constructor has stepped
 * around the boundary, and
 * {@see \Phlix\Hub\Tests\Unit\Mcp\McpToolIsolationTest} fails the build for it.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
interface McpToolInterface
{
    /**
     * Machine name the MCP client calls (`tools/call` `name`).
     */
    public function name(): string;

    /**
     * One-paragraph description shown to the model in `tools/list`.
     */
    public function description(): string;

    /**
     * JSON Schema for {@see call()}'s `$arguments`, as MCP's `inputSchema`.
     *
     * S62 publishes the schema but does not VALIDATE against it — JSON-RPC /
     * schema validation is S63. Each tool therefore still checks its own
     * required arguments and returns an `mcp.invalid_arguments` error rather
     * than assuming the client honoured the schema.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * The {@see McpScopes} constant a token must hold to invoke this tool.
     *
     * Enforced by {@see McpToolRegistry::call()} BEFORE the tool runs, so a
     * scope check cannot be omitted per-tool either.
     */
    public function requiredScope(): string;

    /**
     * Run the tool.
     *
     * @param array<string, mixed> $arguments Caller-supplied arguments. Untrusted.
     * @param McpToolContext       $context   The only capability surface (see the
     *        class docblock). Runs as the PAT's user; enforces ownership.
     *
     * @return array{status: int, payload: array<string, mixed>} Upstream status
     *         plus the payload to render as the tool result. A status >= 400 is
     *         rendered as an MCP `isError` result by
     *         {@see McpToolRegistry::call()}.
     */
    public function call(array $arguments, McpToolContext $context): array;
}
