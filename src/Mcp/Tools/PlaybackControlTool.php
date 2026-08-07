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
use Phlix\Hub\Mcp\McpInvalidArgumentsException;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolInterface;

use function array_keys;
use function sprintf;

/**
 * `playback_control` — transport control for a cast/DLNA session (S63).
 *
 * ## ⚠ THE CASTING BACKENDS ARE NOT PRODUCTION-FUNCTIONAL
 *
 * Read this before reading anything else about the tool. A prior audit of
 * phlix-server found the Chromecast, Roku and AirPlay backends NOT
 * production-functional, and the DLNA renderer surface is gated behind a
 * `PlayToManager` the server only registers when it is configured. So:
 *
 *  - this tool is **best effort**, and that word is not hedging — a call may be
 *    correctly authorised, correctly routed, correctly forwarded and still do
 *    nothing, because the device backend on the far end does not work;
 *  - the caveat is repeated in {@see description()} (the text a model reads at
 *    `tools/list`, i.e. the only place a model will see it) and in the `_phlix`
 *    block of EVERY response (the only place a model will see it after acting).
 *    It is deliberately not confined to this docblock, which no model reads;
 *  - the tool ships behind an operator flag that is **OFF by default**. When the
 *    flag is off the tool is not registered at all, so it appears in no
 *    `tools/list` and a `tools/call` for it is `mcp.unknown_tool`. See
 *    `HubServicesProvider`.
 *
 * Do not "improve" the description by removing the caveat. It is the honest
 * description of what this does today.
 *
 * ## What it can and cannot do
 *
 * It controls an ALREADY-RUNNING session: pause, resume, stop, seek — plus the
 * two reads (`list_devices`, `status`) without which a model has no device id
 * to name. It cannot START one. The two server routes that start a session are
 * deliberately outside the proxy's allowlist:
 *
 *  - `POST /api/v1/cast/devices/{id}/cast` — takes the media item to cast;
 *  - `POST /api/v1/dlna/renderers/{id}/play` — despite the name this is
 *    `playTo()`, which takes a caller-supplied `uri` that the renderer on the
 *    operator's LAN is then told to fetch.
 *
 * The second is why `play` is Chromecast-only here: on Chromecast `/play`
 * resumes an existing session, on DLNA the same word means "start this URL".
 * Mapping one tool action onto both would have made a resume request into a
 * fetch instruction.
 *
 * ## Units
 *
 * The two upstream seek endpoints take DIFFERENT units — `position_ms`
 * (Chromecast) and `position_ticks`, i.e. 100-nanosecond units (DLNA). The tool
 * takes plain `position_seconds` and converts, because asking a model to know
 * which device family wants which unit is asking it to get it wrong.
 *
 * @package Phlix\Hub\Mcp\Tools
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
final class PlaybackControlTool implements McpToolInterface
{
    /** The one-line caveat repeated in the description and every response. */
    public const string CAVEAT = 'BEST EFFORT ONLY: the Chromecast/Roku/AirPlay casting backends on Phlix '
        . 'servers are not production-functional, and DLNA renderer control is only present when the '
        . 'operator has configured it. A call can be accepted and still have no effect on any device. '
        . 'Always re-read the "status" action before telling a user that playback changed.';

    /** Upper bound on a seek position, in seconds (~27.7 hours). */
    private const int MAX_POSITION_SECONDS = 100000;

    /**
     * Action → [HTTP verb, path tail] per target family.
     *
     * Restated per target rather than derived, because the two families are not
     * symmetric: DLNA has no resume, and its `play` means something else
     * entirely (see the class docblock). A shared table with a `play` row would
     * have hidden that.
     *
     * @var array<string, array<string, string>>
     */
    private const ACTIONS = [
        'chromecast' => [
            'list_devices' => '',
            'status' => 'status',
            'play' => 'play',
            'pause' => 'pause',
            'stop' => 'stop',
            'seek' => 'seek',
        ],
        'dlna' => [
            'list_devices' => '',
            'status' => 'status',
            'pause' => 'pause',
            'stop' => 'stop',
            'seek' => 'seek',
        ],
    ];

    /** Target family → the server's collection path. */
    private const COLLECTIONS = [
        'chromecast' => '/api/v1/cast/devices',
        'dlna' => '/api/v1/dlna/renderers',
    ];

    public function name(): string
    {
        return 'playback_control';
    }

    public function description(): string
    {
        return 'Control an already-running cast or DLNA playback session on a claimed Phlix server: '
            . 'list devices, read session status, pause, resume (Chromecast only), stop, or seek. '
            . 'It cannot START playback — beginning a session is a separate action this tool does not '
            . 'expose. ' . self::CAVEAT;
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
                'target' => [
                    'type' => 'string',
                    'enum' => array_keys(self::ACTIONS),
                    'description' => 'Which device family to talk to: "chromecast" or "dlna".',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => array_keys(self::ACTIONS['chromecast']),
                    'description' => 'list_devices and status are reads. play (Chromecast only), pause, '
                        . 'stop and seek act on the session. dlna does not support play — on that family '
                        . '"play" means starting a new session, which this tool does not expose.',
                ],
                'device_id' => [
                    'type' => 'string',
                    'description' => 'Device/renderer id from list_devices. Required for every action '
                        . 'except list_devices.',
                ],
                'position_seconds' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => self::MAX_POSITION_SECONDS,
                    'description' => 'Seek target in whole seconds. Required for the seek action and '
                        . 'ignored otherwise. Converted to the unit the device family wants.',
                ],
            ],
            'required' => ['server_id', 'target', 'action'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return McpScopes::PLAYBACK_CONTROL;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{status: int, payload: array<string, mixed>}
     *
     * @throws McpInvalidArgumentsException When the arguments do not name a
     *         supported target/action pair.
     */
    public function call(array $arguments, McpToolContext $context): array
    {
        $serverId = McpArguments::id($arguments, 'server_id');
        $target = McpArguments::oneOf($arguments, 'target', array_keys(self::ACTIONS));
        $action = McpArguments::oneOf($arguments, 'action', array_keys(self::ACTIONS['chromecast']));

        $tails = self::ACTIONS[$target];
        if (!isset($tails[$action])) {
            // A real combination the model may reasonably try (dlna + play).
            // Named explicitly rather than 404-ed by the proxy, so the model is
            // told WHY and can pick another action instead of retrying.
            throw new McpInvalidArgumentsException(sprintf(
                'The "%s" action is not available for target "%s". On DLNA "play" starts a NEW session '
                . 'from a caller-supplied URL, which this tool deliberately does not expose; use pause, '
                . 'stop or seek on a session that is already running.',
                $action,
                $target,
            ));
        }

        $collection = self::COLLECTIONS[$target];

        if ($action === 'list_devices') {
            return self::annotate($context->proxyGet($serverId, $collection));
        }

        $deviceId = McpArguments::id($arguments, 'device_id');
        $path = $collection . '/' . $deviceId . '/' . $tails[$action];

        if ($action === 'status') {
            return self::annotate($context->proxyGet($serverId, $path));
        }

        return self::annotate($context->proxyPost($serverId, $path, self::body($target, $action, $arguments)));
    }

    /**
     * The JSON body for a transport POST.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws McpInvalidArgumentsException When a seek carries no usable position.
     */
    private static function body(string $target, string $action, array $arguments): array
    {
        if ($action !== 'seek') {
            return [];
        }

        $seconds = McpArguments::nonNegativeInt($arguments, 'position_seconds', self::MAX_POSITION_SECONDS);

        // Chromecast wants milliseconds; DLNA wants 100-nanosecond ticks.
        return $target === 'chromecast'
            ? ['position_ms' => $seconds * 1000]
            : ['position_ticks' => $seconds * 10000000];
    }

    /**
     * Stamp the caveat onto the outcome.
     *
     * Under a `_phlix` key so it cannot collide with a field the media server
     * itself returns, and applied to EVERY outcome — success, refusal and
     * upstream error alike. A caveat that appeared only on failures would read
     * as "this worked" the moment it worked, which is precisely the claim that
     * cannot be made.
     *
     * @param array{status: int, payload: array<string, mixed>} $outcome
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private static function annotate(array $outcome): array
    {
        $outcome['payload']['_phlix'] = [
            'best_effort' => true,
            'caveat' => self::CAVEAT,
        ];

        return $outcome;
    }
}
