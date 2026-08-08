<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\SyncPlay;

use Phlix\Hub\SyncPlay\PendingCommandPusherInterface;

use function count;

/**
 * A {@see PendingCommandPusherInterface} that records every push and reports a
 * delivered count the test chooses (S93).
 *
 * ## Why it records the ARGUMENTS and not just the call count
 *
 * The four arguments are an identity: `userId` is whose apps get the frame and
 * `serverId` is which of that user's servers it is scoped to. A push that carried
 * the wrong one of either would still return a plausible count, still make the
 * skill speak a confirmation, and still pass any assertion that only counted
 * calls — while landing one person's spoken intent on somebody else's socket.
 * So the arguments are captured and asserted by value.
 *
 * ## Why the count is settable rather than fixed
 *
 * The whole S93 design turns on the confirmation being gated on a REAL delivered
 * count. A stand-in that always returned the same number could only ever exercise
 * one side of that gate, and the branch it never took would be the dishonest one.
 *
 * @package Phlix\Hub\Tests\Support\SyncPlay
 */
final class RecordingPendingCommandPusher implements PendingCommandPusherInterface
{
    /**
     * Every push, in order.
     *
     * @var list<array{userId: string, serverId: string, mediaId: string, title: string}>
     */
    public array $calls = [];

    /**
     * @param int $delivered The count every {@see pushPlayMedia()} call reports.
     */
    public function __construct(private readonly int $delivered = 0)
    {
    }

    public function pushPlayMedia(string $userId, string $serverId, string $mediaId, string $title): int
    {
        $this->calls[] = [
            'userId' => $userId,
            'serverId' => $serverId,
            'mediaId' => $mediaId,
            'title' => $title,
        ];

        return $this->delivered;
    }

    /**
     * Number of pushes recorded so far.
     */
    public function callCount(): int
    {
        return count($this->calls);
    }
}
