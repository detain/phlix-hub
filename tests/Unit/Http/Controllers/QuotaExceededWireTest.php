<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

/**
 * W5 wire-law pins: the two quota throws and the redeem passthrough must
 * surface the REGISTERED `quota.exceeded` code at HTTP level — the handler
 * message strings are internal, the client contract is the code.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class QuotaExceededWireTest extends TestCase
{
    use DecodedJsonAssertions;

    public function testClaimControllerMapsServerCapReachedTo409Quota(): void
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')
            ->willThrowException(new InvalidArgumentException('SERVER_CAP_REACHED'));

        $controller = new ServerClaimController($handler);

        $request        = new Request();
        $request->path  = '/api/v1/server-claims/claim';
        $request->method = 'POST';
        $request->userId = 'user-1';
        $request->body   = ['claim_code' => 'DR4Q-7AXB'];

        $response = $controller->claim($request);

        self::assertSame(409, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('quota.exceeded', $body['code']);
        self::assertSame('SERVER_CAP_REACHED', $body['error'], 'legacy literal parks in the text field');
        self::assertSame('Server quota reached for this account', $body['message']);
    }

    public function testShareControllerMapsCollaboratorCapTo409Quota(): void
    {
        $handler = $this->createMock(LibrarySharingHandler::class);
        $handler->method('shareLibrary')
            ->willThrowException(new InvalidArgumentException('SERVER_SHARE_CAP_REACHED', 409));

        $controller = new LibraryShareController($handler);

        $request        = new Request();
        $request->path  = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'owner-1';
        $request->body   = [
            'collaborator_email' => 'friend@example.com',
            'server_id'          => 'server-1',
            'library_id'         => 'lib-1',
            'library_name'       => 'My Movies',
        ];

        $response = $controller->createShare($request);

        self::assertSame(409, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('quota.exceeded', $body['code']);
        self::assertSame('This server has reached its collaborator limit', $body['message']);
    }

    public function testInviteRedeemSurfacesTheSameQuotaWire(): void
    {
        // Redeem converges on the same share choke, so the cap throw must be
        // discriminated BEFORE the generic 409 (share_exists) arm.
        $handler = $this->createMock(InviteLinkHandler::class);
        $handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('SERVER_SHARE_CAP_REACHED', 409));

        $controller = new InviteLinkController($handler);

        $request        = new Request();
        $request->path  = '/api/v1/me/invite-links/tok123/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $controller->redeem($request, ['token' => 'tok123']);

        self::assertSame(409, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('quota.exceeded', $body['code']);
    }
}
