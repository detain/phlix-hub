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
 * `get_playback_info` — how one media item would be played (S62).
 *
 * Wraps `GET /api/v1/media/{id}/playback-info` (phlix-server
 * `MediaItemController::getPlaybackInfo`), a sub-path of the already-allowed
 * `/api/v1/media` GET prefix. It reports the server's playback decision —
 * container/codec details, whether direct play is possible, subtitle and audio
 * tracks — WITHOUT starting anything.
 *
 * ## Why this is not `get_stream_url`
 *
 * S62's plan text names `get_stream_url` / `start_playback`. Neither can be
 * built read-only in this step, and the reason is upstream of the hub:
 *
 *  - The direct-play byte stream `/media/{id}/stream` is a phlix-server
 *    PRE-ROUTER fast path. `Phlix\Hub\RelayRequestDispatcher` states in its own
 *    docblock that "static-file serving, the media byte-stream fast path, and
 *    the SSR" are not dispatched over the relay, so a hub-proxied URL for it
 *    404s no matter what the hub allowlists. Handing a model a URL that cannot
 *    resolve is worse than not offering the tool.
 *  - The HLS/DASH surfaces (`/hls`, `/dash`) only exist once a transcode job
 *    does, and creating one is `POST /api/v1/media/{id}/transcode` — a WRITE.
 *    S62 is read-only tools; a playback WRITE tool is S63's `playback_control`.
 *
 * So this step ships the honest read: what the server says about playing the
 * item. See the worklog for the full finding.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class GetPlaybackInfoTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_playback_info';
    }

    public function description(): string
    {
        return 'Report how a media item on a claimed Phlix server would be played: container and codecs, '
            . 'whether direct play is possible or a transcode would be needed, and the available audio and '
            . 'subtitle tracks. This is read-only — it does not start playback and does not return a '
            . 'playable URL, because starting a stream is a write action this token cannot perform.';
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
        return McpScopes::PLAYBACK_READ;
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

        return $context->proxyGet($serverId, '/api/v1/media/' . $mediaId . '/playback-info');
    }
}
