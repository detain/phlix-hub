<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

/**
 * M-HUB-04 wire law: the hub's two HTTP 409 claim refusals must each
 * carry THEIR OWN honest sentence on the wire, mutually distinguishable.
 *
 * The review ("claiming a server at your quota tells you the code was
 * 'already claimed'") hypothesised a server-side message clobber: a
 * code-keyed default overriding the per-call text inside
 * {@see Response::error()}. Re-measured at HEAD that premise is false —
 * `error()` merges `$extra` over nothing (left-hand `error`/`code` keys
 * win, the human sentence rides `message` untouched), and `mapError()`
 * emits the quota arm as the registered `quota.exceeded` code with
 * 'Server quota reached for this account' verbatim. The residual
 * mislabel is CLIENT-side: the pinned @phlix/ui `claimServer.ts`
 * resolves a failure `kind` from a code table that lacks `quota.exceeded`,
 * so a 409 falls through to its status-keyed 'already_claimed' copy.
 * That cure belongs to the ui repo + tag cascade (registry codes are
 * frozen by the contracts SSOT); THIS suite owns the hub half of the
 * law: the wire the client reads must stay honest and distinguishable,
 * and any future refactor that reintroduces a default-message clobber
 * reddens here first.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerClaimQuotaMessageTest extends TestCase
{
    use DecodedJsonAssertions;

    /**
     * Registered quota frame (contracts: quota.exceeded). M-HUB-35
     * completed the honest frame: the human sentence rides BOTH text
     * fields (`error` used to park the SCREAMING literal here), followed
     * by the quota discriminator tail — which cap answered, and that a
     * cap clears on release, never on a timer. Key order is load-bearing
     * (assertSame on the decoded array).
     */
    private const QUOTA_FRAME = [
        'error' => 'Server quota reached for this account',
        'code' => 'quota.exceeded',
        'message' => 'Server quota reached for this account',
        'quota_kind' => 'server_count',
        'quota_reset' => 'on_release',
    ];

    /**
     * Registered already-claimed frame (contracts:
     * claim.code_already_claimed) — the neighbour 409 that must NEVER
     * lend its copy to the quota case.
     */
    private const ALREADY_CLAIMED_FRAME = [
        'error' => 'CLAIM_CODE_ALREADY_CLAIMED',
        'code' => 'claim.code_already_claimed',
        'message' => 'Claim code has already been used',
    ];

    private function claimResponseThrowing(string $reason): Response
    {
        $handler = $this->createMock(ClaimRequestHandler::class);
        $handler->method('handleClaimCode')
            ->willThrowException(new InvalidArgumentException($reason));

        $request         = new Request();
        $request->method = 'POST';
        $request->path   = '/api/v1/server-claims/claim';
        $request->userId = 'user-1';
        $request->body   = ['claim_code' => 'DR4Q-7AXB'];

        return (new ServerClaimController($handler))->claim($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function frameOf(Response $response): array
    {
        return self::arrayNode(
            json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testQuotaRefusalRendersItsHonestFrameByteExactly(): void
    {
        $response = $this->claimResponseThrowing('SERVER_CAP_REACHED');

        self::assertSame(409, $response->statusCode);
        self::assertSame(
            self::QUOTA_FRAME,
            $this->frameOf($response),
            'quota refusal frame drifted from the registered wire truth'
        );
    }

    public function testAlreadyClaimedRefusalKeepsItsOwnFrameByteExactly(): void
    {
        $response = $this->claimResponseThrowing('CLAIM_CODE_ALREADY_CLAIMED');

        self::assertSame(409, $response->statusCode);
        self::assertSame(
            self::ALREADY_CLAIMED_FRAME,
            $this->frameOf($response),
            'already-claimed refusal frame drifted from the registered wire truth'
        );
    }

    public function testTheTwoFortyNineArmsStayDistinguishableOnEveryChannel(): void
    {
        $quota   = $this->frameOf($this->claimResponseThrowing('SERVER_CAP_REACHED'));
        $claimed = $this->frameOf($this->claimResponseThrowing('CLAIM_CODE_ALREADY_CLAIMED'));

        self::assertNotSame(self::stringNode($quota['message']), self::stringNode($claimed['message']));
        self::assertNotSame(self::stringNode($quota['code']), self::stringNode($claimed['code']));
        self::assertNotSame(self::stringNode($quota['error']), self::stringNode($claimed['error']));
    }

    public function testResponseErrorMergePrecedenceCannotClobberCodeOrError(): void
    {
        // Left-hand `error`/`code` win the `+` merge: extras may ADD the
        // human `message` key but can never overwrite the registered code
        // or the legacy literal. Pinned so a future "default table" inside
        // error() is a test-red, not a user-visible mislabel.
        $response = (new Response())->error(400, 'some.code', 'CANONICAL_TEXT', [
            'error'   => 'MUST_NOT_WIN',
            'code'    => 'MUST_NOT_WIN',
            'message' => 'per-call sentence',
        ]);

        self::assertSame(
            ['error' => 'CANONICAL_TEXT', 'code' => 'some.code', 'message' => 'per-call sentence'],
            $this->frameOf($response)
        );
    }
}
