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
 * `get_media` — full metadata for one media item on an owned server (S62).
 *
 * Wraps `GET /api/v1/media/{id}`. The id is validated as a SINGLE path segment
 * by {@see McpArguments::id()} before interpolation, so this tool addresses the
 * endpoint it advertises and no other sub-path under the `/api/v1/media` prefix.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class GetMediaTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_media';
    }

    public function description(): string
    {
        return 'Fetch full metadata for one media item on a claimed Phlix server — title, year, overview, '
            . 'runtime, genres, cast and (for a series) its season/episode structure. Requires a '
            . 'server_id from list_servers and a media_id from search_media.';
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
                'media_id' => [
                    'type' => 'string',
                    'description' => 'Id of a media item, as returned by search_media.',
                ],
            ],
            'required' => ['server_id', 'media_id'],
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
        $serverId = McpArguments::id($arguments, 'server_id');
        $mediaId = McpArguments::id($arguments, 'media_id');

        return $context->proxyGet($serverId, '/api/v1/media/' . $mediaId);
    }
}
