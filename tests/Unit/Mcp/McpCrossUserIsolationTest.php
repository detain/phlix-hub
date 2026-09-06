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
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Mcp\Tools\GetMediaTool;
use Phlix\Hub\Mcp\Tools\GetPlaybackInfoTool;
use Phlix\Hub\Mcp\Tools\ListLibrariesTool;
use Phlix\Hub\Mcp\Tools\ListServersTool;
use Phlix\Hub\Mcp\Tools\SearchMediaTool;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function is_array;
use function json_decode;
use function sprintf;

/**
 * S62 acceptance criterion: **a PAT scoped to user A cannot see user B's
 * servers.**
 *
 * ## What makes this test worth trusting
 *
 * The subject is the REAL {@see ServerProxyController} and the REAL
 * {@see ServerListController}, constructed here exactly as
 * `HubServicesProvider` constructs them. Nothing about ownership is
 * re-implemented, stubbed or approximated: the 404/403 answers below come from
 * the same production code path `/api/v1/servers/{id}/proxy/…` uses for the SPA.
 * A test that swapped in a fake proxy would be pinning the fake.
 *
 * ## The bridge is a tripwire, not a stub
 *
 * {@see RelayProxyBridge} is `final`, so it cannot be mocked — and that turns
 * out to be exactly what is wanted. It is built here with
 * {@see ReflectionClass::newInstanceWithoutConstructor()}, leaving its typed
 * readonly properties UNINITIALISED. If any assertion below ever stopped being
 * refused and the request actually reached the tunnel, PHP would raise
 * "typed property … must not be accessed before initialization" and the test
 * would fail LOUDLY rather than quietly passing on a mocked "no". The refusals
 * asserted here are therefore proven to happen BEFORE the bridge, which is the
 * only place they are worth anything.
 *
 * ## What this suite actually drives
 *
 * The REAL {@see ListServersTool}, {@see ListLibrariesTool},
 * {@see SearchMediaTool}, {@see GetMediaTool} and {@see GetPlaybackInfoTool}
 * through {@see McpToolRegistry::call()} and asserts, per tool:
 *
 *  - the four server-scoped tools forward the caller's `server_id` into the
 *    production ownership gate — 403 `server.not_owned` for another user's
 *    server, 404 `server.not_found` for one that does not exist (so the 403 is a
 *    real ownership decision, not a blanket refusal);
 *  - `ListServersTool::call()` asks the handler for the TOKEN's user id and no
 *    other, which is the acceptance criterion of this step.
 *
 * Those are assertions about each tool's `call()`. The descriptor half of each
 * class (`description()`, `inputSchema()`) is exercised and asserted by
 * {@see McpToolRegistryTest}.
 *
 * This file carries no coverage metadata (S311) — in this repository that
 * metadata discards every executed line outside the units it names, and the
 * tag must not be written out in prose here either, because PHPUnit parses it
 * out of a sentence as an invalid entry and discards the attribution anyway.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
final class McpCrossUserIsolationTest extends TestCase
{
    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string USER_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** A server that exists and belongs to user B. */
    private const string SERVER_OF_B = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /** An id that matches no `servers` row at all. */
    private const string SERVER_UNKNOWN = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    /**
     * Every tool that reaches a media server must refuse user A's token when the
     * named server belongs to user B — with the proxy's own 403
     * `server.not_owned`, produced by the production ownership check.
     *
     * Driven through {@see McpToolRegistry::call()} rather than by calling the
     * tool object directly, because the registry is the production entry point
     * and a bypass would not prove the wiring.
     *
     * @dataProvider serverScopedToolProvider
     *
     * @param array<string, mixed> $arguments
     */
    public function testATokenForUserACannotReachAServerOwnedByUserB(
        string $tool,
        array $arguments,
    ): void {
        $registry = self::registry();
        $context = $this->contextFor(self::USER_A);

        $outcome = $registry->call($tool, $arguments, $context);

        self::assertSame(
            403,
            $outcome['status'],
            sprintf(
                'Tool "%s" did NOT refuse a server owned by another user. This is the whole point of '
                . 'S62: every tool call must re-derive the token holder and go through '
                . 'ServerProxyController::proxy(), whose ownership check answers 403 server.not_owned.',
                $tool,
            ),
        );
        self::assertSame('server.not_owned', $outcome['payload']['code'] ?? null);
    }

    /**
     * The same tools must answer 404 for a server that does not exist — proving
     * the 403 above is a real ownership decision and not "everything is refused".
     *
     * A test that only ever saw refusals could not tell a working gate from a
     * broken proxy. This is the succeeding-control-beside-it half: two DIFFERENT
     * refusals from two different branches.
     *
     * @dataProvider serverScopedToolProvider
     *
     * @param array<string, mixed> $arguments
     */
    public function testAnUnknownServerIsA404NotA403(string $tool, array $arguments): void
    {
        /** @var array<string, mixed> $unknownArguments */
        $unknownArguments = $arguments;
        $unknownArguments['server_id'] = self::SERVER_UNKNOWN;

        $outcome = self::registry()->call($tool, $unknownArguments, $this->contextFor(self::USER_A));

        self::assertSame(404, $outcome['status'], sprintf('Tool "%s" mis-classified an unknown server.', $tool));
        self::assertSame('server.not_found', $outcome['payload']['code'] ?? null);
    }

    /**
     * `list_servers` must return only the calling token's own servers.
     *
     * The handler is stubbed at `getServersForUser()` — the DB boundary — and
     * asserts the user id it was asked for. That id is the one thing this step
     * has to get right: it must come from the token, never from an argument.
     */
    public function testListServersAsksTheHandlerOnlyForTheTokensOwnUser(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->expects(self::once())
            ->method('getServersForUser')
            ->with(self::USER_A)
            ->willReturn([self::dto(self::USER_A, 'srv-of-a')]);

        $context = new McpToolContext(
            new McpToken('token-row-1', self::USER_A, McpScopes::all()),
            $this->proxyController($this->createMock(ServerInfoHandler::class)),
            new ServerListController($serverInfo),
        );

        $outcome = self::registry()->call('list_servers', [], $context);

        self::assertSame(200, $outcome['status']);
        /** @var mixed $servers */
        $servers = $outcome['payload']['servers'] ?? null;
        self::assertIsArray($servers);
        self::assertCount(1, $servers);
    }

    /**
     * A tool cannot smuggle another identity in through its arguments.
     *
     * `user_id` is not part of any tool's schema, but a caller can put whatever
     * it likes in the JSON-RPC envelope, so this asserts the behaviour rather
     * than the schema: adding `user_id: USER_B` changes nothing, because
     * {@see McpToolContext} sets the request's user id from the token on every
     * call and reads no argument at all.
     */
    public function testAUserIdSmuggledIntoTheArgumentsIsIgnored(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->expects(self::once())
            ->method('getServersForUser')
            ->with(self::USER_A)
            ->willReturn([]);

        $context = new McpToolContext(
            new McpToken('token-row-1', self::USER_A, McpScopes::all()),
            $this->proxyController($this->createMock(ServerInfoHandler::class)),
            new ServerListController($serverInfo),
        );

        self::registry()->call(
            'list_servers',
            ['user_id' => self::USER_B, 'userId' => self::USER_B, 'sub' => self::USER_B],
            $context,
        );
    }

    /**
     * User B's own token DOES reach the ownership gate's success side — so the
     * 403 above is discriminating, not a blanket refusal.
     *
     * The request is refused one gate LATER (503, relay tunnel not connected),
     * which is the closest observable point to "ownership passed" that does not
     * require a live tunnel. Asserting 503 here rather than 200 is deliberate:
     * a 200 would need the bridge, and the bridge is the tripwire.
     */
    public function testTheOwningUserPassesTheOwnershipGateAndFailsLater(): void
    {
        $outcome = self::registry()->call(
            'list_libraries',
            ['server_id' => self::SERVER_OF_B],
            $this->contextFor(self::USER_B),
        );

        self::assertSame(
            503,
            $outcome['status'],
            'user B was refused on their OWN server, so the 403 asserted elsewhere in this suite '
            . 'cannot be attributed to the ownership check — every request is being refused.',
        );
        self::assertSame('server.relay_unavailable', $outcome['payload']['code'] ?? null);
    }

    /**
     * Scope enforcement lives in the registry, so it applies to every tool
     * without any tool implementing it.
     */
    public function testATokenWithoutTheRequiredScopeIsRefusedBeforeTheToolRuns(): void
    {
        $context = new McpToolContext(
            new McpToken('token-row-1', self::USER_A, [McpScopes::SERVERS_READ]),
            $this->proxyController($this->serverInfoHandler()),
            new ServerListController($this->createMock(ServerInfoHandler::class)),
        );

        $outcome = self::registry()->call('list_libraries', ['server_id' => self::SERVER_OF_B], $context);

        self::assertSame(403, $outcome['status']);
        self::assertSame('mcp.scope_denied', $outcome['payload']['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function serverScopedToolProvider(): array
    {
        return [
            'list_libraries' => ['list_libraries', ['server_id' => self::SERVER_OF_B]],
            'search_media' => ['search_media', ['server_id' => self::SERVER_OF_B, 'query' => 'dune']],
            'get_media' => ['get_media', ['server_id' => self::SERVER_OF_B, 'media_id' => 'media-1']],
            'get_playback_info' => [
                'get_playback_info',
                ['server_id' => self::SERVER_OF_B, 'media_id' => 'media-1'],
            ],
        ];
    }

    /** The production tool set, wired the way the container wires it. */
    private static function registry(): McpToolRegistry
    {
        return new McpToolRegistry([
            new ListServersTool(),
            new ListLibrariesTool(),
            new SearchMediaTool(),
            new GetMediaTool(),
            new GetPlaybackInfoTool(),
        ]);
    }

    /**
     * A context holding a full-scope token for `$userId`.
     */
    private function contextFor(string $userId): McpToolContext
    {
        return new McpToolContext(
            new McpToken('token-row-1', $userId, McpScopes::all()),
            $this->proxyController($this->serverInfoHandler()),
            new ServerListController($this->createMock(ServerInfoHandler::class)),
            '203.0.113.7',
        );
    }

    /**
     * A handler that knows exactly one server, owned by user B, whose relay
     * tunnel is NOT connected.
     */
    private function serverInfoHandler(): ServerInfoHandler
    {
        $handler = $this->createMock(ServerInfoHandler::class);
        $handler->method('getOwnerAndStatus')->willReturnCallback(
            static function (string $serverId): ?array {
                if ($serverId !== self::SERVER_OF_B) {
                    return null;
                }

                return [
                    'userId' => self::USER_B,
                    'status' => ServerInfoDto::STATUS_ONLINE,
                    'relayActive' => false,
                ];
            },
        );

        return $handler;
    }

    /**
     * The REAL proxy controller, with the REAL rate limiter, and a
     * deliberately-uninitialised {@see RelayProxyBridge} (see the class
     * docblock: reaching it is a loud failure, not a silent pass).
     */
    private function proxyController(ServerInfoHandler $serverInfo): ServerProxyController
    {
        $bridge = (new ReflectionClass(RelayProxyBridge::class))->newInstanceWithoutConstructor();

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('checkUserQuota')->willReturn([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 0,
        ]);

        return new ServerProxyController(
            $serverInfo,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600, 1000),
        );
    }

    private static function dto(string $userId, string $serverId): ServerInfoDto
    {
        return new ServerInfoDto(
            serverId: $serverId,
            userId: $userId,
            serverName: 'Test server',
            version: '1.0.0',
            lastSeenAt: null,
            status: ServerInfoDto::STATUS_ONLINE,
            hostnameCandidates: [],
            relayActive: true,
        );
    }

    /**
     * Guard for this file's own fixtures: the ids used above must be distinct,
     * or "user A cannot see user B's server" would be trivially true because
     * there is only one user.
     */
    public function testTheFixtureUsersAndServersAreActuallyDistinct(): void
    {
        self::assertNotSame(self::USER_A, self::USER_B);
        self::assertNotSame(self::SERVER_OF_B, self::SERVER_UNKNOWN);

        // And the handler really does report SERVER_OF_B as belonging to B —
        // if it reported null, every assertion above would pass as a 404 and
        // the ownership branch would never be exercised.
        $owner = $this->serverInfoHandler()->getOwnerAndStatus(self::SERVER_OF_B);
        self::assertIsArray($owner);
        self::assertSame(self::USER_B, $owner['userId']);
    }

    /**
     * The refusals asserted in this suite must be JSON bodies produced by the
     * proxy controller, not empty responses that happen to decode to nothing.
     */
    public function testTheRefusalPayloadsAreRealDecodedJson(): void
    {
        $outcome = self::registry()->call(
            'list_libraries',
            ['server_id' => self::SERVER_OF_B],
            $this->contextFor(self::USER_A),
        );

        self::assertArrayNotHasKey(
            'raw',
            $outcome['payload'],
            'the proxy answered with a body that is not JSON; every assertion on `code` in this suite '
            . 'would then be reading a key that does not exist.',
        );
        /** @var mixed $roundTrip */
        $roundTrip = json_decode((string) json_encode($outcome['payload']), true);
        self::assertTrue(is_array($roundTrip));
    }
}
