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
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Review hub__casualgamer M5: the member-facing error channel must speak
 * human. The pinned portal client renders the `error` field verbatim
 * (`extractError` checks `error` before `message`), so every failure arm of
 * the two member-facing share/invite controllers must carry a human
 * sentence there — never an HTTP phrase ("Conflict", "Bad Request", …),
 * never an empty string. `code` keeps the registered machine code and
 * `message` mirrors `error` so the sentence renders under either client
 * precedence. Machine-facing claim envelopes (ServerClaimController) are
 * deliberately excluded: their `error` literals are string-matched by
 * programmatic clients (HUB-04 law).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class MemberErrorProseTest extends TestCase
{
    use DecodedJsonAssertions;

    /** HTTP reason phrases the member channel must never surface verbatim. */
    private const HTTP_PHRASES = [
        'Conflict',
        'Bad Request',
        'Not Found',
        'Gone',
        'Unauthorized',
        'Forbidden',
        'Internal Server Error',
        'Unprocessable Entity',
    ];

    private const GENERIC_PROBLEM = 'Something went wrong on the hub. Please try again.';

    /**
     * Assert the canonical member-facing envelope.
     *
     * @param array<string, string> $expect Key order pinned: error, code, message.
     */
    private static function assertEnvelope(Response $response, int $status, array $expect): void
    {
        self::assertSame($status, $response->statusCode);

        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame(['error', 'code', 'message'], array_keys($body));

        $error = self::stringNode($body['error']);
        $message = self::stringNode($body['message']);

        self::assertSame($expect['code'], self::stringNode($body['code']));
        self::assertSame($expect['error'], $error, 'the human sentence rides the error field');
        self::assertSame($error, $message, 'message mirrors error');
        self::assertNotContains($error, self::HTTP_PHRASES, 'members never see bare HTTP phrases');
        self::assertNotSame('', $error, 'the member channel never ships an empty error');
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function request(string $method, string $path, ?string $userId, array $body = []): Request
    {
        $request         = new Request();
        $request->path   = $path;
        $request->method = $method;
        $request->userId = $userId;
        $request->body   = $body;

        return $request;
    }

    /** Valid createShare body; the thrown exception picks the arm.
     *
     * @return array<string, mixed>
     */
    private static function shareBody(): array
    {
        return [
            'collaborator_email' => 'friend@example.com',
            'server_id'          => 'server-1',
            'library_id'         => 'lib-1',
            'library_name'       => 'My Movies',
        ];
    }

    // ---------------------------------------------------------------- shares

    public function testCreateShareGuardArmsSpeakHuman(): void
    {
        $controller = new LibraryShareController($this->createMock(LibrarySharingHandler::class));

        self::assertEnvelope(
            $controller->createShare(self::request('POST', '/api/v1/me/shares', null)),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to share your libraries'],
        );
        self::assertEnvelope(
            $controller->createShare(self::request('POST', '/api/v1/me/shares', 'owner-1')),
            400,
            ['code' => 'invalid_body', 'error' => 'A request body is required'],
        );
        self::assertEnvelope(
            $controller->createShare(self::request('POST', '/api/v1/me/shares', 'owner-1', ['server_id' => 's'])),
            400,
            [
                'code'  => 'missing_collaborator_email',
                'error' => 'Enter the email address of the person you want to share with',
            ],
        );
        self::assertEnvelope(
            $controller->createShare(self::request(
                'POST',
                '/api/v1/me/shares',
                'owner-1',
                ['collaborator_email' => 'friend@example.com'],
            )),
            400,
            ['code' => 'missing_server_id', 'error' => 'Select which server the library lives on'],
        );
        self::assertEnvelope(
            $controller->createShare(self::request(
                'POST',
                '/api/v1/me/shares',
                'owner-1',
                ['collaborator_email' => 'friend@example.com', 'server_id' => 'server-1'],
            )),
            400,
            ['code' => 'missing_library_id', 'error' => 'Select which library to share'],
        );
        self::assertEnvelope(
            $controller->createShare(self::request(
                'POST',
                '/api/v1/me/shares',
                'owner-1',
                ['collaborator_email' => 'friend@example.com', 'server_id' => 'server-1', 'library_id' => 'lib-1'],
            )),
            400,
            ['code' => 'missing_library_name', 'error' => 'Give the shared library a name'],
        );
    }

    public function testCreateShareHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['SERVER_SHARE_CAP_REACHED', 409, 'quota.exceeded', 'This server has reached its collaborator limit'],
            ['no such user', 404, 'user_not_found', 'No user found with that email address'],
            ['not yours', 403, 'not_server_owner', 'You do not own this server'],
            ['already shared', 409, 'share_exists', 'This library is already shared with that user'],
            [
                'Permission must be read, write, or admin',
                400,
                'invalid_request',
                'Permission must be read, write, or admin',
            ],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(LibrarySharingHandler::class);
            $handler->method('shareLibrary')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new LibraryShareController($handler);

            self::assertEnvelope(
                $controller->createShare(self::request('POST', '/api/v1/me/shares', 'owner-1', self::shareBody())),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    public function testCreateSharePassthroughNormalizesEmptyExceptionMessage(): void
    {
        // The client's `typeof === 'string'` check renders "" verbatim, so an
        // empty pass-through message must be normalized, never shipped.
        $handler = $this->createMock(LibrarySharingHandler::class);
        $handler->method('shareLibrary')
            ->willThrowException(new InvalidArgumentException('   ', 400));
        $controller = new LibraryShareController($handler);

        self::assertEnvelope(
            $controller->createShare(self::request('POST', '/api/v1/me/shares', 'owner-1', self::shareBody())),
            400,
            ['code' => 'invalid_request', 'error' => self::GENERIC_PROBLEM],
        );
    }

    public function testListSharesGuardArmSpeaksHuman(): void
    {
        $controller = new LibraryShareController($this->createMock(LibrarySharingHandler::class));

        self::assertEnvelope(
            $controller->listShares(self::request('GET', '/api/v1/me/shares', null)),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to see your shares'],
        );
    }

    public function testDeleteShareGuardArmsSpeakHuman(): void
    {
        $controller = new LibraryShareController($this->createMock(LibrarySharingHandler::class));

        self::assertEnvelope(
            $controller->deleteShare(self::request('DELETE', '/api/v1/me/shares/x', null), ['id' => 's1']),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to remove a share'],
        );
        self::assertEnvelope(
            $controller->deleteShare(self::request('DELETE', '/api/v1/me/shares/s1', 'owner-1'), []),
            400,
            ['code' => 'missing_share_id', 'error' => 'Select the share you want to remove'],
        );
    }

    public function testDeleteShareHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['gone share', 404, 'share_not_found', 'That share no longer exists'],
            ['not yours', 403, 'not_share_owner', 'You do not own this share'],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(LibrarySharingHandler::class);
            $handler->method('revokeShare')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new LibraryShareController($handler);

            self::assertEnvelope(
                $controller->deleteShare(
                    self::request('DELETE', '/api/v1/me/shares/s1', 'owner-1'),
                    ['id' => 's1'],
                ),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    public function testUpdateShareArmsSpeakHuman(): void
    {
        $controller = new LibraryShareController($this->createMock(LibrarySharingHandler::class));

        self::assertEnvelope(
            $controller->updateShare(self::request('PATCH', '/api/v1/me/shares/x', null), ['id' => 's1']),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to update a share'],
        );
        self::assertEnvelope(
            $controller->updateShare(self::request('PATCH', '/api/v1/me/shares/s1', 'owner-1'), []),
            400,
            ['code' => 'missing_share_id', 'error' => 'Select the share you want to change'],
        );
        self::assertEnvelope(
            $controller->updateShare(self::request('PATCH', '/api/v1/me/shares/s1', 'owner-1'), ['id' => 's1']),
            400,
            ['code' => 'invalid_body', 'error' => 'A request body is required'],
        );
        self::assertEnvelope(
            $controller->updateShare(
                self::request('PATCH', '/api/v1/me/shares/s1', 'owner-1', ['permission' => 42]),
                ['id' => 's1'],
            ),
            400,
            ['code' => 'missing_permission', 'error' => 'Choose a permission level for this share'],
        );
    }

    public function testUpdateShareHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['gone share', 404, 'share_not_found', 'That share no longer exists'],
            ['not yours', 403, 'not_share_owner', 'You do not own this share'],
            [
                'Permission must be read, write, or admin',
                400,
                'invalid_permission',
                'Permission must be read, write, or admin',
            ],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(LibrarySharingHandler::class);
            $handler->method('updateSharePermission')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new LibraryShareController($handler);

            self::assertEnvelope(
                $controller->updateShare(
                    self::request('PATCH', '/api/v1/me/shares/s1', 'owner-1', ['permission' => 'write']),
                    ['id' => 's1'],
                ),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    // ---------------------------------------------------------- invite links

    public function testInviteCreateGuardArmsSpeakHuman(): void
    {
        $controller = new InviteLinkController($this->createMock(InviteLinkHandler::class));

        self::assertEnvelope(
            $controller->createInviteLink(self::request('POST', '/api/v1/me/invite-links', null)),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to create invite links'],
        );
        self::assertEnvelope(
            $controller->createInviteLink(self::request('POST', '/api/v1/me/invite-links', 'owner-1')),
            400,
            ['code' => 'invalid_body', 'error' => 'A request body is required'],
        );
        self::assertEnvelope(
            $controller->createInviteLink(self::request('POST', '/api/v1/me/invite-links', 'owner-1', ['x' => 1])),
            400,
            ['code' => 'missing_server_id', 'error' => 'Select which server the invite is for'],
        );
    }

    public function testInviteCreateHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['not yours', 403, 'not_server_owner', 'You do not own this server'],
            ['Server id must be a UUID', 400, 'invalid_request', 'Server id must be a UUID'],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(InviteLinkHandler::class);
            $handler->method('createInviteLink')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new InviteLinkController($handler);

            self::assertEnvelope(
                $controller->createInviteLink(
                    self::request('POST', '/api/v1/me/invite-links', 'owner-1', ['server_id' => 'server-1']),
                ),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    public function testInviteListGuardArmSpeaksHuman(): void
    {
        $controller = new InviteLinkController($this->createMock(InviteLinkHandler::class));

        self::assertEnvelope(
            $controller->listInviteLinks(self::request('GET', '/api/v1/me/invite-links', null)),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to see your invite links'],
        );
    }

    public function testInviteDeleteArmsSpeakHuman(): void
    {
        $idle = $this->createMock(InviteLinkHandler::class);

        $controller = new InviteLinkController($idle);
        self::assertEnvelope(
            $controller->deleteInviteLink(self::request('DELETE', '/api/v1/me/invite-links/x', null), ['id' => 'l1']),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to revoke an invite link'],
        );
        self::assertEnvelope(
            $controller->deleteInviteLink(self::request('DELETE', '/api/v1/me/invite-links/x', 'owner-1'), []),
            400,
            ['code' => 'missing_link_id', 'error' => 'Select the invite link you want to revoke'],
        );
    }

    public function testInviteDeleteHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['gone link', 404, 'invite_link_not_found', 'That invite link no longer exists'],
            ['not yours', 403, 'not_link_owner', 'You do not own this invite link'],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(InviteLinkHandler::class);
            $handler->method('revokeInviteLink')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new InviteLinkController($handler);

            self::assertEnvelope(
                $controller->deleteInviteLink(
                    self::request('DELETE', '/api/v1/me/invite-links/l1', 'owner-1'),
                    ['id' => 'l1'],
                ),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    public function testRedeemGuardArmsSpeakHuman(): void
    {
        $controller = new InviteLinkController($this->createMock(InviteLinkHandler::class));

        self::assertEnvelope(
            $controller->redeem(self::request('POST', '/api/v1/me/invite-links/t/redeem', null), ['token' => 't']),
            401,
            ['code' => 'auth.required', 'error' => 'Sign in to accept this invite'],
        );
        self::assertEnvelope(
            $controller->redeem(self::request('POST', '/api/v1/me/invite-links//redeem', 'user-1'), []),
            400,
            ['code' => 'missing_token', 'error' => 'This invite link is missing its token'],
        );
    }

    public function testRedeemHandlerArmsSpeakHuman(): void
    {
        $cases = [
            ['SERVER_SHARE_CAP_REACHED', 409, 'quota.exceeded', 'This server has reached its collaborator limit'],
            ['That invite is not valid', 400, 'invalid_invite', 'That invite is not valid'],
            ['gone link', 404, 'invite_link_not_found', 'That invite link no longer exists'],
            [
                'This invite has expired or reached its use limit',
                410,
                'invite_expired_or_exhausted',
                'This invite has expired or reached its use limit',
            ],
            ['already shared', 409, 'share_exists', 'This library is already shared with you'],
            ['unrouted boom', 999, 'unknown_error', self::GENERIC_PROBLEM],
        ];

        foreach ($cases as [$throw, $throwCode, $code, $error]) {
            $handler = $this->createMock(InviteLinkHandler::class);
            $handler->method('redeemInviteLink')
                ->willThrowException(new InvalidArgumentException($throw, $throwCode));
            $controller = new InviteLinkController($handler);

            self::assertEnvelope(
                $controller->redeem(
                    self::request('POST', '/api/v1/me/invite-links/tok/redeem', 'user-1'),
                    ['token' => 'tok'],
                ),
                $throwCode === 999 ? 500 : $throwCode,
                ['code' => $code, 'error' => $error],
            );
        }
    }

    public function testRedeemPassthroughNormalizesEmptyExceptionMessage(): void
    {
        $handler = $this->createMock(InviteLinkHandler::class);
        $handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('', 410));
        $controller = new InviteLinkController($handler);

        self::assertEnvelope(
            $controller->redeem(
                self::request('POST', '/api/v1/me/invite-links/tok/redeem', 'user-1'),
                ['token' => 'tok'],
            ),
            410,
            ['code' => 'invite_expired_or_exhausted', 'error' => self::GENERIC_PROBLEM],
        );
    }
}
