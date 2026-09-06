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
use Phlix\Hub\Mcp\McpProtocol;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpSseStream;
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
use Phlix\Hub\Tests\Support\RecordingStreamTimers;
use Phlix\Hub\Version;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\Connection\TcpConnection;
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
 */
final class McpControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private const string USER_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string GOOD_TOKEN = 'phlix-mcp-0123456789abcdef';

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    public function testARequestWithoutABearerTokenIs401(): void
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
    public function testAMissingCredentialDoesNotTouchTheRateLimiter(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->expects(self::never())->method('peek');
        $limiter->expects(self::never())->method('hit');

        $response = $this->controller(limiter: $limiter)->handle($this->request('{}'));

        self::assertSame(401, $response->statusCode);
    }

    public function testAnUnknownTokenIs401AndCountsAgainstTheLimiter(): void
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
    public function testAValidTokenResetsTheLimiterAndNeverHitsIt(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('peek')->willReturn(new RateLimitState(0, 10, 0, false, 10));
        $limiter->expects(self::never())->method('hit');
        $limiter->expects(self::once())->method('reset');

        $response = $this->controller(limiter: $limiter)
            ->handle($this->request('{"jsonrpc":"2.0","id":1,"method":"ping"}', self::GOOD_TOKEN));

        self::assertSame(200, $response->statusCode);
    }

    public function testAnExhaustedWindowThrowsTheSharedRateLimitException(): void
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
    public function testANonMcpCredentialIsToldWhichCredentialItNeeds(): void
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

    public function testMalformedJsonIsAParseError(): void
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
    public function testAnEmptyBodyIsANamedInvalidRequest(): void
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
    public function testARequestWithoutAMethodIsAnInvalidRequestThatEchoesItsId(): void
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
    public function testAMethodlessNotificationIsSilentlyAccepted(): void
    {
        $response = $this->controller()->handle(
            $this->request('{"jsonrpc":"2.0"}', self::GOOD_TOKEN),
        );

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    public function testAJsonScalarBodyIsAnInvalidRequest(): void
    {
        $response = $this->controller()->handle($this->request('"hello"', self::GOOD_TOKEN));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode(self::body($response)));
    }

    public function testABatchIsRefusedByName(): void
    {
        $batch = '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]';

        $body = self::body($this->controller()->handle($this->request($batch, self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        self::assertIsString($error['message']);
        self::assertStringContainsString('Batched', $error['message']);
    }

    public function testAnOversizedBodyIsRefusedBeforeBeingParsed(): void
    {
        $huge = '{"jsonrpc":"2.0","id":1,"method":"ping","padding":"' . str_repeat('x', 300000) . '"}';

        $body = self::body($this->controller()->handle($this->request($huge, self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($body));
    }

    public function testAnUnknownMethodIsMethodNotFound(): void
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
    public function testANotificationGetsAnEmpty202(): void
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
    public function testANotificationForAnUnknownMethodIsStillSilent(): void
    {
        $response = $this->controller()->handle(
            $this->request('{"jsonrpc":"2.0","method":"nonsense/does-not-exist"}', self::GOOD_TOKEN),
        );

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    // ------------------------------------------------------------------
    // S63: JSON-RPC envelope schema validation
    // ------------------------------------------------------------------

    /**
     * THE FAILING INPUT: an envelope without a correct `jsonrpc` member is
     * `INVALID_REQUEST`.
     *
     * S62 never looked at `jsonrpc` at all — a body could claim version 1.0, or
     * omit the member entirely, and still be dispatched. That is not a
     * cosmetic omission: JSON-RPC 2.0 §4 makes the member mandatory precisely so
     * a 1.0 client cannot be silently served 2.0 semantics.
     *
     * @dataProvider badJsonRpcMemberProvider
     */
    public function testABadJsonrpcMemberIsAnInvalidRequest(string $body): void
    {
        $decoded = self::body($this->controller()->handle($this->request($body, self::GOOD_TOKEN)));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($decoded));
        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('jsonrpc', $data['field'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badJsonRpcMemberProvider(): array
    {
        return [
            'the member is absent' => ['{"id":1,"method":"ping"}'],
            'JSON-RPC 1.0' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}'],
            'a number, not the string' => ['{"jsonrpc":2.0,"id":1,"method":"ping"}'],
            'the string "2"' => ['{"jsonrpc":"2","id":1,"method":"ping"}'],
            'null' => ['{"jsonrpc":null,"id":1,"method":"ping"}'],
        ];
    }

    /**
     * THE FAILING INPUT: a structured `id` is `INVALID_REQUEST`, answered with
     * `id: null` as §5 requires.
     *
     * This is the one that mattered. S62 coerced any non-string/non-int `id` to
     * `null` and answered ANYWAY, so a client sending `id: {...}` or a
     * fractional id got a well-formed reply it could not correlate with
     * anything it had outstanding — and waited for a response that had already
     * been sent.
     *
     * @dataProvider badIdProvider
     */
    public function testAnIdThatIsNotAStringOrIntegerIsAnInvalidRequest(string $idJson): void
    {
        $decoded = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":' . $idJson . ',"method":"ping"}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_REQUEST, self::errorCode($decoded));
        self::assertNull($decoded['id'], 'JSON-RPC 2.0 §5: an undeterminable id is echoed as null.');
        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('id', $data['field'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badIdProvider(): array
    {
        return [
            'an object' => ['{"a":1}'],
            'an array' => ['[1,2]'],
            'a boolean' => ['true'],
            'a fractional number' => ['1.5'],
        ];
    }

    /**
     * The SUCCEEDING controls beside the two providers above: the id types
     * JSON-RPC DOES allow are echoed verbatim.
     *
     * Without these a validator that refused every id would satisfy the failing
     * rows perfectly.
     *
     * @dataProvider usableIdProvider
     */
    public function testAUsableIdIsEchoedVerbatim(string $idJson, string|int|null $expected): void
    {
        $decoded = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":' . $idJson . ',"method":"ping"}',
            self::GOOD_TOKEN,
        )));

        self::assertArrayHasKey('result', $decoded, 'a legal id must not be refused.');
        self::assertSame($expected, $decoded['id']);
    }

    /**
     * @return array<string, array{0: string, 1: string|int|null}>
     */
    public static function usableIdProvider(): array
    {
        return [
            'an integer' => ['7', 7],
            'a negative integer' => ['-7', -7],
            'zero' => ['0', 0],
            'a string' => ['"req-1"', 'req-1'],
            'an explicit null' => ['null', null],
        ];
    }

    /**
     * A malformed envelope WITHOUT an `id` is still silent.
     *
     * JSON-RPC §4.1 forbids answering a notification at all — including to
     * report that it was malformed. The validator must therefore never be able
     * to turn a notification into a response, however wrong its members are.
     * `id`-absence is knowable even when everything else is broken, which is why
     * the controller settles that question first.
     *
     * @dataProvider malformedNotificationProvider
     */
    public function testAMalformedNotificationIsStillAnsweredWithSilence(string $body): void
    {
        $response = $this->controller()->handle($this->request($body, self::GOOD_TOKEN));

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedNotificationProvider(): array
    {
        return [
            'no jsonrpc member' => ['{"method":"notifications/initialized"}'],
            'wrong jsonrpc version' => ['{"jsonrpc":"1.0","method":"notifications/initialized"}'],
            'no method' => ['{"jsonrpc":"2.0"}'],
            'method is a number' => ['{"jsonrpc":"2.0","method":42}'],
        ];
    }

    /**
     * A POSITIONAL `params` is refused for its own sake, on a method that has no
     * required member to fall back on.
     *
     * ⚠ `testPositionalParamsCannotNameATool()` looks like it covers this
     * and does NOT: `tools/call` also requires `name`, so removing the
     * positional check entirely still yields `INVALID_PARAMS` from the missing
     * `name`, by a different branch. Mutation M26 survived on exactly that.
     * `tools/list` has no required member, so it is the only method where the
     * positional refusal is the sole thing that can say no — which is why the
     * error `data.field` is asserted too.
     */
    public function testPositionalParamsAreRefusedEvenWhenNothingElseWouldObject(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":31,"method":"tools/list","params":["cursor-1"]}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('params', $data['field'] ?? null);
    }

    /**
     * The SUCCEEDING control: an EMPTY `params` — indistinguishable from `{}` in
     * PHP — is NOT refused. Without this, refusing every array would satisfy the
     * test above while breaking `tools/list` for every client that sends `{}`.
     */
    public function testAnEmptyParamsObjectIsNotMistakenForAPositionalArray(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":32,"method":"tools/list","params":{}}',
            self::GOOD_TOKEN,
        )));

        self::assertArrayHasKey('result', $body, 'an empty params object was refused.');
    }

    /**
     * `Method not found` beats `Invalid params` (JSON-RPC §5.1) — an unknown
     * method with unusable params is reported as the unknown method.
     *
     * This is the discriminating control for
     * {@see \Phlix\Hub\Mcp\McpRequestValidator::isKnownMethod()}: a validator
     * that checked params first would answer `INVALID_PARAMS` here and send the
     * client hunting through a params object when its real mistake is the method
     * name. Two DIFFERENT refusals for two different mistakes, not one refusal
     * spelled twice.
     */
    public function testAnUnknownMethodWithUnusableParamsReportsTheMethod(): void
    {
        $decoded = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":9,"method":"resources/list","params":"not-an-object"}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::METHOD_NOT_FOUND, self::errorCode($decoded));
    }

    /**
     * ...while a KNOWN method with the same unusable params IS `INVALID_PARAMS`.
     * The pair proves the method-known check is what decides.
     */
    public function testAKnownMethodWithUnusableParamsIsInvalidParams(): void
    {
        $decoded = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":9,"method":"tools/list","params":"not-an-object"}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($decoded));
        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('params', $data['field'] ?? null);
    }

    // ------------------------------------------------------------------
    // S63: JSON shape — found by driving a REAL MCP client
    // ------------------------------------------------------------------

    /**
     * An EMPTY result must encode as `{}`, never `[]`.
     *
     * ⚠ Read the assertion: it is on the RAW body STRING, not on the decoded
     * body. That is the whole point. PHP has one array type, so
     * `json_encode([])` emits `[]` — a JSON ARRAY — where every MCP result type
     * is an OBJECT. `ping` shipped that way in S62 and NO decoding test could
     * see it, because `json_decode('{}', true)` and `json_decode('[]', true)`
     * are both `[]`. The real SDK client's `JSONRPCMessageSchema.parse()`
     * rejects it outright ("expected object, received array") and tears the
     * session down, which is how it was found.
     *
     * Do not "simplify" this test to `assertSame([], $body['result'])`. That
     * assertion passes against the broken code.
     */
    public function testAnEmptyResultEncodesAsAJsonObject(): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            self::GOOD_TOKEN,
        ));

        self::assertStringContainsString(
            '"result": {}',
            $response->body,
            "ping's EmptyResult was encoded as a JSON array. A conformant MCP client rejects the "
            . 'message and drops the session. Raw body was: ' . $response->body,
        );
        self::assertStringNotContainsString('"result": []', $response->body);
    }

    /**
     * The same `{}`-not-`[]` rule applies one level down, to
     * `result.structuredContent`.
     *
     * A tool whose upstream answered with an empty body produces an empty
     * payload, and `structuredContent` is an OBJECT in the MCP schema. Same
     * blind spot as the result above: decoding cannot see it, so the assertion
     * is on the raw body.
     */
    public function testAnEmptyToolPayloadEncodesAsAJsonObject(): void
    {
        $probe = new RecordingMcpTool();
        $probe->payload = [];

        $response = $this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":30,"method":"tools/call","params":{"name":"recording_probe"}}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(1, $probe->calls, 'the probe never ran, so nothing was rendered.');
        self::assertStringContainsString(
            '"structuredContent": {}',
            $response->body,
            'an empty tool payload was encoded as a JSON array. Raw body was: ' . $response->body,
        );
        self::assertStringNotContainsString('"structuredContent": []', $response->body);
    }

    /**
     * ...and the control beside it: a NON-empty result is still an object with
     * its members, and a genuine LIST inside a result stays a JSON array.
     *
     * Without this, "wrap everything in an object" would satisfy the test above
     * while turning `tools` into `{"0":…}` and breaking every client.
     */
    public function testAListInsideAResultIsStillAJsonArray(): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            self::GOOD_TOKEN,
        ));

        self::assertStringContainsString('"tools": [', $response->body, 'the tool list stopped being a JSON array.');
        self::assertStringNotContainsString('"tools": {', $response->body);
    }

    /**
     * Every tool descriptor publishes its scope somewhere a real MCP client can
     * actually READ it.
     *
     * S62 published it only as `x-phlix-scope`. The official SDK parses
     * `tools/list` through a Zod object that STRIPS unrecognised keys, so that
     * field never reaches the model — verified by driving the real client, which
     * printed `x-phlix-scope=undefined` for all six tools. `_meta` IS in the
     * `Tool` schema and survives, so the scope is published there too.
     *
     * The assertion is on `_meta` specifically: asserting "the scope appears
     * somewhere in the JSON" would be satisfied by the stripped field alone.
     */
    public function testEveryToolDescriptorPublishesItsScopeInMeta(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        /** @var list<array<string, mixed>> $tools */
        $tools = $result['tools'];
        self::assertNotSame([], $tools, 'ANTI-VACUITY: no tools were published, so this checks nothing.');

        foreach ($tools as $tool) {
            /** @var array<string, mixed> $meta */
            $meta = $tool['_meta'] ?? [];
            $scope = $meta['phlix/scope'] ?? null;
            self::assertIsString($scope, self::stringNode($tool['name'] ?? '?') . ' publishes no scope in _meta.');
            self::assertTrue(
                McpScopes::isKnown($scope),
                $scope . ' is not a scope McpScopes knows, so no token could ever hold it.',
            );
            self::assertSame($scope, $tool['x-phlix-scope'] ?? null, 'the two spellings disagree.');
        }
    }

    // ------------------------------------------------------------------
    // S63: GET /mcp — the SSE transport
    // ------------------------------------------------------------------

    public function testTheSseStreamRequiresAToken(): void
    {
        $response = $this->controller()->stream(
            $this->getRequest(null, ['ACCEPT' => 'text/event-stream']),
        );

        self::assertSame(401, $response->statusCode);
        self::assertNull($response->streamProducer, 'an unauthenticated GET must not open a stream.');
        self::assertSame('auth.required', self::body($response)['code'] ?? null);
    }

    /**
     * A stream costs the SAME limiter budget as a POST — it is not a second,
     * fresh bucket a token guesser could work through.
     */
    public function testAnInvalidTokenOnTheSseStreamCountsAgainstTheSameBucket(): void
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('peek')->willReturn(new RateLimitState(0, 10, 0, false, 10));
        $limiter->expects(self::once())->method('hit')
            ->with('mcp:auth:198.51.100.4')
            ->willReturn(new RateLimitState(1, 9, 0, false, 10));

        $response = $this->controller(limiter: $limiter, validToken: null)->stream(
            $this->getRequest(self::GOOD_TOKEN, ['ACCEPT' => 'text/event-stream']),
        );

        self::assertSame(401, $response->statusCode);
    }

    /**
     * THE FAILING INPUT for the transport: `Accept` must list
     * `text/event-stream`.
     *
     * A wildcard is deliberately NOT acceptance — see
     * `McpController::acceptsEventStream()`. Honouring it would open an
     * unterminated stream to every generic HTTP client, whose failure mode is a
     * hang rather than an error.
     *
     * @dataProvider unacceptableAcceptProvider
     */
    public function testAGetWithoutAnEventStreamAcceptIs406(?string $accept): void
    {
        $headers = $accept === null ? [] : ['ACCEPT' => $accept];

        $response = $this->controller()->stream($this->getRequest(self::GOOD_TOKEN, $headers));

        self::assertSame(406, $response->statusCode);
        self::assertNull($response->streamProducer, 'a refused GET must not open a stream.');
        self::assertSame('mcp.sse_not_acceptable', self::body($response)['code'] ?? null);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unacceptableAcceptProvider(): array
    {
        return [
            'no Accept header at all' => [null],
            'the wildcard curl sends by default' => ['*/*'],
            'JSON only' => ['application/json'],
            'a near miss' => ['text/event-streamX'],
            'an empty header' => [''],
        ];
    }

    /**
     * The SUCCEEDING control: an `Accept` that DOES list `text/event-stream`
     * opens the stream — including the shapes a real client sends it in.
     *
     * @dataProvider acceptableAcceptProvider
     */
    public function testAGetWithAnEventStreamAcceptOpensAStream(string $accept): void
    {
        $response = $this->controller()->stream(
            $this->getRequest(self::GOOD_TOKEN, ['ACCEPT' => $accept]),
        );

        self::assertSame(200, $response->statusCode);
        self::assertNotNull($response->streamProducer, "Accept: {$accept} must open a stream.");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptableAcceptProvider(): array
    {
        return [
            'exactly the media type' => ['text/event-stream'],
            'the MCP SDK spelling' => ['application/json, text/event-stream'],
            'with a q-value' => ['text/event-stream;q=0.9'],
            'upper case' => ['TEXT/EVENT-STREAM'],
        ];
    }

    /**
     * The producer really drives the SSE machinery — asserted by running it
     * against a connection and reading the bytes, not by checking a callable is
     * non-null.
     */
    public function testTheStreamProducerWritesARealSseHead(): void
    {
        $timers = new RecordingStreamTimers();
        $response = $this->controller(timers: $timers)->stream(
            $this->getRequest(self::GOOD_TOKEN, ['ACCEPT' => 'text/event-stream']),
        );

        $written = '';
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            static function (mixed $payload) use (&$written): bool {
                if (!is_string($payload)) {
                    self::fail('the SSE connection must only be sent string frames');
                }
                $written .= $payload;
                return true;
            },
        );

        self::assertNotNull($response->streamProducer);
        ($response->streamProducer)($connection);

        self::assertStringContainsString('HTTP/1.1 200 OK', $written);
        self::assertStringContainsString('Content-Type: text/event-stream', $written);
        self::assertStringContainsString('retry: ', $written);
        self::assertNotSame([], $timers->scheduled, 'the producer scheduled no timers at all.');
    }

    /**
     * A GET carrying an unsupported `MCP-Protocol-Version` header is refused
     * before the transport is considered — the same 400 the POST gives, so the
     * two verbs cannot disagree about which revisions the hub speaks.
     */
    public function testTheSseStreamHonoursTheProtocolVersionHeader(): void
    {
        $response = $this->controller()->stream($this->getRequest(self::GOOD_TOKEN, [
            'ACCEPT' => 'text/event-stream',
            'MCP-PROTOCOL-VERSION' => '1999-01-01',
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertNull($response->streamProducer);
        self::assertSame('mcp.unsupported_protocol_version', self::body($response)['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // MCP methods
    // ------------------------------------------------------------------

    public function testInitializeReportsTheProtocolVersionAndServerInfo(): void
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

    // ------------------------------------------------------------------
    // S63: protocol-version negotiation
    // ------------------------------------------------------------------

    /**
     * An OLDER but supported revision is honoured — the hub speaks it back.
     *
     * ⚠ This replaces S62's
     * `test_initialize_discloses_that_a_different_requested_version_was_not_negotiated`,
     * which asserted the OPPOSITE for this very input: `2024-11-05` used to be
     * answered with `2025-06-18` plus a `_meta` disclosure that no negotiation
     * had happened. S63 negotiates, so `2024-11-05` is now echoed and there is
     * nothing to disclose. The old assertion did not become wrong — the
     * behaviour it described was deliberately replaced, which is what this step
     * is for.
     *
     * @dataProvider supportedRevisionProvider
     */
    public function testASupportedRevisionIsEchoedBack(string $revision): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"'
            . $revision . '"}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertSame($revision, $result['protocolVersion']);
        self::assertArrayNotHasKey(
            '_meta',
            $result,
            'the client got the revision it asked for, so there is nothing to disclose.',
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function supportedRevisionProvider(): iterable
    {
        foreach (McpProtocol::SUPPORTED as $revision) {
            yield $revision => [$revision];
        }
    }

    /**
     * THE FAILING INPUT. An UNSUPPORTED revision is downgraded to the latest,
     * and the substitution is declared.
     *
     * A downgrade is not an error (the lifecycle spec asks for exactly this),
     * but `protocolVersion` alone cannot tell a client whether the value it got
     * is an echo or a substitute — so `_meta` says which, and lists what the hub
     * can speak, so the client can retry without a second round trip.
     *
     * @dataProvider unsupportedRevisionProvider
     */
    public function testAnUnsupportedRevisionIsDowngradedAndDeclared(string $revision): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"'
            . $revision . '"}}',
            self::GOOD_TOKEN,
        )));

        /** @var array<string, mixed> $result */
        $result = $body['result'];
        self::assertSame(McpProtocol::LATEST, $result['protocolVersion']);
        /** @var array<string, mixed> $meta */
        $meta = $result['_meta'];
        self::assertSame($revision, $meta['phlix/protocolVersionRequested']);
        self::assertFalse($meta['phlix/protocolVersionNegotiated']);
        self::assertSame(McpProtocol::SUPPORTED, $meta['phlix/protocolVersionsSupported']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedRevisionProvider(): array
    {
        return [
            'a revision from the future' => ['2099-01-01'],
            'a revision that never existed' => ['1999-01-01'],
            // Near-misses: a substring/superstring of a real revision must NOT
            // match, or the check is a `str_contains` in disguise.
            'a supported revision with a suffix' => ['2025-06-18-beta'],
            'a supported revision truncated' => ['2025-06'],
            'a supported revision with trailing space' => ['2025-06-18 '],
        ];
    }

    /**
     * THE FAILING INPUT for the schema half of negotiation: `protocolVersion`
     * of the wrong TYPE is `INVALID_PARAMS`, not a silent fallback.
     *
     * S62 read it with `is_string($requested) ? … : null` and carried on, so a
     * client sending `"protocolVersion": 20250618` was told it had negotiated
     * successfully. It had not — nothing had been compared.
     *
     * @dataProvider unusableProtocolVersionProvider
     */
    public function testAProtocolVersionOfTheWrongTypeIsInvalidParams(string $json): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":' . $json . '}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('protocolVersion', $data['field'] ?? null);
        self::assertSame(McpProtocol::SUPPORTED, $data['supported'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableProtocolVersionProvider(): array
    {
        return [
            'a number' => ['20250618'],
            'a boolean' => ['true'],
            'null' => ['null'],
            'an object' => ['{"version":"2025-06-18"}'],
            'an empty string' => ['""'],
            'whitespace only' => ['"   "'],
        ];
    }

    /**
     * ...and an `initialize` with NO `protocolVersion` at all is refused too.
     * It is the one field the handshake cannot proceed without.
     */
    public function testInitializeWithoutAProtocolVersionIsInvalidParams(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
    }

    // ------------------------------------------------------------------
    // S63: the MCP-Protocol-Version HTTP header (verified, not negotiated)
    // ------------------------------------------------------------------

    /**
     * THE FAILING INPUT: an unsupported header revision is a plain HTTP 400.
     *
     * Not a downgrade, unlike `initialize`. The header asserts a revision
     * already agreed, so quietly answering in a different one would be
     * re-negotiating mid-session behind the client's back.
     */
    public function testAnUnsupportedProtocolVersionHeaderIsA400(): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            self::GOOD_TOKEN,
            ['MCP-PROTOCOL-VERSION' => '1999-01-01'],
        ));

        self::assertSame(400, $response->statusCode);
        $body = self::body($response);
        self::assertSame('mcp.unsupported_protocol_version', $body['code'] ?? null);
        self::assertSame(McpProtocol::SUPPORTED, $body['supported'] ?? null);
    }

    /**
     * The SUCCEEDING control beside it: the same request with a SUPPORTED header
     * value is answered normally.
     *
     * Without this row a controller that 400-ed every request carrying the
     * header would satisfy the test above perfectly.
     *
     * @dataProvider supportedRevisionProvider
     */
    public function testASupportedProtocolVersionHeaderIsAccepted(string $revision): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            self::GOOD_TOKEN,
            ['MCP-PROTOCOL-VERSION' => $revision],
        ));

        self::assertSame(200, $response->statusCode);
        self::assertArrayHasKey('result', self::body($response));
    }

    /**
     * An ABSENT header is not a refusal either. The header postdates revision
     * `2025-03-26`, so its absence cannot mean "unsupported" — it means the
     * client predates the header, and the spec names the revision to assume.
     */
    public function testAnAbsentProtocolVersionHeaderIsAccepted(): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(200, $response->statusCode);
        self::assertTrue(
            McpProtocol::isSupported(McpProtocol::ASSUMED_WHEN_HEADER_ABSENT),
            'the revision assumed for a header-less request must itself be one the hub speaks, '
            . 'or the assumption is incoherent.',
        );
    }

    /**
     * The header gate runs AFTER authentication, so an unauthenticated caller
     * learns nothing from it — including whether the hub exists as an MCP server
     * at all.
     */
    public function testTheProtocolVersionGateDoesNotPreEmptThe401(): void
    {
        $response = $this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            null,
            ['MCP-PROTOCOL-VERSION' => '1999-01-01'],
        ));

        self::assertSame(401, $response->statusCode);
    }

    public function testToolsListNamesEveryRegisteredTool(): void
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

    public function testToolsCallRunsTheToolAndReturnsAnMcpResult(): void
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
    public function testARefusedToolCallIsAnErrorResultNotATransportError(): void
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

    public function testAnUnknownToolNameIsReportedAsAnErrorResult(): void
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

    public function testMissingToolArgumentsAreInvalidParams(): void
    {
        $body = self::body($this->controller()->handle($this->request(
            '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"list_libraries","arguments":{}}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
    }

    public function testToolsCallWithoutANameIsInvalidParams(): void
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
    public function testANarrowTokenCannotCallAToolOutsideItsScopes(): void
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
    public function testTheSameNarrowTokenCanStillCallTheToolItIsScopedFor(): void
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
    public function testAJsonObjectReallyCanProduceAnIntegerKey(): void
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
    public function testAPositionalKeyInTheArgumentsMapNeverReachesATool(): void
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
    public function testAStringKeyedArgumentsMapReachesTheToolIntact(): void
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
     * An explicit `"arguments": null` reaches the tool as `[]`.
     *
     * ⚠ THIS TEST CHANGED SHAPE IN S63, and the change is the point.
     *
     * In S62 this was a four-row provider: a positional array, a string, a
     * number and `null` all reached the tool as `[]`, and the tool then reported
     * a missing argument. S63's schema validation refuses the first three at the
     * envelope, with `INVALID_PARAMS` naming `arguments` — see
     * {@see testArgumentsThatAreNotAnObjectAreInvalidParams()}, which
     * is where those three rows went. That is a better answer: the client's
     * mistake is the SHAPE of `arguments`, and "server_id is required" sends it
     * looking at the wrong field.
     *
     * `null` deliberately did NOT move. `arguments` is optional in the MCP
     * schema and clients serialise "none" both ways, so an explicit null is
     * treated as absent — and it is the row that keeps
     * `McpController::stringKeyed()`'s `!is_array($raw)` branch REACHED from
     * production. Without it that branch would still be live code that nothing
     * runs.
     */
    public function testExplicitNullArgumentsArriveAtTheToolAsAnEmptyMap(): void
    {
        $probe = new RecordingMcpTool();

        $this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"recording_probe",'
            . '"arguments":null}}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(1, $probe->calls, 'an explicit null "arguments" must not refuse the call.');
        self::assertSame([], $probe->received);
    }

    /**
     * ...and the same for an OMITTED `arguments`, which is the spelling a real
     * client uses for a no-argument tool. Same branch, different spelling; both
     * are pinned so a future `?? []` default cannot quietly orphan it.
     */
    public function testOmittedArgumentsArriveAtTheToolAsAnEmptyMap(): void
    {
        $probe = new RecordingMcpTool();

        $this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":24,"method":"tools/call","params":{"name":"recording_probe"}}',
            self::GOOD_TOKEN,
        ));

        self::assertSame(1, $probe->calls);
        self::assertSame([], $probe->received);
    }

    /**
     * `arguments` that is neither an object nor null is `INVALID_PARAMS`, and
     * the tool is never invoked.
     *
     * The `calls === 0` assertion is load-bearing: without it a controller that
     * ran the tool with an empty map AND returned an error would pass.
     *
     * @dataProvider unusableArgumentsProvider
     */
    public function testArgumentsThatAreNotAnObjectAreInvalidParams(string $argumentsJson): void
    {
        $probe = new RecordingMcpTool();

        $body = self::body($this->controller(extraTool: $probe)->handle($this->request(
            '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"recording_probe",'
            . '"arguments":' . $argumentsJson . '}}',
            self::GOOD_TOKEN,
        )));

        self::assertSame(JsonRpc::INVALID_PARAMS, self::errorCode($body));
        self::assertSame(0, $probe->calls, 'a tool was invoked with unusable arguments.');
        /** @var array<string, mixed> $error */
        $error = $body['error'];
        /** @var array<string, mixed> $data */
        $data = $error['data'];
        self::assertSame('arguments', $data['field'] ?? null, 'the error must name the offending field.');
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
            'a JSON boolean' => ['true'],
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
    public function testPositionalParamsCannotNameATool(): void
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
        ?RecordingStreamTimers $timers = null,
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
            new McpSseStream($timers ?? new RecordingStreamTimers()),
        );
    }

    /**
     * @param array<string, string> $headers Extra request headers, as the
     *        Workerman-shaped upper-case keys `Request` stores.
     */
    private function request(string $rawBody, ?string $bearer = null, array $headers = []): Request
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
        foreach ($headers as $name => $value) {
            $request->headers[$name] = $value;
        }

        return $request;
    }

    /**
     * A `GET /mcp` request, which is what the SSE transport is opened with.
     *
     * @param array<string, string> $headers Extra request headers.
     */
    private function getRequest(?string $bearer = null, array $headers = []): Request
    {
        $request = $this->request('', $bearer, $headers);
        $request->method = 'GET';

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
