<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

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
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
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
 * McpController / AlexaSkillController envelopes are NOT adopted here
 * (HUBCTRL owns those files during this wave) and are intentionally absent.
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
}
