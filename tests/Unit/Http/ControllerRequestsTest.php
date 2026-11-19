<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Http\ControllerRequests;
use Phlix\Hub\Http\Controllers\AdminUpdatesController;
use Phlix\Hub\Http\Controllers\ClientRelayTokenController;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Controllers\LibraryController;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Controllers\McpTokenController;
use Phlix\Hub\Http\Controllers\MeController;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Controllers\ServerDetailController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Controllers\UserPasswordController;
use Phlix\Hub\Http\Controllers\UserQuotaController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_THROW_ON_ERROR;

/**
 * Contract pins for {@see ControllerRequests} (refactor F-03/F-04).
 *
 * The seam replaced 29 hand-rolled `$userId = $request->userId ?? '';
 * if ($userId === '')` preambles. Two laws are pinned here:
 *
 *  1. TRIGGER IDENTITY — the seam answers null for exactly the inputs the
 *     old preambles refused on (missing or empty-string identity) and the
 *     verbatim string otherwise, so every handler body still sees a plain
 *     non-empty string after its `=== null` early exit.
 *  2. FRAME FIDELITY — the seam never picks the 401 frame, and this is not
 *     prose: each guarding controller is driven with an unauthenticated
 *     request and its WHOLE response (status, headers, exact body bytes) is
 *     asserted against the literal the file emitted before the conversion.
 *     These pins are the safety net under the F-02/F-19 frame folds that
 *     follow in this wave — any drift in the wire bytes reddens here.
 *
 * Builder-call sites (`$this->unauthorized()`) share one method per file, so
 * one driven entry point per distinct frame covers every former site that
 * routes through it; the inline-literal copies are each driven once.
 *
 * The F-04 half of the file pins the extraction laws the per-controller
 * copies enforced — one assertion set per distinct law, including the
 * NEGATIVE space the scan's "collapse the near-twins" instinct would have
 * broken: the trimming reader trims where the optional reader does not, the
 * row reader coerces where the strict readers refuse, and the two key
 * normalizers disagree on numeric keys in opposite directions. A future
 * "harmless" collapse of any pair reddens these pins.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class ControllerRequestsTest extends TestCase
{
    /** The plain machine-voice auth frame (20 of the 29 former sites). */
    private const PLAIN_401_BODY = '{"error":"Unauthorized","code":"auth.required"}';

    /** Every hub JSON frame carries the compact content type + no-store seed. */
    private const JSON_HEADERS = [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-store',
    ];

    public function testSeamAnswersNullForMissingIdentity(): void
    {
        self::assertNull(ControllerRequests::authenticatedUserId(new Request()));
    }

    public function testSeamAnswersNullForEmptyStringIdentity(): void
    {
        $request = new Request();
        $request->userId = '';

        self::assertNull(ControllerRequests::authenticatedUserId($request));
    }

    public function testSeamReturnsTheIdentityVerbatim(): void
    {
        $request = new Request();
        $request->userId = 'user-7';

        self::assertSame('user-7', ControllerRequests::authenticatedUserId($request));
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function plain401Provider(): array
    {
        return [
            'AdminUpdates::status (requireAdmin leg)' => [AdminUpdatesController::class, 'status'],
            'ClientRelayToken::mint' => [ClientRelayTokenController::class, 'mint'],
            'Library::listForServer' => [LibraryController::class, 'listForServer'],
            'Me::__invoke' => [MeController::class, '__invoke'],
            'McpToken::index (builder family)' => [McpTokenController::class, 'index'],
            'McpToken::create (builder family)' => [McpTokenController::class, 'create'],
            'McpToken::revoke (builder family)' => [McpTokenController::class, 'revoke'],
            'Request::createRequest (builder family)' => [RequestController::class, 'createRequest'],
            'Request::listMyRequests (builder family)' => [RequestController::class, 'listMyRequests'],
            'Request::getMyRequest (builder family)' => [RequestController::class, 'getMyRequest'],
            'Request::deleteMyRequest (builder family)' => [RequestController::class, 'deleteMyRequest'],
            'Request::listAdminRequests (requireAdmin leg)' => [RequestController::class, 'listAdminRequests'],
            'ServerDetail::getServerDetail' => [ServerDetailController::class, 'getServerDetail'],
            'ServerList::listServers' => [ServerListController::class, 'listServers'],
            'ServerManage::deleteServer' => [ServerManageController::class, 'deleteServer'],
            'ServerManage::accessInfo' => [ServerManageController::class, 'accessInfo'],
            'ServerProxy::proxy (error()-form)' => [ServerProxyController::class, 'proxy'],
            'UserPassword::changePassword' => [UserPasswordController::class, 'changePassword'],
            'UserQuota::viewOwnBandwidth (builder family)' => [UserQuotaController::class, 'viewOwnBandwidth'],
            'UserQuota::viewUserBandwidth (requireAdmin leg)' => [UserQuotaController::class, 'viewUserBandwidth'],
        ];
    }

    #[DataProvider('plain401Provider')]
    public function testPlainAuthFrameSurvivesTheSeamByteForByte(string $class, string $method): void
    {
        $response = $this->drive($class, $method, new Request());

        self::assertSame(401, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame(self::PLAIN_401_BODY, $response->body);
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function memberProse401Provider(): array
    {
        return [
            'LibraryShare::createShare' => [
                LibraryShareController::class,
                'createShare',
                'Sign in to share your libraries',
            ],
            'LibraryShare::listShares' => [
                LibraryShareController::class,
                'listShares',
                'Sign in to see your shares',
            ],
            'LibraryShare::deleteShare' => [
                LibraryShareController::class,
                'deleteShare',
                'Sign in to remove a share',
            ],
            'LibraryShare::updateShare' => [
                LibraryShareController::class,
                'updateShare',
                'Sign in to update a share',
            ],
            'InviteLink::createInviteLink' => [
                InviteLinkController::class,
                'createInviteLink',
                'Sign in to create invite links',
            ],
            'InviteLink::listInviteLinks' => [
                InviteLinkController::class,
                'listInviteLinks',
                'Sign in to see your invite links',
            ],
            'InviteLink::deleteInviteLink' => [
                InviteLinkController::class,
                'deleteInviteLink',
                'Sign in to revoke an invite link',
            ],
            'InviteLink::redeem' => [
                InviteLinkController::class,
                'redeem',
                'Sign in to accept this invite',
            ],
        ];
    }

    /**
     * The member channel mirrors its human sentence into `message` and keeps
     * the registered code — error() shape, key order error, code, message.
     */
    #[DataProvider('memberProse401Provider')]
    public function testMemberProseFrameSurvivesTheSeamByteForByte(string $class, string $method, string $prose): void
    {
        $expected = json_encode(
            ['error' => $prose, 'code' => 'auth.required', 'message' => $prose],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $response = $this->drive($class, $method, new Request());

        self::assertSame(401, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame($expected, $response->body);
    }

    /**
     * The claim envelope is the deliberate outsider: machine-voice `error`,
     * dotted `code`, legacy SCREAMING literal parked in the `message` field
     * — `error` NOT being prose is legal for this family (HUB-04
     * dual-placement). It must NEVER be collapsed into the plain or member
     * frames — pinned as distinct bytes.
     */
    public function testClaimAuthFrameStaysMachineVoiceByteForByte(): void
    {
        $response = $this->drive(ServerClaimController::class, 'claim', new Request());

        self::assertSame(401, $response->statusCode);
        self::assertSame(self::JSON_HEADERS, $response->headers);
        self::assertSame(
            '{"error":"UNAUTHENTICATED","code":"auth.unauthenticated",' .
            '"message":"Authentication is required to claim a server"}',
            $response->body,
        );
    }

    /**
     * F-04 — the Request-body strict law: read only strings, everything else
     * (absent, null, number, list) collapses to `''`. No coercion, and the
     * fold truthfully deleted the old copy's claim of a raw-POST fallback:
     * that fallback never existed in any of the three absorbed copies.
     */
    public function testStringFieldReadsOnlyStringsFromTheBody(): void
    {
        $request = new Request();
        $request->body = [
            'username' => 'ada',
            'pin' => '123',
            'count' => 7,
            'nothing' => null,
            'list' => [1, 2],
        ];

        self::assertSame('ada', ControllerRequests::stringField($request, 'username'));
        self::assertSame('123', ControllerRequests::stringField($request, 'pin'));
        self::assertSame('', ControllerRequests::stringField($request, 'count'));
        self::assertSame('', ControllerRequests::stringField($request, 'nothing'));
        self::assertSame('', ControllerRequests::stringField($request, 'list'));
        self::assertSame('', ControllerRequests::stringField($request, 'absent'));
    }

    /** The array-source front door reads under the same law (OAuth::str fold). */
    public function testStringOrEmptySharesTheStrictBodyLaw(): void
    {
        $source = ['a' => 'x', 'n' => 7, 'nul' => null, 'l' => [1]];

        self::assertSame('x', ControllerRequests::stringOrEmpty($source, 'a'));
        self::assertSame('', ControllerRequests::stringOrEmpty($source, 'n'));
        self::assertSame('', ControllerRequests::stringOrEmpty($source, 'nul'));
        self::assertSame('', ControllerRequests::stringOrEmpty($source, 'l'));
        self::assertSame('', ControllerRequests::stringOrEmpty($source, 'absent'));

        // Identity-of-law pin: the two front doors cannot drift apart.
        $request = new Request();
        $request->body = $source;
        foreach (['a', 'n', 'nul', 'l', 'absent'] as $key) {
            self::assertSame(
                ControllerRequests::stringOrEmpty($source, $key),
                ControllerRequests::stringField($request, $key),
            );
        }
    }

    /** The base law under both strict readers (AdminDashboard::stringOf fold). */
    public function testStringOfIsTheStrictBaseLaw(): void
    {
        self::assertSame('x', ControllerRequests::stringOf('x'));
        self::assertSame('123', ControllerRequests::stringOf('123'));
        self::assertSame('', ControllerRequests::stringOf(7));
        self::assertSame('', ControllerRequests::stringOf(null));
        self::assertSame('', ControllerRequests::stringOf([1]));
        self::assertSame('', ControllerRequests::stringOf(true));
    }

    /**
     * `state` semantics (OAuth::strOrNull fold): absent, empty and non-string
     * all mean "not supplied"; a supplied string survives VERBATIM — the
     * opposite of the trimming law below, pinned to stop a collapse.
     */
    public function testOptionalStringDistinguishesAbsentFromEmpty(): void
    {
        $source = ['set' => 'v', 'empty' => '', 'num' => 5, 'spaced' => ' a '];

        self::assertSame('v', ControllerRequests::optionalString($source, 'set'));
        self::assertNull(ControllerRequests::optionalString($source, 'empty'));
        self::assertNull(ControllerRequests::optionalString($source, 'num'));
        self::assertNull(ControllerRequests::optionalString($source, 'absent'));
        // No trim: the raw spelling is preserved.
        self::assertSame(' a ', ControllerRequests::optionalString($source, 'spaced'));
    }

    /**
     * The Alexa slot law: trim, then non-empty. Distinct from
     * {@see optionalString()} on purpose — whitespace-only is noise there,
     * here it must not surface as a blank slot value.
     */
    public function testTrimmedStringTrimsAndRejectsBlank(): void
    {
        $source = ['pad' => '  a  b ', 'blank' => " \t\n", 'empty' => '', 'num' => 5];

        self::assertSame('a  b', ControllerRequests::trimmedString($source, 'pad'));
        self::assertNull(ControllerRequests::trimmedString($source, 'blank'));
        self::assertNull(ControllerRequests::trimmedString($source, 'empty'));
        self::assertNull(ControllerRequests::trimmedString($source, 'num'));
        self::assertNull(ControllerRequests::trimmedString($source, 'absent'));
    }

    /**
     * The MySQL-row law (AdminUser::str fold): int/float COERCE to string so
     * a driver-typed key reaches the wire as a string — must NEVER be
     * collapsed with the strict readers, which refuse numbers.
     */
    public function testRowStringCoercesNumericColumnAmbiguity(): void
    {
        $row = ['id' => 42, 'score' => 1.5, 'name' => 'ada', 'flag' => true, 'nul' => null];

        self::assertSame('42', ControllerRequests::rowString($row, 'id'));
        self::assertSame('1.5', ControllerRequests::rowString($row, 'score'));
        self::assertSame('ada', ControllerRequests::rowString($row, 'name'));
        self::assertSame('', ControllerRequests::rowString($row, 'flag'));
        self::assertSame('', ControllerRequests::rowString($row, 'nul'));
        self::assertSame('', ControllerRequests::rowString($row, 'absent'));
    }

    /**
     * Alexa's normalizer CASTS numeric keys (no payload field is lost);
     * values are never touched. Contrast the drop law below.
     */
    public function testStringKeyedCastsNumericKeysAndKeepsValues(): void
    {
        $keyed = ControllerRequests::stringKeyed([0 => 'zero', 'a' => 7]);

        self::assertSame(['0' => 'zero', 'a' => 7], $keyed);
        self::assertSame([], ControllerRequests::stringKeyed([]));
    }

    /**
     * Mcp's normalizer DROPS non-string keys — positional JSON-RPC params
     * are unusable by name-addressed tool args — and non-arrays collapse to
     * `[]`. The opposite law of {@see stringKeyed()} on purpose.
     */
    public function testStringKeyedParamsDropsNonStringKeys(): void
    {
        self::assertSame(
            ['a' => 1, 'c' => 'd'],
            ControllerRequests::stringKeyedParams(['a' => 1, 2 => 'b', 'c' => 'd']),
        );
        self::assertSame([], ControllerRequests::stringKeyedParams([]));
        self::assertSame([], ControllerRequests::stringKeyedParams('positional'));
        self::assertSame([], ControllerRequests::stringKeyedParams(null));
        self::assertSame([], ControllerRequests::stringKeyedParams(7));
    }

    /**
     * Build the controller under test with mock collaborators and call the
     * pinning entry point. Guards are the first statement of every driven
     * method, so no collaborator is ever reached; the mocks exist to prove
     * that by construction. The class key arrives as a plain string from the
     * provider; every accepted value is a controller `::class` literal in
     * the `match` below and any other value fails loud.
     *
     * @param string $class
     * @param string $method
     */
    private function drive(string $class, string $method, Request $request): Response
    {
        if ($class === MeController::class) {
            // The only __invoke-shaped entry point in the guard family.
            $me = new MeController(
                $this->createMock(AuthManager::class),
                $this->createMock(ServerInfoHandler::class),
            );

            return $me($request);
        }

        $controller = match ($class) {
            AdminUpdatesController::class => new AdminUpdatesController(
                // Final collaborator: real service over mock seams (existing
                // CoreUpdateCheckServiceTest recipe), never reached — the
                // guard refuses before the first collaborator call.
                new CoreUpdateCheckService(
                    $this->createMock(HubSettingsRepository::class),
                    $this->createMock(VersionMarkerFetcherInterface::class),
                    $this->createMock(StructuredLogger::class),
                    'https://marker.invalid/hub-version.json',
                    '/bin/true',
                ),
                $this->createMock(UserRepository::class),
                $this->createMock(AuditLogger::class),
            ),
            ClientRelayTokenController::class => new ClientRelayTokenController(
                new ClientRelayTokenService($this->createMock(Connection::class)),
                $this->createMock(ServerInfoHandler::class),
                $this->createMock(AuditLogger::class),
                $this->createMock(RateLimiterInterface::class),
            ),
            InviteLinkController::class => new InviteLinkController($this->createMock(InviteLinkHandler::class)),
            LibraryController::class => new LibraryController($this->createMock(LibrarySharingHandler::class)),
            LibraryShareController::class => new LibraryShareController(
                $this->createMock(LibrarySharingHandler::class),
            ),
            McpTokenController::class => new McpTokenController(
                new McpTokenService($this->createMock(Connection::class)),
                $this->createMock(AuditLogger::class),
                false,
            ),
            MeController::class => new MeController(
                $this->createMock(AuthManager::class),
                $this->createMock(ServerInfoHandler::class),
            ),
            RequestController::class => new RequestController(
                $this->createMock(RequestManager::class),
                $this->createMock(RequestNotification::class),
                $this->createMock(UserRepository::class),
                $this->createMock(AuditLogger::class),
            ),
            ServerClaimController::class => new ServerClaimController($this->createMock(ClaimRequestHandler::class)),
            ServerDetailController::class => new ServerDetailController(
                $this->createMock(ServerInfoHandler::class),
                $this->createMock(RelaySessionManager::class),
                $this->createMock(HeartbeatHandler::class),
                $this->createMock(TlsCertificateManager::class),
            ),
            ServerListController::class => new ServerListController($this->createMock(ServerInfoHandler::class)),
            ServerManageController::class => new ServerManageController(
                $this->createMock(ServerInfoHandler::class),
                $this->createMock(Connection::class),
            ),
            ServerProxyController::class => new ServerProxyController(
                $this->createMock(ServerInfoHandler::class),
                new RelayProxyBridge($this->createMock(StructuredLogger::class)),
                $this->createMock(StructuredLogger::class),
                $this->createMock(RelaySessionManager::class),
                $this->createMock(RateLimiterInterface::class),
            ),
            UserPasswordController::class => new UserPasswordController($this->createMock(AuthManager::class)),
            UserQuotaController::class => new UserQuotaController(
                $this->createMock(RelaySessionManager::class),
                $this->createMock(UserRepository::class),
                $this->createMock(AuditLogger::class),
            ),
            default => $this->fail('unknown pin target ' . $class),
        };

        // Every remaining entry point takes (Request, params); the guards are
        // the first statement, so the empty params are never read.
        return $controller->{$method}($request, []);
    }
}
