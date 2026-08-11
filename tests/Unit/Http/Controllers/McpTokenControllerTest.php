<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\McpTokenController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function is_array;
use function json_decode;
use function str_contains;

/**
 * Unit tests for {@see McpTokenController} — minting, listing and revoking MCP
 * personal access tokens (S62).
 *
 * The service is the REAL {@see McpTokenService} over a doubled
 * {@see Connection}, so the SQL and the hashing stay under test.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class McpTokenControllerTest extends TestCase
{
    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /**
     * @dataProvider unauthenticatedCallProvider
     */
    public function testEveryRouteRefusesAnUnauthenticatedCaller(string $method): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $controller = new McpTokenController(
            new McpTokenService($db),
            $this->createMock(AuditLogger::class),
            false,
        );

        $response = $controller->{$method}(new Request(), ['id' => 'row-1']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', self::body($response)['code'] ?? null);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unauthenticatedCallProvider(): array
    {
        return [['index'], ['create'], ['revoke']];
    }

    public function testCreateReturnsThePlaintextExactlyOnce(): void
    {
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $response = $this->controller($db)->create($this->request([
            'name' => 'Claude Desktop',
            'scopes' => [McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ],
        ]));

        self::assertSame(201, $response->statusCode);
        $body = self::body($response);
        self::assertIsString($body['token']);
        self::assertStringStartsWith(McpTokenService::TOKEN_PREFIX, $body['token']);
        self::assertSame([McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ], $body['scopes']);
        // The row is bound to the AUTHENTICATED user, never to a body field.
        self::assertSame(self::USER_A, $captured['user_id']);
    }

    /**
     * A body naming somebody else's user id changes nothing: the row is bound
     * to `$request->userId`.
     */
    public function testAUserIdInTheBodyIsIgnored(): void
    {
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $this->controller($db)->create($this->request([
            'name' => 'x',
            'user_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ]));

        self::assertSame(self::USER_A, $captured['user_id']);
    }

    public function testAScopeListWithNothingKnownInItIsRefused(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $response = $this->controller($db)->create($this->request(['scopes' => ['admin:*', 'root']]));

        self::assertSame(400, $response->statusCode);
        self::assertSame('mcp_token.no_valid_scopes', self::body($response)['code'] ?? null);
    }

    /**
     * ⚠ **THE S261 DEFAULT.** Omitting `scopes` grants the READ-ONLY set.
     *
     * This is also the control for the refusal above — omitting the field mints
     * rather than 400s, so that 400 is about UNRECOGNISED scopes and not about
     * "any request without an explicit list".
     *
     * Before S261 this granted `McpScopes::all()`, so an API caller who said
     * nothing about scopes received `mcp:playback:control`, the only WRITE
     * capability in the vocabulary. The test that stood here was named
     * `test_omitting_scopes_grants_the_full_read_set` and asserted
     * `McpScopes::all()` — the name described the intended behaviour and the
     * assertion pinned the actual one, so the disagreement between them was
     * invisible to the suite. It is written out as a literal list now for the
     * same reason `McpScopesTest` does: the granted set is an interface.
     */
    public function testOmittingScopesGrantsTheReadOnlySetAndNotTheWriteScope(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $response = $this->controller($db)->create($this->request(['name' => 'x']));

        self::assertSame(201, $response->statusCode);
        $granted = self::body($response)['scopes'];

        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            $granted,
            'the scope set granted to a caller who asked for nothing changed.',
        );

        // Exact per-member comparison, never a substring test: `mcp:playback`
        // is a PREFIX of `mcp:playback:control`, so `str_contains` would also
        // fire on the read scope that legitimately IS in this list.
        self::assertIsArray($granted);
        foreach ($granted as $scope) {
            self::assertNotSame(McpScopes::PLAYBACK_CONTROL, $scope);
        }
    }

    /**
     * 🚨 **THE SUCCEEDING CONTROL for the test above.** Asking for
     * `mcp:playback:control` explicitly still mints it.
     *
     * Without this row, the exclusion above is indistinguishable from the write
     * scope having been BANNED, or deleted from the vocabulary, or dropped by
     * `fromArray()`. All three would leave the default-scope test green and all
     * three are different, shipped behaviour. What S261 changed is what happens
     * when the caller says NOTHING; what happens when the caller says the word
     * is unchanged, and that is what this asserts.
     *
     * Note the flag is OFF here (the fixture default). Mint is deliberately not
     * gated on `mcp_playback_control_enabled`: the flag is a runtime switch an
     * operator can flip without every agent re-minting.
     */
    public function testAnExplicitRequestForTheWriteScopeIsStillGranted(): void
    {
        $db = $this->createMock(Connection::class);
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $response = $this->controller($db)->create(
            $this->request(['name' => 'agent', 'scopes' => ['mcp:playback:control']]),
        );

        self::assertSame(201, $response->statusCode);
        self::assertSame(['mcp:playback:control'], self::body($response)['scopes']);
        // ...and it is what reached the column, not just the response envelope.
        self::assertSame('mcp:playback:control', $captured['scopes'] ?? null);
    }

    /**
     * A mixed explicit list is granted whole, in `McpScopes::all()` order.
     *
     * The order is load-bearing: `McpScopes::parse()` emits in `all()` order, so
     * it is the stored representation of `mcp_tokens.scopes`. The request below
     * deliberately sends the write scope FIRST, so a controller that preserved
     * caller order would store a different string for the same grant.
     */
    public function testAnExplicitMixedListIsGrantedWholeAndInVocabularyOrder(): void
    {
        $db = $this->createMock(Connection::class);
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                $captured = $params;

                return [];
            },
        );

        $response = $this->controller($db)->create($this->request([
            'scopes' => ['mcp:playback:control', 'mcp:servers:read'],
        ]));

        self::assertSame(201, $response->statusCode);
        self::assertSame(['mcp:servers:read', 'mcp:playback:control'], self::body($response)['scopes']);
        self::assertSame('mcp:servers:read mcp:playback:control', $captured['scopes'] ?? null);
    }

    /**
     * With `playback_control` OFF — the shipped default — `available_scopes`
     * omits the write scope.
     *
     * The flag gates REGISTRATION of `PlaybackControlTool`, so with it off the
     * scope names a tool that appears in no `tools/list` and answers
     * `mcp.unknown_tool`. Advertising it anyway told the `/app/mcp-tokens`
     * create form (which builds its checkboxes from this list and pre-ticks
     * every one) to offer a capability the server would not honour.
     */
    public function testIndexOmitsTheWriteScopeFromAvailableScopesWhenTheFlagIsOff(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'FROM mcp_tokens')) {
                    return [];
                }
                self::assertSame(self::USER_A, $params['user_id']);

                return [];
            },
        );

        $response = $this->controller($db)->index($this->request());

        self::assertSame(200, $response->statusCode);
        $body = self::body($response);
        self::assertSame([], $body['tokens']);
        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            $body['available_scopes'],
        );

        self::assertIsArray($body['available_scopes']);
        foreach ($body['available_scopes'] as $scope) {
            self::assertNotSame(McpScopes::PLAYBACK_CONTROL, $scope);
        }
    }

    /**
     * 🚨 **THE SUCCEEDING CONTROL for the advertisement.** With the flag ON,
     * `available_scopes` is the whole vocabulary again.
     *
     * Without it, a controller that had simply hard-coded the three read scopes
     * — or deleted `mcp:playback:control` from the vocabulary altogether —
     * would satisfy the test above exactly, and the list would be a ban rather
     * than a reflection of the operator's setting.
     */
    public function testIndexAdvertisesTheWriteScopeWhenTheOperatorEnabledIt(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $response = $this->controller($db, playbackControlEnabled: true)->index($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read', 'mcp:playback:control'],
            self::body($response)['available_scopes'],
        );
        self::assertSame(McpScopes::all(), self::body($response)['available_scopes']);
    }

    /**
     * The 400 envelope advertises the same filtered list as `index()`.
     *
     * It is the other place a client learns the vocabulary — it is what a caller
     * who guessed wrong reads to correct themselves — and it had the same
     * unconditional `McpScopes::all()`. Telling somebody who just sent an
     * unusable scope to try `mcp:playback:control` next would be a second wrong
     * answer.
     *
     * @dataProvider flagProvider
     *
     * @param list<string> $expected
     */
    public function testTheNoValidScopesErrorAdvertisesTheFlagFilteredList(
        bool $flag,
        array $expected,
    ): void {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $response = $this->controller($db, playbackControlEnabled: $flag)
            ->create($this->request(['scopes' => ['admin:*']]));

        self::assertSame(400, $response->statusCode);
        $body = self::body($response);
        self::assertSame('mcp_token.no_valid_scopes', $body['code'] ?? null);
        self::assertSame($expected, $body['available_scopes']);
    }

    /**
     * @return array<string, array{0: bool, 1: list<string>}>
     */
    public static function flagProvider(): array
    {
        return [
            'flag off (shipped default)' => [
                false,
                ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            ],
            'flag on' => [
                true,
                ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read', 'mcp:playback:control'],
            ],
        ];
    }

    public function testRevokingARowThatIsNotYoursIsAnIndistinguishable404(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        $response = $this->controller($db)->revoke($this->request(), ['id' => 'someone-elses-row']);

        self::assertSame(404, $response->statusCode);
        self::assertSame('mcp_token.not_found', self::body($response)['code'] ?? null);
    }

    public function testRevokingYourOwnRowSucceedsAndIsAudited(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(1);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logAdminAction')
            ->with(self::USER_A, 'mcp_token.revoke', 'row-1');

        $controller = new McpTokenController(new McpTokenService($db), $audit, false);
        $response = $controller->revoke($this->request(), ['id' => 'row-1']);

        self::assertSame(200, $response->statusCode);
        self::assertTrue(self::body($response)['revoked']);
    }

    // ------------------------------------------------------------------

    /**
     * @param bool $playbackControlEnabled The `mcp_playback_control_enabled`
     *        operator flag. Defaults to the SHIPPED value (off), so every test
     *        that does not name it is asserting the fresh-deployment state.
     */
    private function controller(Connection $db, bool $playbackControlEnabled = false): McpTokenController
    {
        return new McpTokenController(
            new McpTokenService($db),
            $this->createMock(AuditLogger::class),
            $playbackControlEnabled,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body = []): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/me/mcp-tokens';
        $request->userId = self::USER_A;
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertTrue(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
