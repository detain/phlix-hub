<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\McpController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\JsonRpc;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToken;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Mcp\McpToolInterface;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Mcp\Tools\GetMediaTool;
use Phlix\Hub\Mcp\Tools\GetPlaybackInfoTool;
use Phlix\Hub\Mcp\Tools\ListLibrariesTool;
use Phlix\Hub\Mcp\Tools\ListServersTool;
use Phlix\Hub\Mcp\Tools\SearchMediaTool;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Tests\Support\RecordingMcpTool;
use Phlix\Hub\Version;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use Workerman\MySQL\Connection;

use function array_keys;
use function hash;
use function implode;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function sort;
use function str_contains;
use function str_repeat;

/**
 * Unit tests for {@see McpController} — the `POST /mcp` JSON-RPC endpoint (S62).
 *
 * The controller is built with the REAL {@see ServerProxyController},
 * {@see ServerListController}, {@see McpToolRegistry} and a REAL
 * {@see RateLimiter}; only the token store and the DB-backed handlers are
 * doubled, because those are the boundaries this suite is not testing.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\McpController
 * @covers \Phlix\Hub\Mcp\JsonRpc
 */
final class McpControllerTest extends TestCase
{
    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string GOOD_TOKEN = 'phlix-mcp-0123456789abcdef';

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    public function test_a_request_without_a_bearer_token_is_401(): void
    {
        $response = $this->controller()->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', self::body($response)['code'] ?? null);
        self::assertArrayHasKey('WWW-Authenticate', $response->headers);
    }

    /**
     * A credential-less flood must not mint limiter buckets — the 401 above has
     * to be cheaper than a limiter write, or the limiter becomes the amplifier.
     */
    public function test_a_missing_credential_does_not_touch_the_rate_limiter(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::never())->method('peek');
        $limiter->expects(self::never())->method('hit');

        $response = $this->controller(limiter: $limiter)->handle($this->request('{}'));

        self::assertSame(401, $response->statusCode);
    }

    public function test_an_unknown_token_is_401_and_counts_against_the_limiter(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('peek')->willReturn(new RateLimitState(0, 10, 0, false, 10));
        $limiter->expects(self::once())->method('hit')
            ->willReturn(new RateLimitState(1, 9, 0, false, 10));
        $limiter->expects(self::never())->method('reset');

        $response = $this->controller(limiter: $limiter, validToken: null)
            ->handle($this->request('{}', self::GOOD_TOKEN));

        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.invalid_token', self::body($response)['code'] ?? null);
    }

    /**
     * ...and a SUCCESSFUL call resets the bucket instead of consuming it, which
     * is login's behaviour and the reason a busy agent never locks itself out.
     */
    public function test_a_valid_token_resets_the_limiter_and_never_hits_it(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('peek')->willReturn(new RateLimitState(0, 10, 0, false, 10));
        $limiter->expects(self::never())->method('hit');
        $limiter->expects(self::once())->method('reset');

        $response = $this->controller(limiter: $limiter)
            ->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"ping"}', self::GOOD_TOKEN));

        self::assertSame(200, $response->statusCode);
    }

    public function test_an_exhausted_window_throws_the_shared_rate_limit_exception(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('peek')->willReturn(
            new RateLimitState(10, 0, 4102444800, true, 10),
        );

        $this->expectException(RateLimitException::class);

        $this->controller(limiter: $limiter)->handle($this->request('{}', self::GOOD_TOKEN));
    }

    /**
     * The wrong KIND of credential gets a message that says so. A hub session
     * JWT presented here is a configuration mistake, not an attack, and the
     * 401 should be actionable.
     */
    public function test_a_non_mcp_credential_is_told_which_credential_it_needs(): void
    {
        $response = $this->controller(validToken: null)
            ->handle($this->request('{}', 'eyJhbGciOiJIUzI1NiJ9.not-an-mcp-token'));

        self::assertSame(401, $response->statusCode);
        $message = self::body($response)['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('/api/v1/me/mcp-tokens', $message);
    }

    // ------------------------------------------------------------------
    // JSON-RPC envelope
    // ------------------------------------------------------------------

    public function test_malformed_json_is_a_parse_error(): void
    {
        $response = $this->controller()->handle($this->request('{not json', self::GOOD_TOKEN));

        $body = self::body($response);
        self::assertSame(200, $response->statusCode, 'a JSON-RPC error still rides an HTTP 200.');
        self::assertSame(JsonRpc::PARSE_ERROR, self::errorCode($body));
        self::assertArrayHasKey('id', $body, 'the `id` key must be PRESENT and null, not omitted.');
        self::assertNull($body['id'], 'a parse error must answer with id: null (JSON-RPC 2.0 §5).');
    }

    /**
     * An authenticated POST with NO body at all is an invalid request, named as
     * such — not a parse error, and certainly not a 202.
     *
     * `json_decode('')` is a parse error, so without the explicit empty-body
     * branch this would be reported as malformed JSON, which sends the client
     * looking for a syntax mistake in a body it never sent.
     */
    public function test_an_empty_body_is_a_named_invalid_request(): void
    {
        $body = self::body($this->controller()->handle($this->request('', self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        self::assertIsString($error['message']);
        self::assertStringContainsString('Empty request body', $error['message']);
    }

    /**
     * A request that HAS an `id` but no `method` is answered with an error that
     * echoes that id — the client is waiting on it.
     */
    public function test_a_request_without_a_method_is_an_invalid_request_that_echoes_its_id(): void
    {
        $body = self::body($this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","id":11}', self::GOOD_TOKEN),
        ));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
        self::assertSame(11, $body['id'] ?? null);
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        self::assertIsString($error['message']);
        self::assertStringContainsString('Missing "method"', $error['message']);
    }

    /**
     * ...but the same malformed envelope WITHOUT an `id` is a notification, and
     * a notification is never answered — not even to say it was malformed.
     *
     * This is the control beside the test above: the two envelopes differ only
     * by the presence of `id`, so a 202 here and a named error there proves the
     * `id`-presence branch is what decides, rather than "everything without a
     * method is silently accepted".
     */
    public function test_a_methodless_notification_is_silently_accepted(): void
    {
        $response = $this->controller()->handle(
            $this->request('{"jsonrpc":"2.0"}', self::GOOD_TOKEN),
        );

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    public function test_a_json_scalar_body_is_an_invalid_request(): void
    {
        $response = $this->controller()->handle($this->request('"hello"', self::GOOD_TOKEN));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode(self::body($response)));
    }

    public function test_a_batch_is_refused_by_name(): void
    {
        $batch = '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]';

        $body = self::body($this->controller()->handle($this->request($batch, self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        self::assertIsString($error['message']);
        self::assertStringContainsString('Batched', $error['message']);
    }

    public function test_an_oversized_body_is_refused_before_being_parsed(): void
    {
        $huge = '{"jsonrpc":"2.0","id":1,"method":"ping","padding":"' . str_repeat('x', 300000) . '"}';

        $body = self::body($this->controller()->handle($this->request($huge, self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
    }

    public function test_an_unknown_method_is_method_not_found(): void
    {
        $body = self::body($this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","id":7,"method":"resources/list"}', self::GOOD_TOKEN),
        ));

        self::assertSame(JsonRpc::METHOD_NOT_FOUND, self::errorCode($body));
        self::assertSame(7, $body['id'] ?? null, 'the request id must be echoed on an error too.');
    }

    /**
     * A notification (no `id`) gets an empty 202 and NEVER a body — a client
     * that gets one would wait for a reply that JSON-RPC forbids.
     */
    public function test_a_notification_gets_an_empty_202(): void
    {
        $response = $this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","method":"notifications/initialized"}', self::GOOD_TOKEN),
        );

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    /**
     * ...including a notification naming a method that does not exist: still no
     * body, because §4.1 says a notification is never answered.
     */
    public function test_a_notification_for_an_unknown_method_is_still_silent(): void
    {
        $response = $this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","method":"nonsense/does-not-exist"}', self::GOOD_TOKEN),
        );

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    // ------------------------------------------------------------------
    // MCP methods
    // ------------------------------------------------------------------

    public function test_initialize_reports_the_protocol_version_and_server_info(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertSame(McpController::PROTOCOL_VERSION, $result['protocolVersion']);
        self::assertSame(['name' => 'phlix-hub', 'version' => Version::VERSION], $result['serverInfo']);
        self::assertArrayNotHasKey(
            '_meta',
            $result,
            'the client asked for the version it got, so there is nothing to disclose.',
        );
    }

    /**
     * S62 does not negotiate. When the client asks for a different revision the
     * response says so rather than letting the client assume it was honoured —
     * negotiation itself is S63.
     */
    public function test_initialize_discloses_that_a_different_requested_version_was_not_negotiated(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05"}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertSame(McpController::PROTOCOL_VERSION, $result['protocolVersion']);
        /** @var array<string, mixed> $meta */
        $meta = $result['_meta'];
        self::assertSame('2024-11-05', $meta['phlix/protocolVersionRequested']);
        self::assertFalse($meta['phlix/protocolVersionNegotiated']);
    }

    public function test_tools_list_names_every_registered_tool(): void
    {
        $body = self::body($this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', self::GOOD_TOKEN),
        ));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        /** @var list<array<string, mixed>> $tools */
        $tools = $result['tools'];

        $names = [];
        foreach ($tools as $tool) {
            $names[] = $tool['name'];
            self::assertArrayHasKey('inputSchema', $tool);
            self::assertArrayHasKey('description', $tool);
        }
        sort($names);

        self::assertSame(
            ['get_media', 'get_playback_info', 'list_libraries', 'list_servers', 'search_media'],
            $names,
        );
    }

    public function test_tools_call_runs_the_tool_and_returns_an_mcp_result(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertFalse($result['isError']);
        /** @var list<array<string, mixed>> $content */
        $content = $result['content'];
        self::assertSame('text', $content[0]['type']);
        /** @var array<string, mixed> $structured */
        $structured = $result['structuredContent'];
        self::assertArrayHasKey('servers', $structured);
    }

    /**
     * A refused tool call is `isError: true` on a NORMAL result, not a JSON-RPC
     * error: the call worked, the answer was "no", and the model needs to read
     * the reason.
     */
    public function test_a_refused_tool_call_is_an_error_result_not_a_transport_error(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":'
            . '{"name":"list_libraries","arguments":{"server_id":"someone-elses-server"}}}',
            self::GOOD_TOKEN,
        )));

        self::assertArrayNotHasKey('error', $body, 'an application-level refusal is not a JSON-RPC error.');
        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertTrue($result['isError']);
        /** @var array<string, mixed> $structured */
        $structured = $result['structuredContent'];
        self::assertSame('server.not_found', $structured['code'] ?? null);
    }

    public function test_an_unknown_tool_name_is_reported_as_an_error_result(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"drop_database","arguments":{}}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertTrue($result['isError']);
        /** @var array<string, mixed> $structured */
        $structured = $result['structuredContent'];
        self::assertSame('mcp.unknown_tool', $structured['code'] ?? null);
    }

    public function test_missing_tool_arguments_are_invalid_params(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"list_libraries","arguments":{}}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
    }

    public function test_tools_call_without_a_name_is_invalid_params(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
    }

    /**
     * The token's scopes gate the call, and a narrow token stays narrow.
     */
    public function test_a_narrow_token_cannot_call_a_tool_outside_its_scopes(): void
    {
        $narrow = new McpToken('row-1', self::USER_A, [McpScopes::SERVERS_READ]);

        $body = self::body($this->controller(validToken: $narrow)->handle($this->request(
            '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":'
            . '{"name":"list_libraries","arguments":{"server_id":"srv-1"}}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertTrue($result['isError']);
        /** @var array<string, mixed> $structured */
        $structured = $result['structuredContent'];
        self::assertSame('mcp.scope_denied', $structured['code'] ?? null);
    }

    /**
     * Control for the test above: the SAME narrow token CAN call the tool its
     * one scope covers. Without this, a blanket "everything is denied" bug would
     * look identical to working scope enforcement.
     */
    public function test_the_same_narrow_token_can_still_call_the_tool_it_is_scoped_for(): void
    {
        $narrow = new McpToken('row-1', self::USER_A, [McpScopes::SERVERS_READ]);

        $body = self::body($this->controller(validToken: $narrow)->handle($this->request(
            '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"list_servers","arguments":{}}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertFalse($result['isError']);
    }

    // ------------------------------------------------------------------
    // S244: the positional-key drop in stringKeyed()
    // ------------------------------------------------------------------

    /**
     * The vector this suite's next three tests depend on must actually exist.
     *
     * `json_decode($raw, true)` canonicalises a DECIMAL-INTEGER object key back
     * to a PHP `int`, so a JSON body of `{"0":"…"}` produces an integer-keyed
     * entry even though JSON itself has only string keys. That is the entire
     * reason `stringKeyed()` has to filter. If a future PHP stopped doing it,
     * the pins below would pass because the attack is impossible rather than
     * because the filter works — the classic vacuous green — so the vector is
     * asserted here rather than assumed.
     */
    public function test_a_json_object_really_can_produce_an_integer_key(): void
    {
        /** @var mixed $decoded */
        $decoded = json_decode('{"0":"positional","server_id":"srv-1"}', true);
        self::assertIsArray($decoded);

        self::assertSame(
            [0, 'server_id'],
            array_keys($decoded),
            'json_decode no longer folds a decimal-string object key to an int, so the positional-key '
            . 'vector stringKeyed() defends against cannot be constructed and the tests below are vacuous.',
        );
    }

    /**
     * **S244 / mutation M24.** A positional (integer) key in `arguments` must
     * never reach a tool.
     *
     * `stringKeyed()`'s whole job is to drop it. Nothing observed it before:
     * every shipped tool reads its arguments BY NAME, so an extra `0 => "…"`
     * entry changes no shipped tool's ANSWER, and deleting the filter left the
     * suite green. The observable consequence is therefore what a tool
     * RECEIVES, and this stands at that boundary — a real
     * {@see McpToolInterface} in the real {@see McpToolRegistry}, invoked
     * through the real `POST /mcp` dispatcher — and asserts the map handed over
     * is exactly the string-keyed subset.
     *
     * Removing the filter (or its `ARRAY_FILTER_USE_KEY` flag) reds this test.
     */
    public function test_a_positional_key_in_the_arguments_map_never_reaches_a_tool(): void
    {
        $probe = new RecordingMcpTool();

        $body = self::body($this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":"recording_probe",'
            . '"arguments":{"0":"positional-smuggled","server_id":"srv-1","2":"and-another"}}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertFalse($result['isError'], 'the probe must have RUN; a refusal would prove nothing.');
        self::assertSame(1, $probe->calls);

        self::assertSame(
            ['server_id' => 'srv-1'],
            $probe->received,
            'a positional (integer) key reached the tool. McpController::stringKeyed() exists to drop '
            . 'exactly that before the params map is handed to a tool: JSON-RPC params may legitimately '
            . 'be positional, MCP tool arguments are by-name only, and a tool typed '
            . 'array<string, mixed> must not be handed an int-keyed entry it never declared.',
        );

        foreach (array_keys((array) $probe->received) as $key) {
            self::assertFalse(is_int($key), 'an integer key survived into the tool arguments.');
        }
    }

    /**
     * The succeeding control beside the pin above: a legitimately string-keyed
     * `arguments` map reaches the tool INTACT — same keys, same values, same
     * order.
     *
     * Without this, a `stringKeyed()` that returned `[]` unconditionally would
     * satisfy the test above perfectly while breaking every tool call in
     * production. The pin asserts what must be dropped; this asserts what must
     * not be.
     */
    public function test_a_string_keyed_arguments_map_reaches_the_tool_intact(): void
    {
        $probe = new RecordingMcpTool();

        $this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":21,"method":"tools/call","params":{"name":"recording_probe",'
            . '"arguments":{"server_id":"srv-1","query":"dune","limit":5,"nested":{"a":1}}}}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(1, $probe->calls);
        self::assertSame(
            ['server_id' => 'srv-1', 'query' => 'dune', 'limit' => 5, 'nested' => ['a' => 1]],
            $probe->received,
            'the filter dropped or reordered legitimate by-name arguments.',
        );
    }

    /**
     * `arguments` that is not a map at all — a JSON array, or a scalar — reaches
     * the tool as `[]`, not as a half-read positional list.
     *
     * This is the `!is_array($raw)` / positional-list half of the same helper:
     * a JSON-RPC caller sending `"arguments": ["srv-1"]` is using positional
     * params, which MCP's by-name tool arguments cannot express. Collapsing to
     * an empty map makes the tool answer "argument missing"; passing the list
     * through would give a tool an argument at key `0` that its schema never
     * declared.
     *
     * @dataProvider unusableArgumentsProvider
     */
    public function test_arguments_that_are_not_a_by_name_map_arrive_empty(string $argumentsJson): void
    {
        $probe = new RecordingMcpTool();

        $this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"recording_probe",'
            . '"arguments":' . $argumentsJson . '}}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(1, $probe->calls);
        self::assertSame([], $probe->received);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableArgumentsProvider(): array
    {
        return [
            'a positional JSON array' => ['["srv-1","dune"]'],
            'a JSON string' => ['"srv-1"'],
            'a JSON number' => ['7'],
            'JSON null' => ['null'],
        ];
    }

    /**
     * The same coercion applies one level up, to `params` itself: a positional
     * `params` cannot name a tool, so `tools/call` answers invalid-params rather
     * than reading `params[0]` as the tool name.
     *
     * ⚠ Unlike the three tests above, this one does NOT distinguish a filtered
     * `params` from an unfiltered one — `callTool()` reads `params['name']`, and
     * an unfiltered `[0 => 'recording_probe']` has no `name` key either. It is
     * recorded as a behaviour assertion (MCP names its tool by key, never by
     * position), not as part of the M24 pin. That the `params` call site has no
     * observable consequence today is a finding, not an oversight: the only
     * readers of that map — `callTool()` and `initializeResult()` — are by-name
     * lookups. The `arguments` call site is the one that hands its map onward,
     * and that is where the pin sits.
     */
    public function test_positional_params_cannot_name_a_tool(): void
    {
        $probe = new RecordingMcpTool();

        $body = self::body($this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":23,"method":"tools/call","params":["recording_probe",{}]}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
        self::assertSame(0, $probe->calls, 'a positionally-named tool was invoked.');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param McpToken|false|null $validToken `false` (the default) means "the
     *        real service validates {@see GOOD_TOKEN} to a full-scope token for
     *        USER_A"; `null` means validation fails; an {@see McpToken} means the
     *        service resolves to exactly that.
     */
    private function controller(
        ?RateLimiterInterface $limiter = null,
        McpToken|false|null $validToken = false,
        ?RecordingMcpTool $extraTool = null,
    ): McpController {
        $resolved = $validToken === false
            ? new McpToken('row-1', self::USER_A, McpScopes::all())
            : $validToken;

        // The REAL token service over a doubled Connection, rather than a
        // doubled service: that keeps the SQL, the SHA-256 hashing and the
        // fail-closed row handling in {@see McpTokenService::validate()} under
        // test instead of replaced by a stub that always says yes.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use ($resolved): array {
                if (!str_contains($sql, 'FROM mcp_tokens')) {
                    return [];
                }
                if ($resolved === null) {
                    return [];
                }
                // Only the hash of the real plaintext resolves — a different
                // presented token still gets nothing.
                if (($params['token_hash'] ?? null) !== hash('sha256', self::GOOD_TOKEN)) {
                    return [];
                }

                return [[
                    'id' => $resolved->id,
                    'user_id' => $resolved->userId,
                    'scopes' => implode(' ', $resolved->scopes),
                ]];
            },
        );
        $tokens = new McpTokenService($db);

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getOwnerAndStatus')->willReturn(null);
        $serverInfo->method('getServersForUser')->willReturn([self::dto()]);

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('checkUserQuota')->willReturn([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 0,
        ]);

        $proxy = new ServerProxyController(
            $serverInfo,
            (new ReflectionClass(RelayProxyBridge::class))->newInstanceWithoutConstructor(),
            $this->createMock(StructuredLogger::class),
            $sessions,
            new RateLimiter(60, 600, 1000),
        );

        /** @var list<McpToolInterface> $tools */
        $tools = [
            new ListServersTool(),
            new ListLibrariesTool(),
            new SearchMediaTool(),
            new GetMediaTool(),
            new GetPlaybackInfoTool(),
        ];
        if ($extraTool !== null) {
            $tools[] = $extraTool;
        }

        return new McpController(
            $tokens,
            new McpToolRegistry($tools),
            $proxy,
            new ServerListController($serverInfo),
            $limiter ?? new RateLimiter(900, 10, 1000),
            $this->createMock(StructuredLogger::class),
        );
    }

    private function request(string $rawBody, ?string $bearer = null): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/mcp';
        $request->rawBody = $rawBody;
        $request->remoteIp = '198.51.100.4';
        if ($bearer !== null) {
            $request->headers['AUTHORIZATION'] = 'Bearer ' . $bearer;
            $request->bearerToken = $bearer;
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertTrue(is_array($decoded), 'response body was not a JSON object: ' . $response->body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function errorCode(array $body): int
    {
        self::assertArrayHasKey('error', $body, 'expected a JSON-RPC error, got: ' . (string) json_encode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        self::assertIsInt($error['code']);

        return $error['code'];
    }

    private static function dto(): ServerInfoDto
    {
        return new ServerInfoDto(
            serverId: 'srv-of-a',
            userId: self::USER_A,
            serverName: 'Test server',
            version: '1.0.0',
            lastSeenAt: null,
            status: ServerInfoDto::STATUS_ONLINE,
            hostnameCandidates: [],
            relayActive: true,
        );
    }
}
