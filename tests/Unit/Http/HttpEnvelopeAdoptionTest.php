<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\QuotaFrames;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\McpProtocol;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use support\Context;

/**
 * HUB-ENV (F-01 legacy-envelope adoption) — wire byte-identity law.
 *
 * The legacy raw `json(['error' => ..., 'code' => ...])` envelopes in
 * FederationController / AdminMiddleware / AuthMiddleware were folded onto
 * the canonical `Response::error()` seam. Adoption is a pure code-shape
 * move: the emitted HTTP status, the exact JSON bytes (including extra-key
 * ORDER — the legacy payloads are golden on the wire) and the JSON content
 * headers must not move.
 *
 * Every literal below was recorded from the pristine pre-conversion code at
 * base commit a5cf0c5a (the raw legacy construction executed verbatim); see
 * /tmp/opencode/refactor-exec-1119/gates/HUBENV-legacy-bytes-base.txt.
 * Long byte strings are split by concatenation — the S299 ruleset forbids
 * >120-char lines in tests too, and the join points are inside string
 * VALUES, so the asserted bytes are unaffected. If a future refactor moves
 * one of these wire shapes, this file goes red BEFORE the wire does.
 *
 * HUB-F16-REST (wave-5 rest sweep): the 55 remaining legacy raw envelopes
 * across 20 files — RequestController, the server/quota guard family, the
 * steer/copy family, and the former McpController/AlexaSkillController HUB-ENV
 * parks (park dissolved: HUBCTRL closed, F-14 landed) — are pinned by the
 * testRestSweep* methods below, recorded the same way: pristine pre-conversion
 * constructions executed verbatim, bytes saved in
 * /tmp/opencode/refactor-exec-1119/gates/HUBF16-legacy-bytes-base.txt.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class HttpEnvelopeAdoptionTest extends TestCase
{
    private const SECRET = 'this-is-a-32-byte-or-larger-test-secret';

    /** Legacy 409 body of the federation-disabled guard (verbatim wire bytes). */
    private const GUARD_BODY = '{"error":"Conflict","code":"provider.not_configured",'
        . '"message":"Federation is disabled on this hub.",'
        . '"detail":"Federation is switched off (federation.enabled). '
        . 'Enable it in hub settings to manage peers and shares."}';

    private FederationHubRepository&MockObject $hubRepo;

    protected function setUp(): void
    {
        Context::destroy();
        AuthMiddleware::resetCache();
        $this->hubRepo = $this->createMock(FederationHubRepository::class);
    }

    /**
     * @param array<string,string> $headers
     */
    private function assertWireEnvelope(Response $response, int $status, string $body, array $headers): void
    {
        self::assertSame($status, $response->statusCode);
        self::assertSame($body, (string) $response->body);
        $expected = ['Content-Type' => 'application/json'] + $headers;
        foreach ($expected as $name => $value) {
            self::assertSame($value, $response->headers[$name] ?? null, "header $name");
        }
        // The adoption must not sprinkle extra headers onto legacy shapes.
        self::assertSame(array_keys($expected), array_keys($response->headers));
    }

    /**
     * @return array<string,string>
     */
    private function jsonHeaders(): array
    {
        return ['Cache-Control' => 'no-store'];
    }

    // ------------------------------------------------------------------
    // Seam level: Response::error() reproduces each recorded legacy shape.
    // ------------------------------------------------------------------

    public function testGuardDisabledEnvelopeIsByteIdentical(): void
    {
        $detail = 'Federation is switched off (federation.enabled). '
            . 'Enable it in hub settings to manage peers and shares.';
        $response = (new Response())->error(409, 'provider.not_configured', 'Conflict', [
            'message' => 'Federation is disabled on this hub.',
            'detail'  => $detail,
        ]);

        $this->assertWireEnvelope($response, 409, self::GUARD_BODY, $this->jsonHeaders());
    }

    public function testPeerConflictEnvelopesAreByteIdentical(): void
    {
        $this->assertWireEnvelope(
            (new Response())->error(409, 'peer_url_exists', 'Conflict', [
                'message' => 'A peer with this URL already exists.',
            ]),
            409,
            '{"error":"Conflict","code":"peer_url_exists","message":"A peer with this URL already exists."}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(409, 'peer_key_exists', 'Conflict', [
                'message' => 'A peer with this public key already exists.',
            ]),
            409,
            '{"error":"Conflict","code":"peer_key_exists","message":"A peer with this public key already exists."}',
            $this->jsonHeaders(),
        );
    }

    public function testPlainNotFoundEnvelopesAreByteIdentical(): void
    {
        $codes = ['peer_not_found', 'share_not_found', 'delegation_not_found'];
        foreach ($codes as $code) {
            $this->assertWireEnvelope(
                (new Response())->error(404, $code, 'Not Found'),
                404,
                '{"error":"Not Found","code":"' . $code . '"}',
                $this->jsonHeaders(),
            );
        }
    }

    public function testOfferAndDelegationConflictEnvelopesAreByteIdentical(): void
    {
        $this->assertWireEnvelope(
            (new Response())->error(404, 'offer_not_found', 'Not Found', [
                'message' => 'Offer not found',
            ]),
            404,
            '{"error":"Not Found","code":"offer_not_found","message":"Offer not found"}',
            $this->jsonHeaders(),
        );
        $responded = 'This offer has already been accepted or rejected';
        $this->assertWireEnvelope(
            (new Response())->error(409, 'offer_already_responded', 'Conflict', ['message' => $responded]),
            409,
            '{"error":"Conflict","code":"offer_already_responded","message":"' . $responded . '"}',
            $this->jsonHeaders(),
        );
        $masterOnly = 'Admin delegations are only available on the master hub.';
        $this->assertWireEnvelope(
            (new Response())->error(403, 'master_only', 'Forbidden', ['message' => $masterOnly]),
            403,
            '{"error":"Forbidden","code":"master_only","message":"' . $masterOnly . '"}',
            $this->jsonHeaders(),
        );
    }

    public function testLeafBoundInlineEnvelopeKeepsReasonBeforeMessageOrder(): void
    {
        // The inline rebind-conflict site's legacy literal put 'reason' first;
        // golden bytes preserve that order through the seam.
        $reason  = 'leaf_hub_id already bound to a different value';
        $message = 'This peer is already bound to a different leaf hub';
        $this->assertWireEnvelope(
            (new Response())->error(409, 'leaf_hub_id_already_bound', 'Conflict', [
                'reason'  => $reason,
                'message' => $message,
            ]),
            409,
            '{"error":"Conflict","code":"leaf_hub_id_already_bound","reason":"' . $reason . '",'
                . '"message":"' . $message . '"}',
            $this->jsonHeaders(),
        );
    }

    // ------------------------------------------------------------------
    // Controller level: the converted call sites still answer the wire.
    // ------------------------------------------------------------------

    private function federationController(bool $enabled): FederationController
    {
        return new FederationController(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            null,
            $this->createMock(FederationMasterPusher::class),
            static fn (): bool => $enabled,
        );
    }

    public function testDisabledGuardAnswersByteIdenticalThroughController(): void
    {
        $request         = new Request();
        $request->method = 'POST';
        $request->path   = '/api/v1/me/federation/peers';
        $request->userId = 'admin-1';

        $response = $this->federationController(false)->createPeer($request);

        $this->assertWireEnvelope($response, 409, self::GUARD_BODY, $this->jsonHeaders());
    }

    public function testUnknownPeerAnswersByteIdenticalThroughController(): void
    {
        $this->hubRepo->method('getPeerById')->willReturn(null);

        $request  = new Request();
        $response = $this->federationController(true)->deletePeer($request, ['id' => 'peer-missing']);

        $this->assertWireEnvelope(
            $response,
            404,
            '{"error":"Not Found","code":"peer_not_found"}',
            $this->jsonHeaders(),
        );
    }

    public function testInvalidBodyAnswersByteIdenticalThroughController(): void
    {
        $request         = new Request();
        $request->method = 'POST';
        $request->path   = '/api/v1/me/federation/peers';
        $request->body   = [];

        $response = $this->federationController(true)->createPeer($request);

        $this->assertWireEnvelope(
            $response,
            400,
            '{"error":"Bad Request","code":"invalid_body","message":"Request body must be a JSON object"}',
            $this->jsonHeaders(),
        );
    }

    public function testMalformedLeafHubIdAnswersByteIdenticalThroughController(): void
    {
        $request           = new Request();
        $request->method   = 'PUT';
        $request->path     = '/api/v1/me/federation/peers/peer-1/leaf-hub-id';
        $request->body     = ['leaf_hub_id' => 'not-a-uuid'];
        $required          = 'leaf_hub_id must be a UUID';

        $response = $this->federationController(true)->bindPeerLeafHubId($request, ['id' => 'peer-1']);

        // badRequest()'s builder order after adoption: error, code, message, reason.
        $this->assertWireEnvelope(
            $response,
            400,
            '{"error":"Bad Request","code":"leaf_hub_id_already_bound","message":"' . $required . '",'
                . '"reason":"' . $required . '"}',
            $this->jsonHeaders(),
        );
    }

    // ------------------------------------------------------------------
    // Middleware level.
    // ------------------------------------------------------------------

    public function testAdminMiddlewareEnvelopesAreByteIdentical(): void
    {
        $repo  = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);

        $unauthenticated = (new AdminMiddleware($repo, $audit))(new Request());
        self::assertNotNull($unauthenticated);
        $this->assertWireEnvelope(
            $unauthenticated,
            401,
            '{"error":"Unauthorized","code":"auth.required"}',
            $this->jsonHeaders(),
        );

        $repo->method('findAdminById')->willReturn(null);
        $request         = new Request();
        $request->userId = 'u-1';
        $forbidden       = (new AdminMiddleware($repo, $audit))($request);
        self::assertNotNull($forbidden);
        $this->assertWireEnvelope(
            $forbidden,
            403,
            '{"error":"Forbidden","code":"auth.not_admin"}',
            $this->jsonHeaders(),
        );
    }

    public function testAuthChallengeEnvelopeIsByteIdentical(): void
    {
        $middleware = new AuthMiddleware(new JwtHandler(self::SECRET), $this->createMock(UserRepository::class));

        $request         = new Request();
        $request->method = 'GET';
        $request->path   = '/api/v1/me';

        $response = $middleware($request);
        self::assertNotNull($response);
        $this->assertWireEnvelope(
            $response,
            401,
            '{"error":"Unauthorized","code":"auth.required"}',
            $this->jsonHeaders(),
        );
    }

    // ------------------------------------------------------------------
    // Census self-defense: the two variables that feed Response::error()
    // position 2 (the adoption seam's blind spot) must stay enumerated in
    // ErrorCodesContractTest's OPAQUE_CODE_FEEDS.
    // ------------------------------------------------------------------

    public function testAdoptedVariableFeedsRemainEnumeratedInCensus(): void
    {
        $feedClass = 'Phlix\\Hub\\Tests\\Unit\\Contracts\\ErrorCodesContractTest';
        /** @var array<string,string> $feeds */
        $feeds = (new ReflectionClass($feedClass))->getConstant('OPAQUE_CODE_FEEDS');

        $keys = [
            'Http/Controllers/FederationController.php::badRequest::error@2',
            'Http/Middleware/AuthMiddleware.php::challenge::error@2',
        ];
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $feeds, "adopted code feed $key lost its census enumeration");
            self::assertStringStartsWith('hop:', $feeds[$key]);
        }
    }

    // ------------------------------------------------------------------
    // HUB-F16-REST rest sweep — 55 legacy sites across 20 files. Golden
    // bodies recorded verbatim from the pristine pre-conversion code;
    // em-dashes ride the wire as \u2014 escapes (json_encode default),
    // which the single-quoted literals below embed as written bytes.
    // ------------------------------------------------------------------

    /**
     * Strict full-header-map variant for the chain sites: asserts the ENTIRE
     * response header map, key ORDER included (legacy insertion order is
     * custom legs first, then Content-Type, then Cache-Control).
     *
     * @param array<string,string> $orderedHeaders
     */
    private function assertFullFrame(Response $response, int $status, string $body, array $orderedHeaders): void
    {
        self::assertSame($status, $response->statusCode);
        self::assertSame($body, (string) $response->body);
        self::assertSame($orderedHeaders, $response->headers);
    }

    public function testRestSweepBareFramesAreByteIdentical(): void
    {
        // [status, code, error-phrase, golden body, live-site count]
        $rows = [
            // RequestController: create/approve/deny/delete id guards (x4)
            [400, 'missing_request_id', 'Bad Request',
                '{"error":"Bad Request","code":"missing_request_id"}', 4],
            // RequestController: lookups + approve/deny match-NotFound arms (x6)
            [404, 'request_not_found', 'Not Found',
                '{"error":"Not Found","code":"request_not_found"}', 6],
            // RequestController: ownership guards on approve + delete (x2)
            [403, 'not_request_owner', 'Forbidden',
                '{"error":"Forbidden","code":"not_request_owner"}', 2],
            // RequestController/UserQuotaController/AdminUpdatesController requireAdmin (x3)
            [403, 'admin_required', 'Forbidden',
                '{"error":"Forbidden","code":"admin_required"}', 3],
            // ServerManageController x2, ClientRelayTokenController, ServerDetailController (x4)
            [404, 'server.not_found', 'Not Found',
                '{"error":"Not Found","code":"server.not_found"}', 4],
            [403, 'server.not_owned', 'Forbidden',
                '{"error":"Forbidden","code":"server.not_owned"}', 4],
            [400, 'missing_server_id', 'Bad Request',
                '{"error":"Bad Request","code":"missing_server_id"}', 1],
            [403, 'not_server_owner', 'Forbidden',
                '{"error":"Forbidden","code":"not_server_owner"}', 1],
            [404, 'user.not_found', 'Not Found',
                '{"error":"Not Found","code":"user.not_found"}', 1],
            [404, 'mcp_token.not_found', 'Not Found',
                '{"error":"Not Found","code":"mcp_token.not_found"}', 1],
            [400, 'alexa.malformed_envelope', 'Bad Request',
                '{"error":"Bad Request","code":"alexa.malformed_envelope"}', 1],
            [403, 'csrf.invalid_origin', 'CSRF validation failed',
                '{"error":"CSRF validation failed","code":"csrf.invalid_origin"}', 1],
        ];
        $sites = 0;
        foreach ($rows as [$status, $code, $error, $body, $count]) {
            $this->assertWireEnvelope(
                (new Response())->error($status, $code, $error),
                $status,
                $body,
                $this->jsonHeaders(),
            );
            $sites += $count;
        }
        self::assertSame(29, $sites, 'rest-sweep bare-frame site census moved');
    }

    public function testRestSweepMessageFramesAreByteIdentical(): void
    {
        $this->assertWireEnvelope(
            (new Response())->error(400, 'invalid_type', 'Bad Request', [
                'message' => 'Request type must be "movie" or "series"',
            ]),
            400,
            '{"error":"Bad Request","code":"invalid_type",'
                . '"message":"Request type must be \"movie\" or \"series\""}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(400, 'invalid_tmdb_id', 'Bad Request', [
                'message' => 'TMDB ID must be a positive integer',
            ]),
            400,
            '{"error":"Bad Request","code":"invalid_tmdb_id",'
                . '"message":"TMDB ID must be a positive integer"}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(400, 'missing_title', 'Bad Request', [
                'message' => 'Title is required',
            ]),
            400,
            '{"error":"Bad Request","code":"missing_title","message":"Title is required"}',
            $this->jsonHeaders(),
        );
        // RequestController pending-transition quartet (approve/deny x 409/500).
        $already = 'This request was already approved or denied';
        $this->assertWireEnvelope(
            (new Response())->error(409, 'approve_failed', $already, ['message' => $already]),
            409,
            '{"error":"This request was already approved or denied","code":"approve_failed",'
                . '"message":"This request was already approved or denied"}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(500, 'approve_failed', 'Internal Server Error', [
                'message' => 'Failed to approve the request. Check the Radarr/Sonarr configuration',
            ]),
            500,
            '{"error":"Internal Server Error","code":"approve_failed",'
                . '"message":"Failed to approve the request. Check the Radarr/Sonarr configuration"}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(409, 'deny_failed', $already, ['message' => $already]),
            409,
            '{"error":"This request was already approved or denied","code":"deny_failed",'
                . '"message":"This request was already approved or denied"}',
            $this->jsonHeaders(),
        );
        $denied = 'Failed to deny the request. Try again';
        $this->assertWireEnvelope(
            (new Response())->error(500, 'deny_failed', $denied, ['message' => $denied]),
            500,
            '{"error":"Failed to deny the request. Try again","code":"deny_failed",'
                . '"message":"Failed to deny the request. Try again"}',
            $this->jsonHeaders(),
        );
        // ServerProxyController browse/method-scope refusals (x2, one shape).
        $this->assertWireEnvelope(
            (new Response())->error(403, 'proxy.scope_denied', 'Forbidden', [
                'message' => 'This method/path is not exposed through the relay proxy.',
            ]),
            403,
            '{"error":"Forbidden","code":"proxy.scope_denied",'
                . '"message":"This method/path is not exposed through the relay proxy."}',
            $this->jsonHeaders(),
        );
        // UserQuotaController range builders (field name resolved at record time).
        $this->assertWireEnvelope(
            (new Response())->error(400, 'invalid_quota', 'Bad Request', [
                'message' => sprintf('"%s" must be a non-negative integer within range.', 'requests_per_month'),
            ]),
            400,
            '{"error":"Bad Request","code":"invalid_quota",'
                . '"message":"\"requests_per_month\" must be a non-negative integer within range."}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(400, 'invalid_throttle', 'Bad Request', [
                'message' => '"throttle_bps" must be one of the allowed levels '
                    . '(0, 1000000, 3000000, 5000000, 10000000, 20000000, 50000000).',
            ]),
            400,
            '{"error":"Bad Request","code":"invalid_throttle",'
                . '"message":"\"throttle_bps\" must be one of the allowed levels '
                . '(0, 1000000, 3000000, 5000000, 10000000, 20000000, 50000000)."}',
            $this->jsonHeaders(),
        );
        // McpController SSE steer.
        $this->assertWireEnvelope(
            (new Response())->error(406, 'mcp.sse_not_acceptable', 'Not Acceptable', [
                'message' => 'GET /mcp opens a Server-Sent Events stream. Send '
                    . 'Accept: text/event-stream, or use POST /mcp for JSON-RPC.',
            ]),
            406,
            '{"error":"Not Acceptable","code":"mcp.sse_not_acceptable",'
                . '"message":"GET /mcp opens a Server-Sent Events stream. Send '
                . 'Accept: text/event-stream, or use POST /mcp for JSON-RPC."}',
            $this->jsonHeaders(),
        );
        // LogController member-voice wire law (SH-08x copy family, em-dash legs).
        $this->assertWireEnvelope(
            (new Response())->error(400, 'invalid_request', 'Invalid log file name', [
                'message' => 'The file parameter must be a bare *.log file name from the '
                    . 'hub log directory (like app.log) — paths and other extensions are refused.',
            ]),
            400,
            '{"error":"Invalid log file name","code":"invalid_request",'
                . '"message":"The file parameter must be a bare *.log file name from the '
                . 'hub log directory (like app.log) \u2014 paths and other extensions are refused."}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(404, 'storage_unavailable', 'Log directory not available', [
                'message' => 'The hub writes its logs to the .logs/ directory at the root of '
                    . 'the hub install; it does not exist or is unreadable — check that '
                    . 'directory before retrying.',
            ]),
            404,
            '{"error":"Log directory not available","code":"storage_unavailable",'
                . '"message":"The hub writes its logs to the .logs/ directory at the root of '
                . 'the hub install; it does not exist or is unreadable \u2014 check that '
                . 'directory before retrying."}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(404, 'not_found', 'Log file not found', [
                'message' => 'No such *.log file in the hub log directory — '
                    . 'GET /api/v1/me/logs lists the files that exist.',
            ]),
            404,
            '{"error":"Log file not found","code":"not_found",'
                . '"message":"No such *.log file in the hub log directory \u2014 '
                . 'GET /api/v1/me/logs lists the files that exist."}',
            $this->jsonHeaders(),
        );
        $this->assertWireEnvelope(
            (new Response())->error(500, 'hub.internal_error', 'Failed to read log file', [
                'message' => 'The hub could not read the log file — the hub HTTP log '
                    . 'channel carries the read-failure reason.',
            ]),
            500,
            '{"error":"Failed to read log file","code":"hub.internal_error",'
                . '"message":"The hub could not read the log file \u2014 the hub HTTP log '
                . 'channel carries the read-failure reason."}',
            $this->jsonHeaders(),
        );
        // Router unified 404 (also byte-pinned by RouterTest on the live route).
        $this->assertWireEnvelope(
            (new Response())->error(404, 'not_found', 'The requested resource was not found', [
                'message' => 'The requested resource was not found',
            ]),
            404,
            '{"error":"The requested resource was not found","code":"not_found",'
                . '"message":"The requested resource was not found"}',
            $this->jsonHeaders(),
        );
    }

    public function testRestSweepQuotaFrameKeepsDiscriminatorTail(): void
    {
        $text = QuotaFrames::REQUESTS_TEXT;
        $this->assertWireEnvelope(
            (new Response())->error(409, 'quota.exceeded', $text, [
                'message' => $text,
                ...QuotaFrames::discriminator(QuotaFrames::KIND_REQUESTS, QuotaFrames::RESET_ON_RELEASE),
            ]),
            409,
            '{"error":"You have too many pending requests \u2014 an admin approval or rejection frees a slot",'
                . '"code":"quota.exceeded",'
                . '"message":"You have too many pending requests \u2014 an admin approval or rejection frees a slot",'
                . '"quota_kind":"requests","quota_reset":"on_release"}',
            $this->jsonHeaders(),
        );
    }

    public function testRestSweepStructuredExtrasFramesAreByteIdentical(): void
    {
        // AdminUserController::fieldErrors builder (sample per-field map).
        $this->assertWireEnvelope(
            (new Response())->error(400, 'validation_failed', 'Validation failed', [
                'field_errors' => ['title' => 'Title is required.'],
            ]),
            400,
            '{"error":"Validation failed","code":"validation_failed",'
                . '"field_errors":{"title":"Title is required."}}',
            $this->jsonHeaders(),
        );
        // McpTokenController scope refusal (record-time available-catalog sample).
        $this->assertWireEnvelope(
            (new Response())->error(400, 'mcp_token.no_valid_scopes', 'Bad Request', [
                'message' => 'At least one known scope is required.',
                'available_scopes' => ['library:read', 'media:search'],
            ]),
            400,
            '{"error":"Bad Request","code":"mcp_token.no_valid_scopes",'
                . '"message":"At least one known scope is required.",'
                . '"available_scopes":["library:read","media:search"]}',
            $this->jsonHeaders(),
        );
        // McpController protocol-version refusal (supported list from the live const).
        $presented = '2099-01-01';
        $this->assertWireEnvelope(
            (new Response())->error(400, 'mcp.unsupported_protocol_version', 'Bad Request', [
                'message' => 'This hub does not implement MCP protocol revision "' . $presented
                    . '". Re-run initialize and use the revision it returns.',
                'supported' => McpProtocol::SUPPORTED,
            ]),
            400,
            '{"error":"Bad Request","code":"mcp.unsupported_protocol_version",'
                . '"message":"This hub does not implement MCP protocol revision \"2099-01-01\". '
                . 'Re-run initialize and use the revision it returns.",'
                . '"supported":["2025-06-18","2025-03-26","2024-11-05"]}',
            $this->jsonHeaders(),
        );
    }

    public function testRestSweepHeaderChainsKeepLegacyHeaderOrder(): void
    {
        $ws = 'wss://hub.example.test/ws/relay';
        $docs = 'https://detain.github.io/phlix-docs/hub/relay';
        $this->assertFullFrame(
            (new Response())
                ->header('Link', '<' . $docs . '>; rel="help"')
                ->header('X-WS-Endpoint', $ws)
                ->error(501, 'relay.ws_http_endpoint', 'NOT_IMPLEMENTED_VIA_HTTP', [
                    'message' => 'The relay tunnel must be established via WebSocket.'
                        . ' Connect to ' . $ws . ' with your enrollment JWT.',
                    'ws_endpoint' => $ws,
                    'docs'        => $docs,
                ]),
            501,
            '{"error":"NOT_IMPLEMENTED_VIA_HTTP","code":"relay.ws_http_endpoint",'
                . '"message":"The relay tunnel must be established via WebSocket.'
                . ' Connect to wss://hub.example.test/ws/relay with your enrollment JWT.",'
                . '"ws_endpoint":"wss://hub.example.test/ws/relay",'
                . '"docs":"https://detain.github.io/phlix-docs/hub/relay"}',
            [
                'Link' => '<' . $docs . '>; rel="help"',
                'X-WS-Endpoint' => $ws,
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
        );
        $tlsDocs = 'https://detain.github.io/phlix-docs/hub-admin/tls';
        $this->assertFullFrame(
            (new Response())
                ->header('Link', '<' . $tlsDocs . '>; rel="help"')
                ->error(501, 'tls.acme_not_implemented', 'NOT_IMPLEMENTED', [
                    'message' => 'certificate issuance is not implemented',
                    'docs' => $tlsDocs,
                ]),
            501,
            '{"error":"NOT_IMPLEMENTED","code":"tls.acme_not_implemented",'
                . '"message":"certificate issuance is not implemented",'
                . '"docs":"https://detain.github.io/phlix-docs/hub-admin/tls"}',
            [
                'Link' => '<' . $tlsDocs . '>; rel="help"',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
        );
        // ClientMountController 426 + 501 arms: one steer sentence, two envelopes.
        $note = ' The target server is currently offline.';
        $mountMessage = 'The client relay tunnel must be established via WebSocket. Connect to '
            . $ws . ' with your client relay token.' . $note;
        $mountBody = '{"error":"%s","code":"relay.client_ws_endpoint",'
            . '"message":"The client relay tunnel must be established via WebSocket.'
            . ' Connect to wss://hub.example.test/ws/relay with your client relay token.'
            . ' The target server is currently offline.",'
            . '"ws_endpoint":"wss://hub.example.test/ws/relay","available":false}';
        $this->assertFullFrame(
            (new Response())
                ->header('X-WS-Endpoint', $ws)
                ->error(426, 'relay.client_ws_endpoint', 'UPGRADE_REQUIRED', [
                    'message' => $mountMessage,
                    'ws_endpoint' => $ws,
                    'available' => false,
                ]),
            426,
            sprintf($mountBody, 'UPGRADE_REQUIRED'),
            [
                'X-WS-Endpoint' => $ws,
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
        );
        $this->assertFullFrame(
            (new Response())
                ->header('X-WS-Endpoint', $ws)
                ->error(501, 'relay.client_ws_endpoint', 'NOT_IMPLEMENTED_VIA_HTTP', [
                    'message' => $mountMessage,
                    'ws_endpoint' => $ws,
                    'available' => false,
                ]),
            501,
            sprintf($mountBody, 'NOT_IMPLEMENTED_VIA_HTTP'),
            [
                'X-WS-Endpoint' => $ws,
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    public function testMaintenanceResponseEmitsTheRecordedFrameThroughApplication(): void
    {
        $this->assertFullFrame(
            Application::maintenanceResponse(),
            503,
            '{"error":"This hub is under maintenance. Try again shortly.",'
                . '"code":"provider_unavailable",'
                . '"detail":"Hub maintenance mode (hub.maintenance_mode) is on; '
                . 'API traffic resumes when an admin switches it off."}',
            [
                'Retry-After' => '60',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    public function testRestSweepFilesCarryNoRawErrorEnvelopeLiterals(): void
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $clean = [
            'Http/Controllers/RequestController.php',
            'Http/Controllers/ServerManageController.php',
            'Http/Controllers/LibraryController.php',
            'Http/Controllers/ClientRelayTokenController.php',
            'Http/Controllers/MeController.php',
            'Http/Controllers/ServerDetailController.php',
            'Http/Controllers/AdminUserController.php',
            'Http/Controllers/AlexaSkillController.php',
            'Http/Controllers/RelayController.php',
            'Http/Controllers/SubdomainController.php',
            'Http/Controllers/UserQuotaController.php',
            'Http/Controllers/McpTokenController.php',
            'Http/Controllers/LogController.php',
            'Http/Controllers/ClientMountController.php',
            'Http/Controllers/McpController.php',
            'Http/Router.php',
            'Http/Middleware/CsrfOriginGuard.php',
        ];
        foreach ($clean as $file) {
            self::assertSame(
                0,
                (int) preg_match_all("/'error'\s*=>/", (string) file_get_contents($root . $file)),
                "$file still assembles a raw error envelope",
            );
        }
        // ServerProxyController: the single remaining hit is docblock prose
        // quoting the retired nested shape. AdminUpdatesController: the two
        // success-first { success, error, ... } frames stay parked — they are
        // OUT of the seam's reach (the merge would reorder the wire); FINDING
        // rows in the lane register, not adoptions.
        self::assertSame(
            1,
            (int) preg_match_all(
                "/'error'\s*=>/",
                (string) file_get_contents($root . 'Http/Controllers/ServerProxyController.php')
            ),
        );
        self::assertSame(
            2,
            (int) preg_match_all(
                "/'error'\s*=>/",
                (string) file_get_contents($root . 'Http/Controllers/AdminUpdatesController.php')
            ),
        );
    }
}
