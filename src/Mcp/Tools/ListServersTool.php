<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp\Tools;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolInterface;

/**
 * `list_servers` — the media servers the presenting token's user has claimed
 * (S62).
 *
 * The one tool that does not go over the relay: server ownership is hub state,
 * so this reads it from the hub. It still goes through the production
 * {@see \Phlix\Hub\Http\Controllers\ServerListController} (via
 * {@see McpToolContext::servers()}) rather than touching a repository, which is
 * what keeps "only this user's servers" a property of the existing controller
 * instead of a claim this file has to make good on.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class ListServersTool implements McpToolInterface
{
    public function name(): string
    {
        return 'list_servers';
    }

    public function description(): string
    {
        return 'List the Phlix media servers this account has claimed, with each server\'s id, name, '
            . 'online status and whether its relay tunnel is currently connected. Call this first: every '
            . 'other tool needs a server_id from here, and a server whose relay tunnel is not connected '
            . 'cannot answer library or media queries.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return McpScopes::SERVERS_READ;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function call(array $arguments, McpToolContext $context): array
    {
        return $context->servers();
    }
}
