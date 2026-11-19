<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\ControllerErrorFrames;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Golden-byte pins for {@see ControllerErrorFrames} (refactor F-02).
 *
 * The kit absorbed the hub's duplicated controller error frames — the
 * byte-identical plain-401 pair (and its ten inlined copies), the two
 * member-voice `memberError()` builders, the MCP challenge frame, the
 * admin-user `notFound()`/`conflict()` builders and the two identical
 * `mapError()` default arms. Because a dedupe that shifts a single byte is
 * a wire change, every frame the kit emits is pinned here as whole exact
 * bytes — status, header map (MCP frame: order included) and body string —
 * and each pin is cross-checked against the legacy inline construction the
 * converted site used to carry. The driven guard-family pins in
 * {@see \Phlix\Hub\Tests\Unit\Http\ControllerRequestsTest} prove the same
 * frames still leave the controllers themselves; this file is the shape
 * proof of the kit.
 *
 * The `auth.unauthenticated` claim envelope (ServerClaimController) and the
 * OAuth token/user-info envelopes are deliberately ABSENT: they are
 * registered outsider families the kit must never absorb (HUB-04
 * dual-placement law), so they have no goldens here.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ControllerErrorFramesTest extends TestCase
{
    /** Every hub JSON frame carries the compact content type + no-store seed. */
    private const JSON_HEADERS = [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-store',
    ];

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
     */
    public static function memberFrameProvider(): array
    {
        $generic = ControllerErrorFrames::GENERIC_PROBLEM;

        return [
            'plain member prose' => [
                401,
                'auth.required',
                'Sign in to see your shares',
                '{"error":"Sign in to see your shares","code":"auth.required",' .
                    '"message":"Sign in to see your shares"}',
                [],
            ],
            'prose is trimmed before it reaches the wire' => [
                409,
                'conflict',
                '  padded sentence  ',
                '{"error":"padded sentence","code":"conflict","message":"padded sentence"}',
                [],
            ],
            'blank prose falls back to the generic sentence' => [
                500,
                'unknown_error',
                '   ',
                '{"error":"' . $generic . '","code":"unknown_error","message":"' . $generic . '"}',
                [],
            ],
            'extras ride after the three house keys, in caller order' => [
                429,
                'quota.exceeded',
                'Slow down',
                '{"error":"Slow down","code":"quota.exceeded","message":"Slow down","limit":10}',
                ['limit' => 10],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('memberFrameProvider')]
    public function testMemberFrameIsPinnedByteForByte(
        int $status,
        string $code,
        string $message,
        string $expectedBody,
        array $extra
    ): void {
        $response = ControllerErrorFrames::memberError($status, $code, $message, $extra);

        self::assertSame($status, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame($expectedBody, $response->body);
    }

    /**
     * The member builder equals the legacy hand-rolled emission: the very
     * expression every converted `$this->memberError(...)` call site used to
     * evaluate to, run over the same inputs.
     */
    public function testMemberFrameEqualsTheLegacyLocalBuilder(): void
    {
        $human = 'Sign in to create invite links';
        $legacy = (new Response())->error(401, 'auth.required', $human, ['message' => $human]);
        $kit = ControllerErrorFrames::memberError(401, 'auth.required', $human);

        self::assertSame($legacy->statusCode, $kit->statusCode);
        self::assertSame($legacy->headers, $kit->headers);
        self::assertSame($legacy->body, $kit->body);
    }

    public function testPlainUnauthorizedFrameIsTheRegisteredBytes(): void
    {
        $response = ControllerErrorFrames::unauthorized();

        self::assertSame(401, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame('{"error":"Unauthorized","code":"auth.required"}', $response->body);
    }

    /**
     * Cross-check against the literal the pair + ten inlined copies emitted
     * (both the `status()->json()` spelling and ServerProxy's `error()`
     * spelling — the wire never saw the difference).
     */
    public function testPlainUnauthorizedEqualsBothLegacySpellings(): void
    {
        $jsonSpelling = (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code' => 'auth.required',
        ]);
        $errorSpelling = (new Response())->error(401, 'auth.required', 'Unauthorized');
        $kit = ControllerErrorFrames::unauthorized();

        foreach ([$jsonSpelling, $errorSpelling] as $legacy) {
            self::assertSame($legacy->statusCode, $kit->statusCode);
            self::assertSame($legacy->headers, $kit->headers);
            self::assertSame($legacy->body, $kit->body);
        }
    }

    public function testMcpUnauthorizedFrameChallengesWithOrderedHeaders(): void
    {
        $response = ControllerErrorFrames::mcpUnauthorized('auth.invalid_token', 'Token rejected');

        self::assertSame(401, $response->statusCode);
        // The legacy builder applied header() BEFORE json(); a re-ordered kit
        // would still serialize the same body but flip this map's order.
        self::assertSame([
            'WWW-Authenticate' => 'Bearer realm="phlix-hub-mcp"',
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
        ], $response->headers);
        self::assertSame(
            '{"error":"Unauthorized","code":"auth.invalid_token","message":"Token rejected"}',
            $response->body,
        );
    }

    public function testUserNotFoundFrameIsPinned(): void
    {
        $response = ControllerErrorFrames::userNotFound();

        self::assertSame(404, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame('{"error":"User not found","code":"user_not_found"}', $response->body);
    }

    public function testMissingUserIdFrameIsPinned(): void
    {
        $response = ControllerErrorFrames::missingUserId();

        self::assertSame(400, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame('{"error":"Bad Request","code":"missing_user_id"}', $response->body);
    }

    public function testInternalErrorFrameMatchesBothMapErrorDefaultArms(): void
    {
        $legacy = (new Response())->error(
            500,
            'hub.internal_error',
            'HUB_INTERNAL_ERROR',
            ['message' => 'An unexpected error occurred.'],
        );
        $kit = ControllerErrorFrames::internalError();

        self::assertSame(500, $kit->statusCode);
        self::assertSame($legacy->headers, $kit->headers);
        self::assertSame(
            '{"error":"HUB_INTERNAL_ERROR","code":"hub.internal_error",' .
                '"message":"An unexpected error occurred."}',
            $kit->body,
        );
    }

    /**
     * The `conflict()` foot-gun proof. AdminUserController's private builder
     * took `(string $message, string $code)` — INVERTED against the house
     * `(status, code, message)` convention — while still emitting the right
     * wire bytes because the body map placed each arg in its own slot. The
     * kit keeps the wire and fixes the signature; these goldens are built
     * from the four real call sites' legacy args, read in the legacy order,
     * and pin the byte output — so a mis-sorted adoption anywhere reddens.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     *         [legacy $message, legacy $code, expected body]
     */
    public static function conflictFrameProvider(): array
    {
        return [
            'delete own account' => [
                'Cannot delete your own account',
                'cannot_delete_self',
                '{"error":"Conflict","code":"cannot_delete_self",' .
                    '"message":"Cannot delete your own account"}',
            ],
            'delete last admin' => [
                'Cannot delete the last admin',
                'last_admin',
                '{"error":"Conflict","code":"last_admin",' .
                    '"message":"Cannot delete the last admin"}',
            ],
            'demote yourself' => [
                'Cannot demote yourself',
                'cannot_demote_self',
                '{"error":"Conflict","code":"cannot_demote_self",' .
                    '"message":"Cannot demote yourself"}',
            ],
            'demote last admin' => [
                'Cannot demote the last admin',
                'last_admin',
                '{"error":"Conflict","code":"last_admin",' .
                    '"message":"Cannot demote the last admin"}',
            ],
        ];
    }

    /**
     * @param string $legacyMessage First arg at the old call site.
     * @param string $legacyCode    Second arg at the old call site.
     */
    #[DataProvider('conflictFrameProvider')]
    public function testConflictFrameKeepsLegacyBytesUnderTheFixedSignature(
        string $legacyMessage,
        string $legacyCode,
        string $expectedBody
    ): void {
        // Legacy order was (message, code); the kit takes (code, message).
        // Feeding each call site's values into the slots the old body map
        // used proves the signature fix moved zero bytes.
        $response = ControllerErrorFrames::conflict($legacyCode, $legacyMessage);

        self::assertSame(409, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame($expectedBody, $response->body);
    }

    /**
     * Slot placement, asserted structurally: the house-order signature must
     * land the code in `code` and the prose in `message`.
     */
    public function testConflictSignatureIsHouseOrderNotTheLegacyInversion(): void
    {
        $decoded = json_decode(
            ControllerErrorFrames::conflict('some_code', 'Some prose')->body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        self::assertSame(['error', 'code', 'message'], array_keys($decoded));
        self::assertSame('some_code', $decoded['code']);
        self::assertSame('Some prose', $decoded['message']);
    }
    /**
     * F-19: the machine frame with the mirrored human `message` twin — the
     * byte-identical law the AuthController and UserPasswordController
     * private copies shared. Key order is error, code, message.
     */
    public function testErrorFrameIsPinnedByteForByte(): void
    {
        $frame = ControllerErrorFrames::errorFrame(400, 'validation_failed', 'password too short');

        self::assertSame(400, $frame->statusCode);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            $frame->headers,
        );
        self::assertSame(
            '{"error":"password too short","code":"validation_failed",' .
            '"message":"password too short"}',
            $frame->body,
        );
    }

    /**
     * Status passthrough law: the frame builder never edits the status, only
     * the pair of message slots — pinned for the two arms the controllers
     * actually emit through the fold.
     */
    public function testErrorFrameCarriesEveryStatusVerbatim(): void
    {
        $frame = ControllerErrorFrames::errorFrame(403, 'auth.signups_disabled', 'Signups are closed');

        self::assertSame(403, $frame->statusCode);
        self::assertSame(
            '{"error":"Signups are closed","code":"auth.signups_disabled",' .
            '"message":"Signups are closed"}',
            $frame->body,
        );
    }
}
