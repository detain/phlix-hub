<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Mcp\McpInvalidArgumentsException;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToken;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Mcp\Tools\PlaybackControlTool;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function json_decode;
use function strtolower;

/**
 * Unit tests for {@see PlaybackControlTool} — the flagged MCP write tool (S63).
 *
 * ## What is real here and what is doubled
 *
 * The tool is driven through a REAL {@see McpToolContext} over a REAL
 * {@see ServerProxyController}, so every assertion about a forwarded path is an
 * assertion about a path that got past the production ownership gate, the
 * traversal check and the write allowlist. Only the relay bridge is doubled —
 * by a recording publisher, because the forwarded METHOD, PATH and BODY are
 * exactly what these tests are about and there is no other place to read them.
 *
 * ## The caveat assertions are not decoration
 *
 * The casting backends this tool drives are not production-functional. A model
 * only ever reads two things about a tool: its `description()` at `tools/list`,
 * and the payload it gets back. Both are asserted to carry the caveat, on
 * SUCCESS as well as failure — a warning that appeared only on errors would
 * disappear at exactly the moment a model concludes "that worked".
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
final class PlaybackControlToolTest extends TestCase
{
    use DecodedJsonAssertions;

    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string SERVER_OF_A = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /** @var array<string, mixed>|null The last payload the bridge publisher saw. */
    private ?array $forwarded = null;

    // ------------------------------------------------------------------
    // The descriptor a model reads
    // ------------------------------------------------------------------

    public function testTheToolRequiresTheWriteScopeNotAReadOne(): void
    {
        self::assertSame(McpScopes::PLAYBACK_CONTROL, (new PlaybackControlTool())->requiredScope());
    }

    /**
     * The description a model reads at `tools/list` carries the caveat verbatim.
     *
     * Exact-substring on the shared constant, so rewording the constant without
     * rewording the description (or vice versa) cannot drift them apart.
     */
    public function testTheDescriptionCarriesTheBestEffortCaveat(): void
    {
        $description = (new PlaybackControlTool())->description();

        self::assertStringContainsString(PlaybackControlTool::CAVEAT, $description);
        self::assertStringContainsString('BEST EFFORT ONLY', $description);
        self::assertStringContainsString('not production-functional', $description);
    }

    /**
     * ...and it does NOT claim the tool starts playback, because it cannot.
     */
    public function testTheDescriptionDoesNotPromiseItCanStartPlayback(): void
    {
        $description = strtolower((new PlaybackControlTool())->description());

        self::assertStringContainsString('cannot start playback', $description);
    }

    public function testTheSchemaIsClosedAndNamesItsRequiredArguments(): void
    {
        $schema = (new PlaybackControlTool())->inputSchema();

        self::assertSame('object', $schema['type'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? true);
        self::assertSame(['server_id', 'target', 'action'], $schema['required'] ?? null);
    }

    // ------------------------------------------------------------------
    // The paths it forwards
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function forwardedCallProvider(): iterable
    {
        yield 'chromecast list' => [
            ['target' => 'chromecast', 'action' => 'list_devices'],
            'GET',
            '/api/v1/cast/devices',
        ];
        yield 'chromecast status' => [
            ['target' => 'chromecast', 'action' => 'status', 'device_id' => 'dev-1'],
            'GET',
            '/api/v1/cast/devices/dev-1/status',
        ];
        yield 'chromecast play' => [
            ['target' => 'chromecast', 'action' => 'play', 'device_id' => 'dev-1'],
            'POST',
            '/api/v1/cast/devices/dev-1/play',
        ];
        yield 'chromecast pause' => [
            ['target' => 'chromecast', 'action' => 'pause', 'device_id' => 'dev-1'],
            'POST',
            '/api/v1/cast/devices/dev-1/pause',
        ];
        yield 'chromecast stop' => [
            ['target' => 'chromecast', 'action' => 'stop', 'device_id' => 'dev-1'],
            'POST',
            '/api/v1/cast/devices/dev-1/stop',
        ];
        yield 'dlna list' => [
            ['target' => 'dlna', 'action' => 'list_devices'],
            'GET',
            '/api/v1/dlna/renderers',
        ];
        yield 'dlna status' => [
            ['target' => 'dlna', 'action' => 'status', 'device_id' => 'dev-1'],
            'GET',
            '/api/v1/dlna/renderers/dev-1/status',
        ];
        yield 'dlna pause' => [
            ['target' => 'dlna', 'action' => 'pause', 'device_id' => 'dev-1'],
            'POST',
            '/api/v1/dlna/renderers/dev-1/pause',
        ];
        yield 'dlna stop' => [
            ['target' => 'dlna', 'action' => 'stop', 'device_id' => 'dev-1'],
            'POST',
            '/api/v1/dlna/renderers/dev-1/stop',
        ];
    }

    /**
     * Each supported action forwards the verb and path the server registers.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider forwardedCallProvider
     */
    public function testEachActionForwardsTheExpectedVerbAndPath(
        array $arguments,
        string $method,
        string $path,
    ): void {
        $outcome = (new PlaybackControlTool())->call(
            ['server_id' => self::SERVER_OF_A] + $arguments,
            $this->context(),
        );

        self::assertSame(200, $outcome['status'], 'the call did not reach the relay: it was refused first.');
        self::assertIsArray($this->forwarded);
        self::assertSame($method, $this->forwarded['method'] ?? null);
        self::assertSame($path, $this->forwarded['path'] ?? null);
    }

    /**
     * Seek converts to the unit the device family wants — milliseconds for
     * Chromecast, 100-nanosecond ticks for DLNA.
     *
     * The two are asserted TOGETHER and with different expected values, because
     * a converter that emitted the same number for both would satisfy either row
     * alone. Getting this wrong is a silent wrong-position seek, not an error.
     *
     * @dataProvider seekUnitProvider
     */
    public function testSeekConvertsSecondsIntoTheTargetsOwnUnit(
        string $target,
        int $seconds,
        string $expectedField,
        int $expectedValue,
    ): void {
        (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => $target,
            'action' => 'seek',
            'device_id' => 'dev-1',
            'position_seconds' => $seconds,
        ], $this->context());

        self::assertSame([$expectedField => $expectedValue], $this->forwardedBody());
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: int}>
     */
    public static function seekUnitProvider(): array
    {
        return [
            'chromecast milliseconds' => ['chromecast', 42, 'position_ms', 42000],
            'dlna ticks' => ['dlna', 42, 'position_ticks', 420000000],
            'chromecast zero' => ['chromecast', 0, 'position_ms', 0],
            'dlna zero' => ['dlna', 0, 'position_ticks', 0],
        ];
    }

    /**
     * A non-seek action sends NO body. An empty body is what
     * `ServerProxyController::reconstructBody()` turns into an absent one, and
     * inventing `{}` for a pause would be a fabricated request field.
     */
    public function testANonSeekActionForwardsNoBody(): void
    {
        (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => 'chromecast',
            'action' => 'pause',
            'device_id' => 'dev-1',
        ], $this->context());

        self::assertIsArray($this->forwarded);
        self::assertSame('', base64_decode(self::stringNode($this->forwarded['body_b64'] ?? ''), true));
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    /**
     * DLNA `play` is refused BY NAME, and the tunnel is never touched.
     *
     * On DLNA the `/play` route is `playTo()` — a session START whose body
     * carries a caller-supplied `uri` the renderer is told to fetch. Naming the
     * refusal lets the model choose another action; letting it through would
     * have handed a model a server-side outbound-fetch primitive.
     */
    public function testDlnaPlayIsRefusedByNameAndNeverForwarded(): void
    {
        $this->expectException(McpInvalidArgumentsException::class);
        $this->expectExceptionMessageMatches('/not available for target "dlna"/');

        try {
            (new PlaybackControlTool())->call([
                'server_id' => self::SERVER_OF_A,
                'target' => 'dlna',
                'action' => 'play',
                'device_id' => 'dev-1',
            ], $this->context());
        } finally {
            self::assertNull($this->forwarded, 'dlna play reached the relay.');
        }
    }

    /**
     * ...and the SUCCEEDING control beside it: the same `play` action IS
     * available on chromecast, where it means resume. Without this row a tool
     * that refused every `play` would satisfy the test above.
     */
    public function testChromecastPlayIsAvailable(): void
    {
        $outcome = (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => 'chromecast',
            'action' => 'play',
            'device_id' => 'dev-1',
        ], $this->context());

        self::assertSame(200, $outcome['status']);
    }

    /**
     * An unknown target or action is refused, never defaulted.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider unusableArgumentProvider
     */
    public function testUnusableArgumentsAreRefused(array $arguments): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        try {
            (new PlaybackControlTool())->call(
                ['server_id' => self::SERVER_OF_A] + $arguments,
                $this->context(),
            );
        } finally {
            self::assertNull($this->forwarded, 'an unusable call reached the relay.');
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableArgumentProvider(): array
    {
        return [
            'an unknown target' => [['target' => 'roku', 'action' => 'pause', 'device_id' => 'dev-1']],
            'an unknown action' => [['target' => 'chromecast', 'action' => 'reboot', 'device_id' => 'dev-1']],
            'a missing target' => [['action' => 'pause', 'device_id' => 'dev-1']],
            'a missing action' => [['target' => 'chromecast', 'device_id' => 'dev-1']],
            'a missing device id' => [['target' => 'chromecast', 'action' => 'pause']],
            'a device id with a path separator'
                => [['target' => 'chromecast', 'action' => 'pause', 'device_id' => '../../admin/users']],
            'a device id with an encoded separator'
                => [['target' => 'chromecast', 'action' => 'pause', 'device_id' => 'a%2Fb']],
            'a seek with no position'
                => [['target' => 'chromecast', 'action' => 'seek', 'device_id' => 'dev-1']],
            'a seek with a negative position' => [[
                'target' => 'chromecast', 'action' => 'seek', 'device_id' => 'dev-1', 'position_seconds' => -1,
            ]],
            'a seek with a fractional position' => [[
                'target' => 'chromecast', 'action' => 'seek', 'device_id' => 'dev-1', 'position_seconds' => 1.5,
            ]],
            'a seek with a non-numeric position' => [[
                'target' => 'chromecast', 'action' => 'seek', 'device_id' => 'dev-1', 'position_seconds' => 'soon',
            ]],
            'a seek beyond the bound' => [[
                'target' => 'chromecast', 'action' => 'seek', 'device_id' => 'dev-1', 'position_seconds' => 999999999,
            ]],
        ];
    }

    // ------------------------------------------------------------------
    // The caveat travels with every answer
    // ------------------------------------------------------------------

    /**
     * A SUCCESSFUL call still carries the caveat.
     *
     * This is the row that matters. A warning present only on failures vanishes
     * at exactly the moment a model concludes the action worked — and "the hub
     * forwarded it and the server answered 200" is not the same claim as "the
     * device did anything".
     */
    public function testASuccessfulResponseStillCarriesTheCaveat(): void
    {
        $outcome = (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => 'chromecast',
            'action' => 'pause',
            'device_id' => 'dev-1',
        ], $this->context());

        self::assertSame(200, $outcome['status']);
        /** @var array<string, mixed> $phlix */
        $phlix = $outcome['payload']['_phlix'] ?? [];
        self::assertTrue($phlix['best_effort'] ?? null);
        self::assertSame(PlaybackControlTool::CAVEAT, $phlix['caveat'] ?? null);
    }

    /**
     * ...and so does a REFUSAL from the server, without losing the server's own
     * fields.
     */
    public function testAnUpstreamErrorKeepsBothTheServerPayloadAndTheCaveat(): void
    {
        $outcome = (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => 'chromecast',
            'action' => 'pause',
            'device_id' => 'dev-1',
        ], $this->context(status: 404, body: '{"error":"No active session for device"}'));

        self::assertSame(404, $outcome['status']);
        self::assertSame('No active session for device', $outcome['payload']['error'] ?? null);
        /** @var array<string, mixed> $phlix */
        $phlix = $outcome['payload']['_phlix'] ?? [];
        self::assertSame(PlaybackControlTool::CAVEAT, $phlix['caveat'] ?? null);
    }

    /**
     * The caveat lives under a `_phlix` key so it cannot be mistaken for — or
     * collide with — a field the media server itself returned.
     */
    public function testTheCaveatDoesNotOverwriteAServerField(): void
    {
        $outcome = (new PlaybackControlTool())->call([
            'server_id' => self::SERVER_OF_A,
            'target' => 'chromecast',
            'action' => 'status',
            'device_id' => 'dev-1',
        ], $this->context(body: '{"state":"PLAYING","caveat":"a field the server owns"}'));

        self::assertSame('PLAYING', $outcome['payload']['state'] ?? null);
        self::assertSame('a field the server owns', $outcome['payload']['caveat'] ?? null);
        /** @var array<string, mixed> $phlix */
        $phlix = $outcome['payload']['_phlix'] ?? [];
        self::assertSame(PlaybackControlTool::CAVEAT, $phlix['caveat'] ?? null);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * The decoded JSON body the bridge was handed.
     *
     * @return array<string, mixed>
     */
    private function forwardedBody(): array
    {
        self::assertIsArray($this->forwarded);
        $encoded = $this->forwarded['body_b64'] ?? null;
        self::assertIsString($encoded);
        $json = base64_decode($encoded, true);
        self::assertIsString($json);
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'the forwarded body was not JSON: ' . $json);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A context over the REAL proxy controller, with a recording bridge that
     * answers `$status` / `$body`.
     */
    private function context(int $status = 200, string $body = '{"success":true}'): McpToolContext
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getOwnerAndStatus')->willReturnCallback(
            static function (string $serverId): ?array {
                if ($serverId !== self::SERVER_OF_A) {
                    return null;
                }

                return [
                    'userId' => self::USER_A,
                    'status' => ServerInfoDto::STATUS_ONLINE,
                    'relayActive' => true,
                ];
            },
        );

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('checkUserQuota')->willReturn([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 0,
        ]);
        $sessions->method('activeUserStreams')->willReturn(0);
        $sessions->method('getUserThrottleBps')->willReturn(0);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, $status, $body): void {
            $this->forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => $status,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
            ]);
        };

        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $proxy = new ServerProxyController(
            $serverInfo,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600, 1000),
        );

        return new McpToolContext(
            new McpToken('token-row-1', self::USER_A, McpScopes::all()),
            $proxy,
            new ServerListController($serverInfo),
            '203.0.113.7',
        );
    }
}
