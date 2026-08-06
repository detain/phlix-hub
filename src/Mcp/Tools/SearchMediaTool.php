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

use function http_build_query;

/**
 * `search_media` — full-text search across one owned server's libraries (S62).
 *
 * Wraps `GET /api/v1/media/search`, a `/`-delimited sub-path of the
 * `/api/v1/media` GET prefix the proxy already allows. The query text is passed
 * through {@see http_build_query()} rather than concatenated, so a search term
 * containing `&`, `=` or a space cannot invent a second query parameter.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class SearchMediaTool implements McpToolInterface
{
    /** Result cap when the caller does not ask for one. */
    private const int DEFAULT_LIMIT = 20;

    /**
     * Hard ceiling on `limit`.
     *
     * A tool result is fed to a model as text, so an unbounded page is a
     * context-window problem before it is a bandwidth one. The upstream server
     * applies its own cap as well; this one just keeps the request sane.
     */
    private const int MAX_LIMIT = 100;

    public function name(): string
    {
        return 'search_media';
    }

    public function description(): string
    {
        return 'Search one claimed Phlix server\'s libraries for media matching a text query, returning '
            . 'matching titles with their ids. Use the returned id with get_media for full details. '
            . 'Requires a server_id from list_servers.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'Free-text search term, e.g. a title, an actor or a series name.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'Maximum results to return. Defaults to ' . self::DEFAULT_LIMIT
                        . '; values above ' . self::MAX_LIMIT . ' are clamped.',
                ],
            ],
            'required' => ['server_id', 'query'],
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
        $query = McpArguments::requiredString($arguments, 'query');
        $limit = McpArguments::boundedInt($arguments, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return $context->proxyGet(
            $serverId,
            '/api/v1/media/search',
            http_build_query(['q' => $query, 'limit' => $limit]),
        );
    }
}
