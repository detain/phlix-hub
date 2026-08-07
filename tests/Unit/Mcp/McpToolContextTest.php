<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToken;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Execution coverage for {@see McpToolContext}'s byte-streaming backstop (S62).
 *
 * ## Why this file exists
 *
 * {@see McpToolIsolationTest::test_no_tool_targets_a_byte_streaming_prefix()}
 * already pins the INVARIANT that no shipped tool names `/hls`, `/dash` or
 * `/media`. That is a static check over source text: it proves the guard in
 * {@see McpToolContext::proxyGet()} is not needed TODAY, and in doing so it
 * leaves the guard itself never executed by anything.
 *
 * That is precisely the shape this program has been burned by — a green test
 * pinning code that no longer runs. A mutation that deletes the
 * `$response->streamProducer !== null` branch entirely survives the whole suite
 * unless something drives it. This file drives it, so the backstop is proven
 * REACHABLE and correct rather than assumed to be.
 *
 * The two tests are deliberately a pair: one asserts the streaming path yields
 * 501 `mcp.streaming_unsupported`, and the SUCCEEDING CONTROL beside it asserts
 * that a buffered path through the very same fixture does NOT — otherwise "501
 * on everything" would look identical to a working guard.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpToolContext
 */
final class McpToolContextTest extends TestCase
{
    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string SERVER_OF_A = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /**
     * A path in a byte-streaming family comes back as 501, not as a 200 with an
     * empty body.
     *
     * `/media/{id}/stream` is inside the proxy's GET browse allowlist AND inside
     * its `STREAMING_BODY_PREFIXES`, so the proxy answers it with a `Response`
     * carrying a producer closure instead of a body. An MCP tool call has no
     * browser socket for that closure to write to, so decoding the (empty) body
     * would report a successful call that returned nothing.
     */
    public function test_a_byte_streaming_path_is_refused_rather_than_returned_empty(): void
    {
        $outcome = $this->context()->proxyGet(self::SERVER_OF_A, '/media/media-1/stream');

        self::assertSame(
            501,
            $outcome['status'],
            'proxyGet() let a byte-streaming response through. Its body is written by a producer '
            . 'callback to a socket that does not exist here, so the caller would receive an EMPTY '
            . 'SUCCESS instead of an error.',
        );
        self::assertSame('mcp.streaming_unsupported', $outcome['payload']['code'] ?? null);
    }

    /**
     * The succeeding control: the same fixture, same owner, same relay state —
     * but a BUFFERED path — must not be refused with 501.
     *
     * Without this, a guard that returned 501 unconditionally would pass the
     * test above. `/api/v1/libraries` is buffered, so it gets as far as the
     * bridge and fails there (see {@see McpCrossUserIsolationTest} on why
     * reaching the bridge is a loud failure) — the one thing it must NOT be is
     * `mcp.streaming_unsupported`.
     */
    public function test_a_buffered_path_is_not_caught_by_the_streaming_guard(): void
    {
        $code = null;
        try {
            $outcome = $this->context()->proxyGet(self::SERVER_OF_A, '/api/v1/libraries');
            $code = $outcome['payload']['code'] ?? null;
        } catch (\Error $e) {
            // Reaching the uninitialised bridge is itself proof this path was
            // NOT diverted by the streaming guard: the guard returns, it never
            // throws. Which engine-level Error the half-built bridge raises is
            // an implementation detail of RelayProxyBridge's property shape
            // (today: "Value of type null is not callable"), so this asserts the
            // CLASS of failure and where it came from, not the wording.
            self::assertStringContainsString(
                'RelayProxyBridge',
                $e->getFile() . ' ' . $e->getMessage(),
                sprintf(
                    'the buffered path failed with "%s", which did not originate in RelayProxyBridge — so '
                    . 'it is not evidence that the request got past the streaming guard and reached the '
                    . 'tunnel boundary.',
                    $e->getMessage(),
                ),
            );

            return;
        }

        self::assertNotSame(
            'mcp.streaming_unsupported',
            $code,
            'a BUFFERED path was refused by the streaming guard, so that guard is refusing '
            . 'everything and the 501 asserted above proves nothing.',
        );
    }

    /**
     * The proxy's browse-scope gate applies to the MCP path — a path outside
     * `BROWSE_SCOPE_ALLOWLIST` is refused, on an OWNED server with a LIVE relay.
     *
     * This is the "no second route to the tunnel" assertion. If a tool ever
     * reached {@see RelayProxyBridge} by some path other than
     * {@see ServerProxyController::proxy()}, everything
     * `BROWSE_SCOPE_ALLOWLIST` / `SCOPE_DENY_PATTERNS` closes would silently
     * re-open. Every refusal condition in the sibling isolation suite (404
     * unknown, 403 not-owned, 503 relay-down) fires BEFORE the allowlist is
     * consulted, so none of them can see this gate. This one can: the server is
     * owned and the relay is up, so the allowlist is the only thing left to say
     * no.
     */
    public function test_a_path_outside_the_browse_allowlist_is_refused_on_the_mcp_path(): void
    {
        $outcome = $this->context()->proxyGet(self::SERVER_OF_A, '/api/v1/admin/users');

        self::assertSame(403, $outcome['status']);
        self::assertSame(
            'proxy.scope_denied',
            $outcome['payload']['code'] ?? null,
            'an MCP tool reached a path the relay browse allowlist does not cover. Either the '
            . 'allowlist is not being consulted on this path, or it has been widened.',
        );
    }

    /**
     * The discriminating half: the paths the SHIPPED tools actually forward must
     * be INSIDE the allowlist.
     *
     * Without this, the refusal above would be satisfied by an allowlist that
     * refused everything — and the whole MCP tool set would be dead while its
     * tests stayed green. Each of these must get PAST the allowlist and reach
     * the uninitialised bridge, which throws.
     *
     * @dataProvider shippedToolPathProvider
     */
    public function test_the_paths_the_shipped_tools_forward_are_inside_the_browse_allowlist(
        string $path,
    ): void {
        try {
            $outcome = $this->context()->proxyGet(self::SERVER_OF_A, $path);
        } catch (\Error) {
            // Reached the tunnel boundary => the allowlist admitted it.
            $this->addToAssertionCount(1);

            return;
        }

        self::assertNotSame(
            'proxy.scope_denied',
            $outcome['payload']['code'] ?? null,
            sprintf(
                '"%s" is forwarded by a shipped MCP tool but the relay browse allowlist refuses it, so '
                . 'that tool can never succeed against any server.',
                $path,
            ),
        );
    }

    /**
     * The forward paths of the four proxying tools, restated here rather than
     * read back out of the tool classes — a check derived from its subject
     * self-adjusts with it and could never disagree.
     *
     * @return list<array{0: string}>
     */
    public static function shippedToolPathProvider(): array
    {
        return [
            'list_libraries' => ['/api/v1/libraries'],
            'search_media' => ['/api/v1/media/search'],
            'get_media' => ['/api/v1/media/media-1'],
            'get_playback_info' => ['/api/v1/media/media-1/playback-info'],
        ];
    }

    /**
     * Fixture guard: the path used above must really be classified as streaming
     * by the production controller, and the control path must really not be.
     * If both were buffered, the first test could only ever have been vacuous.
     */
    public function test_the_fixture_paths_are_classified_as_this_file_assumes(): void
    {
        $isStreaming = (new ReflectionClass(ServerProxyController::class))->getMethod('isStreamingPath');

        $controller = $this->proxyController();
        self::assertTrue(
            $isStreaming->invoke($controller, 'GET', '/media/media-1/stream'),
            'the proxy does not treat /media/{id}/stream as a streaming path, so the 501 test above '
            . 'is not exercising the streaming branch at all.',
        );
        self::assertFalse(
            $isStreaming->invoke($controller, 'GET', '/api/v1/libraries'),
            'the control path is ALSO streaming, so it is not a control.',
        );
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function context(): McpToolContext
    {
        return new McpToolContext(
            new McpToken('token-row-1', self::USER_A, McpScopes::all()),
            $this->proxyController(),
            new ServerListController($this->createMock(ServerInfoHandler::class)),
            '203.0.113.7',
        );
    }

    /**
     * The real proxy controller, owning the server, with an ACTIVE relay — so
     * the request gets past the 503 and reaches the streaming/buffered fork.
     */
    private function proxyController(): ServerProxyController
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

        return new ServerProxyController(
            $serverInfo,
            (new ReflectionClass(RelayProxyBridge::class))->newInstanceWithoutConstructor(),
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600, 1000),
        );
    }
}
