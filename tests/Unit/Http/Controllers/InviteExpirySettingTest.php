<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W5 enforcement tests for `invite.default_expiry_seconds` at
 * {@see InviteLinkController::createInviteLink()}.
 *
 * The pre-W5 literal `?? 604800` becomes the effective setting; pins:
 * default preservation, explicit body value still wins, and 0 = the honest
 * never-expiring null path (not "0 seconds from now").
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class InviteExpirySettingTest extends TestCase
{
    use DecodedJsonAssertions;

    private InviteLinkHandler&MockObject $handler;

    /** @var array<string, mixed>|null */
    private ?array $capturedCall = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedCall = null;
        $this->handler      = $this->createMock(InviteLinkHandler::class);
        $this->handler->method('createInviteLink')
            ->willReturnCallback(function (
                string $ownerId,
                string $serverId,
                ?string $libraryId,
                string $permission,
                int $maxUses,
                ?int $expiresAt,
            ): InviteLink {
                $this->capturedCall = [
                    'owner_id'   => $ownerId,
                    'server_id'  => $serverId,
                    'library_id' => $libraryId,
                    'permission' => $permission,
                    'max_uses'   => $maxUses,
                    'expires_at' => $expiresAt,
                ];

                return new InviteLink(
                    id: 'link-1',
                    ownerUserId: $ownerId,
                    serverId: $serverId,
                    libraryId: $libraryId,
                    permission: $permission,
                    maxUses: $maxUses,
                    useCount: 0,
                    expiresAt: $expiresAt,
                    createdAt: time(),
                    url: 'https://hub.example.com/invite/token123',
                    token: 'token123',
                );
            });
    }

    /** @param array<string, mixed> $body Raw POST payload (server/library ids merged in create()). */
    private function create(array $body, ?callable $defaultExpiryResolver): \Phlix\Hub\Http\Response
    {
        $controller = new InviteLinkController($this->handler, $defaultExpiryResolver);

        $request          = new Request();
        $request->path    = '/api/v1/me/invite-links';
        $request->method  = 'POST';
        $request->userId  = 'user-1';
        $request->body    = $body + ['server_id' => 'server-1', 'library_id' => 'lib-1'];

        return $controller->createInviteLink($request);
    }

    /**
     * The expiry epoch captured from the handler call — null means the
     * never-expiring path. Fails loudly when the controller never reached
     * the handler (a wiring bug in the test itself, not a behavior claim).
     */
    private function capturedExpiresAt(): ?int
    {
        $call = $this->capturedCall;
        if (!is_array($call) || !array_key_exists('expires_at', $call)) {
            self::fail('InviteLinkController never reached the handler — no call captured');
        }

        $expiresAt = $call['expires_at'];
        if ($expiresAt === null) {
            return null;
        }
        if (!is_int($expiresAt)) {
            self::fail('captured expires_at must be int|null, got ' . get_debug_type($expiresAt));
        }

        return $expiresAt;
    }

    public function testNoResolverKeepsThePreW5WeekDefault(): void
    {
        $before = time();
        $this->create([], null);

        self::assertIsInt($this->capturedExpiresAt());
        self::assertGreaterThanOrEqual($before + 604800, $this->capturedExpiresAt());
        self::assertLessThanOrEqual(time() + 604800, $this->capturedExpiresAt());
    }

    public function testResolverValueIsHonoredLive(): void
    {
        $before = time();
        $this->create([], static fn (): int => 3600);

        self::assertGreaterThanOrEqual($before + 3600, $this->capturedExpiresAt());
        self::assertLessThanOrEqual(time() + 3600, $this->capturedExpiresAt());
    }

    public function testZeroMeansNeverExpiringNull(): void
    {
        $this->create([], static fn (): int => 0);

        self::assertNull($this->capturedExpiresAt(), '0 = explicit never-expire, not epoch');
    }

    public function testNegativeResolverAnswerAlsoMeansNull(): void
    {
        // The >0 guard the pre-W5 code already had still governs.
        $this->create([], static fn (): int => -10);

        self::assertNull($this->capturedExpiresAt());
    }

    public function testExplicitBodyValueStillBeatsTheSetting(): void
    {
        $before = time();
        $this->create(['expires_in' => 120], static fn (): int => 86400);

        self::assertGreaterThanOrEqual($before + 120, $this->capturedExpiresAt());
        self::assertLessThanOrEqual(time() + 120, $this->capturedExpiresAt());
    }

    public function testExplicitBodyZeroKeepsNeverExpiringSemantics(): void
    {
        $this->create(['expires_in' => 0], static fn (): int => 86400);

        self::assertNull($this->capturedExpiresAt());
    }

    public function testCreateResponseCarriesTokenAndExpiresAt(): void
    {
        $response = $this->create([], static fn (): int => 60);

        self::assertSame(201, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('token123', self::stringNode($body['token'] ?? null));
    }
}
