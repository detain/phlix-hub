<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RenewHandler;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Http\Controllers\RelayController;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Controllers\ServerController;
use Phlix\Hub\Http\Controllers\SubdomainController;
use Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

use function array_keys;
use function base64_encode;
use function json_decode;
use function json_encode;
use function strtr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Frame-contract pins for the W3 emit-wave: every promoted error frame must
 * carry the registered dotted code in `code`, keep its legacy SCREAMING
 * literal byte-identical in the `error` TEXT field, and preserve its status
 * and human message. These are exact WHOLE-FRAME assertions (decoded assoc
 * array + key order), not `assertStringContainsString` — the existing tests
 * keep their substring assertions, this file is the shape proof.
 *
 * Also pins every `EnrollmentJwtMiddleware` throw arm's flip frame (the
 * substring-only middleware test keeps its checks; this is the shape proof),
 * the shared helper contract: `Response::errorBody()` output must remain
 * byte-compatible with the deleted relay-private `errorBody()`, and — the
 * wave-2 addendum below — every promoted 401/400/426 gate frame in
 * {@see SubdomainController}, {@see RelayController}, {@see ClientMountController}
 * and the {@see ServerClaimController} bare-'Bad Request' trio.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ErrorPromotionFramesTest extends TestCase
{
    /**
     * Shared wave-2 gate frames (SubdomainController and RelayController speak
     * the identical enrollment-gate vocabulary; each is pinned whole-frame by
     * both controller tests — one source of truth per frame shape here).
     */
    private const FRAME_MISSING = [
        'error' => 'MISSING_SERVER_ID',
        'code' => 'missing_server_id',
        'message' => 'Server ID is required',
    ];

    private const FRAME_HEADER = [
        'error' => 'UNAUTHORIZED',
        'code' => 'auth.required',
        'message' => 'Missing or invalid Authorization header',
    ];

    private const FRAME_FORMAT = [
        'error' => 'UNAUTHORIZED',
        'code' => 'auth.required',
        'message' => 'Invalid token format',
    ];

    private const FRAME_EXPIRED = [
        'error' => 'UNAUTHORIZED',
        'code' => 'auth.enrollment_expired',
        'message' => 'Invalid or expired enrollment token',
    ];

    private const FRAME_MISMATCH = [
        'error' => 'UNAUTHORIZED',
        'code' => 'auth.server_mismatch',
        'message' => 'Server ID mismatch',
    ];

    // ------------------------------------------------------------------
    // ServerClaimController — claim family + hub.* + server.key_invalid
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0:int,1:string,2:string,3:string}>
     */
    public static function claimMapErrorProvider(): array
    {
        return [
            'code not found'   => [404, 'claim.code_not_found', 'CLAIM_CODE_NOT_FOUND', 'Claim code not found'],
            'code expired'     => [410, 'claim.code_expired', 'CLAIM_CODE_EXPIRED', 'Claim code has expired'],
            'already claimed'  => [
                409, 'claim.code_already_claimed', 'CLAIM_CODE_ALREADY_CLAIMED',
                'Claim code has already been used',
            ],
            'protocol'         => [
                400, 'hub.protocol_unsupported', 'HUB_PROTOCOL_UNSUPPORTED',
                'Accept-Phlix-Protocol: v1 required',
            ],
            'key invalid'      => [
                400, 'server.key_invalid', 'SERVER_KEY_INVALID',
                'Server key is malformed or not Ed25519',
            ],
            'default 500'      => [500, 'hub.internal_error', 'HUB_INTERNAL_ERROR', 'An unexpected error occurred'],
        ];
    }

    /**
     * @dataProvider claimMapErrorProvider
     */
    public function testClaimErrorFrameCarriesDottedCodeAndLegacyText(
        int $status,
        string $code,
        string $legacy,
        string $message,
    ): void {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $throw = $legacy === 'HUB_INTERNAL_ERROR' ? 'anything-else' : $legacy;
        $handler->method('handleClaimCode')
            ->willThrowException(new InvalidArgumentException($throw));
        $controller = new ServerClaimController($handler);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';
        $request->userId = 'user-1';
        $request->body = ['claim_code' => 'XXXX'];

        $response = $controller->claim($request);

        self::assertSame($status, $response->statusCode);
        self::assertSame(
            ['error' => $legacy, 'code' => $code, 'message' => $message],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
            'promoted claim frame drifted from the contracted shape',
        );
    }

    public function testClaimUnauthenticatedFrameFlipsLegacyLiteralToTextChannel(): void
    {
        $controller = new ServerClaimController($this->createMock(ClaimRequestHandler::class));

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/server-claims/claim';

        $response = $controller->claim($request);

        self::assertSame(401, $response->statusCode);
        // The legacy literal MOVED from `code` to `error` text; `code` is the
        // dotted forward form. Whole-frame proof, not substring.
        self::assertSame(
            ['error' => 'UNAUTHENTICATED', 'code' => 'auth.unauthenticated'],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    // ------------------------------------------------------------------
    // ServerController — server.not_found inline + mapError arms
    // ------------------------------------------------------------------

    public function testServerInfoNotFoundFrameCarriesRegisteredTwinCode(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->willReturn(null);
        $controller = new ServerController(
            $this->createMock(HeartbeatHandler::class),
            $serverInfo,
            $this->createMock(DeregisterHandler::class),
            $this->createMock(RenewHandler::class),
        );

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/servers/srv-1';
        $request->serverId = 'srv-1';

        $response = $controller->info($request, ['id' => 'srv-1']);

        self::assertSame(404, $response->statusCode);
        self::assertSame(
            ['error' => 'SERVER_NOT_FOUND', 'code' => 'server.not_found', 'message' => 'Server not found'],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, array{0:int,1:string,2:string,3:string}>
     */
    public static function serverMapErrorProvider(): array
    {
        return [
            'enrollment expired' => [
                401, 'auth.enrollment_expired', 'ENROLLMENT_TOKEN_EXPIRED',
                'Enrollment token has expired',
            ],
            'server not found'   => [404, 'server.not_found', 'SERVER_NOT_FOUND', 'Server not found'],
            'default 500'        => [500, 'hub.internal_error', 'HUB_INTERNAL_ERROR', 'An unexpected error occurred'],
        ];
    }

    /**
     * @dataProvider serverMapErrorProvider
     */
    public function testServerErrorFrameCarriesDottedCodeAndLegacyText(
        int $status,
        string $code,
        string $legacy,
        string $message,
    ): void {
        $deregister = $this->createMock(DeregisterHandler::class);
        $throw = $legacy === 'HUB_INTERNAL_ERROR' ? 'anything-else' : $legacy;
        $deregister->method('handle')
            ->willThrowException(new InvalidArgumentException($throw));
        $controller = new ServerController(
            $this->createMock(HeartbeatHandler::class),
            $this->createMock(ServerInfoHandler::class),
            $deregister,
            $this->createMock(RenewHandler::class),
        );

        $request = new Request();
        $request->method = 'DELETE';
        $request->path = '/api/v1/servers/srv-1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'jwt';

        $response = $controller->disconnect($request, ['id' => 'srv-1']);

        self::assertSame($status, $response->statusCode);
        self::assertSame(
            ['error' => $legacy, 'code' => $code, 'message' => $message],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testServerMismatchFramesCarryAuthServerMismatchCode(): void
    {
        $controller = $this->makeServerController();

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/heartbeat';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-2';
        $request->body = [];

        $response = $controller->heartbeat($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
        self::assertSame(
            ['error' => 'AUTHORIZATION_FAILED', 'code' => 'auth.server_mismatch', 'message' => 'Server ID mismatch'],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function makeServerController(): ServerController
    {
        return new ServerController(
            $this->createMock(HeartbeatHandler::class),
            $this->createMock(ServerInfoHandler::class),
            $this->createMock(DeregisterHandler::class),
            $this->createMock(RenewHandler::class),
        );
    }

    // ------------------------------------------------------------------
    // EnrollmentJwtMiddleware — enrollment flip, every throw arm
    // ------------------------------------------------------------------

    public function testEnrollmentMiddlewareMissingTokenFrameIsWholeFramePinned(): void
    {
        $service = $this->createMock(EnrollmentJwtService::class);
        $service->expects(self::never())->method('validateEnrollmentJwt');
        $middleware = new EnrollmentJwtMiddleware($service);

        $request = new Request();

        $this->assertEnrollmentFlipFrame($middleware($request));
    }

    public function testEnrollmentMiddlewareUnparseableKidFrameIsWholeFramePinned(): void
    {
        $service = $this->createMock(EnrollmentJwtService::class);
        $service->expects(self::never())->method('validateEnrollmentJwt');
        $middleware = new EnrollmentJwtMiddleware($service);

        $request = new Request();
        $request->bearerToken = 'not-a-valid-jwt';

        $this->assertEnrollmentFlipFrame($middleware($request));
    }

    public function testEnrollmentMiddlewareFailedValidationFrameIsWholeFramePinned(): void
    {
        $service = $this->createMock(EnrollmentJwtService::class);
        $service->method('validateEnrollmentJwt')->willReturn(null);
        $middleware = new EnrollmentJwtMiddleware($service);

        $request = new Request();
        $request->bearerToken = $this->tokenWithKid('enrollment-pin-kid');

        $this->assertEnrollmentFlipFrame($middleware($request));
    }

    /**
     * Whole-frame pin shared by every {@see EnrollmentJwtMiddleware} throw
     * arm (missing token :46, unparseable kid :51, failed validation :56 —
     * all funnel through the same `unauthorized()` frame): exact key set and
     * order via decoded-assoc `assertSame`, status, and the byte-exact
     * serialized body including the pretty-print/unescaped-slashes flags.
     * Reordering or merging keys here goes red; the sibling middleware test's
     * substring check alone cannot catch that.
     */
    private function assertEnrollmentFlipFrame(?Response $response): void
    {
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertSame(
            ['error' => 'ENROLLMENT_TOKEN_EXPIRED', 'code' => 'auth.enrollment_expired'],
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
            'enrollment flip frame drifted from the contracted shape',
        );
        self::assertSame(
            "{\n    \"error\": \"ENROLLMENT_TOKEN_EXPIRED\",\n    \"code\": \"auth.enrollment_expired\"\n}",
            $response->body,
            'enrollment flip body drifted from JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES serialization',
        );
    }

    /**
     * JWT-shaped string whose header segment carries a `kid`, enough for
     * `Phlix\Hub\Jwt\JwtHeader::kid()` to return non-null so the middleware
     * reaches its validation arm. Signature/payload bytes are never parsed.
     */
    private function tokenWithKid(string $kid): string
    {
        $header = json_encode(['alg' => 'EdDSA', 'kid' => $kid], JSON_THROW_ON_ERROR);

        return strtr(base64_encode($header), '+/', '-_') . '.c2ln.cGF5bG9hZA';
    }

    // ------------------------------------------------------------------
    // Response — shared helper contract
    // ------------------------------------------------------------------

    public function testErrorBodyStaysByteCompatibleWithTheDeletedRelayHelper(): void
    {
        self::assertSame(
            '{"error":"No live relay tunnel for this server.","code":"server.no_tunnel"}',
            Response::errorBody('server.no_tunnel', 'No live relay tunnel for this server.'),
        );
    }

    public function testErrorBodyFallsBackWhenEncodingFails(): void
    {
        // Lone surrogate bytes make json_encode throw under JSON_THROW_ON_ERROR.
        self::assertSame('{"error":"relay error"}', Response::errorBody('x.invalid', "\xB1\x31\x32\x34"));
    }

    public function testErrorHelperEmitsErrorThenCodeThenExtraInOrder(): void
    {
        $response = (new Response())->error(418, 'auth.required', 'Unauthorized', ['message' => 'm']);

        self::assertSame(418, $response->statusCode);
        $decoded = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['error', 'code', 'message'], array_keys($decoded));
        self::assertSame(
            ['error' => 'Unauthorized', 'code' => 'auth.required', 'message' => 'm'],
            $decoded,
        );
        self::assertSame(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $response->body);
    }

    // ------------------------------------------------------------------
    // Wave-2 gates — SubdomainController / RelayController / ClientMountController
    // ------------------------------------------------------------------

    public function testSubdomainGateFramesAreWholeFramePinned(): void
    {
        $catch = [
            'error' => 'UNAUTHORIZED',
            'code' => 'auth.required',
            'message' => 'sub pin boom',
        ];

        $bare = $this->makeSubdomainController($this->createMock(EnrollmentJwtService::class));
        foreach (['allocate', 'refreshCertificate', 'revoke'] as $action) {
            self::assertGateFrame(
                400,
                self::FRAME_MISSING,
                $bare->$action(new Request(), ['id' => '']),
                "SubdomainController::$action MISSING_SERVER_ID",
            );
        }
        foreach (['allocate', 'revoke'] as $action) {
            self::assertGateFrame(
                401,
                self::FRAME_HEADER,
                $bare->$action(new Request(), ['id' => 'srv-1']),
                "SubdomainController::$action header gate",
            );
        }

        $formatController = $this->makeSubdomainController($this->createMock(EnrollmentJwtService::class));
        $throwingService = $this->createMock(EnrollmentJwtService::class);
        $throwingService->method('validateEnrollmentJwt')
            ->willThrowException(new InvalidArgumentException('sub pin boom'));
        $expiredService = $this->createMock(EnrollmentJwtService::class);
        $expiredService->method('validateEnrollmentJwt')->willReturn(null);
        $mismatchService = $this->createMock(EnrollmentJwtService::class);
        $mismatchService->method('validateEnrollmentJwt')->willReturn(['server_id' => 'srv-other']);

        $authed = $this->bearer($this->tokenWithKid('gate-pin-kid'));
        $arms = [
            'token format' => [$formatController, $this->bearer('not-a-valid-jwt'), self::FRAME_FORMAT],
            'expired' => [$this->makeSubdomainController($expiredService), $authed, self::FRAME_EXPIRED],
            'mismatch' => [$this->makeSubdomainController($mismatchService), $authed, self::FRAME_MISMATCH],
            'invalid-argument catch' => [$this->makeSubdomainController($throwingService), $authed, $catch],
        ];
        foreach ($arms as $label => [$controller, $request, $frame]) {
            foreach (['allocate', 'revoke'] as $action) {
                self::assertGateFrame(
                    401,
                    $frame,
                    $controller->$action($request, ['id' => 'srv-1']),
                    "SubdomainController::$action — $label",
                );
            }
        }
    }

    /**
     * The sanctioned reuse target (contracts #80): the subdomain-allocation 404
     * rode the SCREAMING `SERVER_NOT_FOUND` as error TEXT only — the residual
     * named in the #321 CHANGELOG. This frame pin proves the promotion: dotted
     * `server.not_found` on the `code` channel, legacy text byte-identical in
     * `error`, exception message threaded unchanged through `message`.
     */
    public function testSubdomainNotFoundFrameCarriesRegisteredTwinCode(): void
    {
        $passingService = $this->createMock(EnrollmentJwtService::class);
        $passingService->method('validateEnrollmentJwt')->willReturn(['server_id' => 'srv-1']);
        $missingServer = $this->createMock(DnsAliasManager::class);
        $missingServer->method('allocateSubdomain')
            ->willThrowException(new InvalidArgumentException('Server srv-1 not found'));

        $controller = $this->makeSubdomainController($passingService, $missingServer);

        $response = $controller->allocate(
            $this->bearer($this->tokenWithKid('notfound-pin-kid')),
            ['id' => 'srv-1'],
        );

        self::assertGateFrame(
            404,
            [
                'error' => 'SERVER_NOT_FOUND',
                'code' => 'server.not_found',
                'message' => 'Server srv-1 not found',
            ],
            $response,
            'SubdomainController::allocate SERVER_NOT_FOUND',
        );
    }

    public function testRelayGateFramesAreWholeFramePinned(): void
    {
        $expiredService = $this->createMock(EnrollmentJwtService::class);
        $expiredService->method('validateEnrollmentJwt')->willReturn(null);
        $mismatchService = $this->createMock(EnrollmentJwtService::class);
        $mismatchService->method('validateEnrollmentJwt')->willReturn(['server_id' => 'srv-other']);
        $throwingService = $this->createMock(EnrollmentJwtService::class);
        $throwingService->method('validateEnrollmentJwt')
            ->willThrowException(new InvalidArgumentException('relay pin boom'));
        $passingService = $this->createMock(EnrollmentJwtService::class);
        $passingService->method('validateEnrollmentJwt')->willReturn(['server_id' => 'srv-1']);

        $catch = [
            'error' => 'UNAUTHORIZED',
            'code' => 'auth.required',
            'message' => 'relay pin boom',
        ];

        $gate = new RelayController($this->createMock(EnrollmentJwtService::class));
        self::assertGateFrame(
            400,
            self::FRAME_MISSING,
            $gate->handle(new Request(), ['id' => '']),
            'RelayController MISSING_SERVER_ID',
        );
        self::assertGateFrame(
            401,
            self::FRAME_HEADER,
            $gate->handle(new Request(), ['id' => 'srv-1']),
            'RelayController header gate',
        );
        self::assertGateFrame(
            401,
            self::FRAME_FORMAT,
            $gate->handle($this->bearer('not-a-valid-jwt'), ['id' => 'srv-1']),
            'RelayController token format',
        );
        $authed = $this->bearer($this->tokenWithKid('gate-pin-kid'));
        self::assertGateFrame(
            401,
            self::FRAME_EXPIRED,
            (new RelayController($expiredService))->handle($authed, ['id' => 'srv-1']),
            'RelayController expired enrollment token',
        );
        self::assertGateFrame(
            401,
            self::FRAME_MISMATCH,
            (new RelayController($mismatchService))->handle($authed, ['id' => 'srv-1']),
            'RelayController server mismatch',
        );
        self::assertGateFrame(
            401,
            $catch,
            (new RelayController($throwingService))->handle($authed, ['id' => 'srv-1']),
            'RelayController invalid-argument catch',
        );

        // 426 steer: authenticated, but no WebSocket upgrade. Wave-2 promotion
        // attaches the registry twin of the ClientMount 426's client_ws_endpoint.
        self::assertGateFrame(
            426,
            [
                'error' => 'UPGRADE_REQUIRED',
                'code' => 'relay.ws_http_endpoint',
                'message' => 'This endpoint requires a WebSocket upgrade. Please connect via WSS.',
                'upgrade' => 'websocket',
            ],
            (new RelayController($passingService))->handle($authed, ['id' => 'srv-1']),
            'RelayController 426 UPGRADE_REQUIRED',
        );
    }

    public function testClientMountMissingServerIdFrameIsWholeFramePinned(): void
    {
        $controller = new ClientMountController($this->createMock(ContainerInterface::class));

        self::assertGateFrame(
            400,
            ['error' => 'MISSING_SERVER_ID', 'code' => 'missing_server_id', 'message' => 'Server ID is required'],
            $controller->handle(new Request(), ['server_id' => '']),
            'ClientMountController MISSING_SERVER_ID',
        );
    }

    public function testServerClaimBadRequestTrioFramesAreWholeFramePinned(): void
    {
        $controller = new ServerClaimController($this->createMock(ClaimRequestHandler::class));

        // newClaim: body present-but-malformed (ClaimRequest::fromPayload shape
        // rejection) → invalid_payload.
        $protocolRequest = new Request();
        $protocolRequest->method = 'POST';
        $protocolRequest->path = '/api/v1/server-claims/new';
        $protocolRequest->headers['Accept-Phlix-Protocol'] = 'v1';
        $protocolRequest->body = [];

        self::assertGateFrame(
            400,
            [
                'error' => 'Bad Request',
                'code' => 'invalid_payload',
                'message' => 'ClaimRequest "serverName" is required.',
            ],
            $controller->newClaim($protocolRequest),
            'ServerClaimController newClaim payload-shape',
        );

        self::assertGateFrame(
            400,
            ['error' => 'Bad Request', 'code' => 'invalid_request', 'message' => 'claim id is required'],
            $controller->status(new Request(), ['claimId' => '']),
            'ServerClaimController status claim-id gate',
        );

        $claimRequest = new Request();
        $claimRequest->method = 'POST';
        $claimRequest->path = '/api/v1/server-claims/claim';
        $claimRequest->userId = 'user-1';
        $claimRequest->body = [];

        self::assertGateFrame(
            400,
            ['error' => 'Bad Request', 'code' => 'invalid_request', 'message' => 'claim_code is required'],
            $controller->claim($claimRequest),
            'ServerClaimController claim_code gate',
        );
    }

    // ------------------------------------------------------------------
    // Wave-2 helpers
    // ------------------------------------------------------------------

    /**
     * Exact WHOLE-FRAME gate assertion: status, exact decoded key set AND
     * order, and byte-exact serialized body. Legacy literal stays in `error`
     * TEXT; the registered dotted code rides `code`.
     *
     * @param array<string, string> $expected
     */
    private static function assertGateFrame(int $status, array $expected, Response $response, string $site): void
    {
        self::assertSame($status, $response->statusCode, $site . ': status drifted');
        self::assertSame(
            $expected,
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR),
            $site . ': frame drifted from the contracted shape',
        );
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $response->body,
            $site . ': serialization drifted',
        );
    }

    private function makeSubdomainController(
        EnrollmentJwtService $jwtService,
        ?DnsAliasManager $dnsAliasManager = null,
    ): SubdomainController {
        return new SubdomainController(
            $dnsAliasManager ?? $this->createMock(DnsAliasManager::class),
            $this->createMock(TlsCertificateManager::class),
            $jwtService,
        );
    }

    private function bearer(string $token): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->headers['Authorization'] = 'Bearer ' . $token;

        return $request;
    }
}
