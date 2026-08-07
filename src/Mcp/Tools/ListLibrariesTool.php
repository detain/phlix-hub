<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp\Tools;

use Phlix\Hub\Mcp\McpArguments;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolInterface;

/**
 * `list_libraries` — the libraries on one owned media server (S62).
 *
 * Wraps `GET /api/v1/libraries`, which sits under the `/api/v1/libraries` prefix
 * that {@see \Phlix\Hub\Http\Controllers\ServerProxyController::BROWSE_SCOPE_ALLOWLIST}
 * already allows for GET. Nothing is added to that allowlist for this tool, and
 * nothing should be: the mutating siblings under the same prefix
 * (`/scan`, `/rescan`, `/prune`, `/delete-all`, …) are pinned in
 * `SCOPE_DENY_PATTERNS` and stay unreachable from here.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class ListLibrariesTool implements McpToolInterface
{
    public function name(): string
    {
        return 'list_libraries';
    }

    public function description(): string
    {
        return 'List the media libraries on one claimed Phlix server (movies, TV, music, photos), with '
            . 'each library\'s id, name and type. Requires a server_id from list_servers. The server\'s '
            . 'relay tunnel must be connected; if it is not, this returns a 503 rather than a library list.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => [
                    'type' => 'string',
                    'description' => 'Id of a server returned by list_servers.',
                ],
            ],
            'required' => ['server_id'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return McpScopes::LIBRARY_READ;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function call(array $arguments, McpToolContext $context): array
    {
        return $context->proxyGet(
            McpArguments::id($arguments, 'server_id'),
            '/api/v1/libraries',
        );
    }
}
