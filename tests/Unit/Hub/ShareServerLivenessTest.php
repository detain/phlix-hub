<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\ShareServerLiveness;
use Phlix\Hub\ServerReaper;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ShareServerLiveness} — the truth table behind the
 * "Shared With Me" hub-vantage liveness label [review hub__casualgamer M3].
 *
 * Every case pins one row of the derived state from fake heartbeat/relay
 * signals: fresh-online / stale-online / reaped-offline / never-seen /
 * mid-claim / disabled / relay-open / foreign-status. The law under test is
 * honesty, not reachability: the mapper may only claim REACHABLE on a signal
 * that actually says so, and must degrade to UNKNOWN — never false-live —
 * when the signals are absent or unrecognized.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ShareServerLivenessTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const THRESHOLD = ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS;

    public function testFreshOnlineHeartbeatIsReachable(): void
    {
        self::assertSame(
            ShareServerLiveness::REACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW - 10, false, self::NOW),
        );
    }

    public function testOnlineExactlyAtThresholdIsStillReachable(): void
    {
        // The reaper marks offline at "silent >= threshold"; the boundary itself
        // must agree with that law (<=), so a row cannot be "online" here and
        // "fresh" nowhere else.
        self::assertSame(
            ShareServerLiveness::REACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW - self::THRESHOLD, false, self::NOW),
        );
    }

    public function testOnlineOneSecondPastThresholdIsUnreachable(): void
    {
        $onePast = self::NOW - self::THRESHOLD - 1;
        self::assertSame(
            ShareServerLiveness::UNREACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, $onePast, false, self::NOW),
        );
    }

    public function testStaleOnlineRowDoesNotReadAsFalseLive(): void
    {
        // Heartbeat died but the reaper has not run yet: honesty says dead-ish.
        self::assertSame(
            ShareServerLiveness::UNREACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW - 3600, false, self::NOW),
        );
    }

    public function testReapedOfflineServerIsUnreachable(): void
    {
        self::assertSame(
            ShareServerLiveness::UNREACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_OFFLINE, self::NOW - 7200, false, self::NOW),
        );
    }

    public function testNeverHeartbeatedServerIsUnknown(): void
    {
        self::assertSame(
            ShareServerLiveness::UNKNOWN,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, null, false, self::NOW),
        );
    }

    public function testClaimingServerIsUnknown(): void
    {
        self::assertSame(
            ShareServerLiveness::UNKNOWN,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_CLAIMING, self::NOW, false, self::NOW),
        );
    }

    public function testOpenRelayTunnelIsReachableEvenPastHeartbeatFreshness(): void
    {
        // The strongest signal the hub holds: an open relay session means the
        // server dialled in NOW, whatever the last heartbeat row claims.
        self::assertSame(
            ShareServerLiveness::REACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_OFFLINE, self::NOW - 86_400, true, self::NOW),
        );
    }

    public function testDisabledServerIsUnreachableEvenWithOpenRelay(): void
    {
        // Admin kill switch outranks every liveness signal.
        self::assertSame(
            ShareServerLiveness::UNREACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_DISABLED, self::NOW, true, self::NOW),
        );
    }

    public function testForeignStatusVocabularyIsUnknownNeverFalseLive(): void
    {
        self::assertSame(
            ShareServerLiveness::UNKNOWN,
            ShareServerLiveness::derive('decommissioned-pending', self::NOW, false, self::NOW),
        );
    }

    public function testEmptyStatusIsUnknown(): void
    {
        // The handler degrades a non-string status column to '' — the mapper
        // must treat that as "no honest answer", not as offline.
        self::assertSame(
            ShareServerLiveness::UNKNOWN,
            ShareServerLiveness::derive('', self::NOW - 5, false, self::NOW),
        );
    }

    public function testThresholdIsInjectableForCustomWindows(): void
    {
        self::assertSame(
            ShareServerLiveness::REACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW - 300, false, self::NOW, 600),
        );
        self::assertSame(
            ShareServerLiveness::UNREACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW - 300, false, self::NOW, 120),
        );
    }

    public function testDefaultThresholdIsTheReapersOwnLaw(): void
    {
        // Drift tripwire: the mapper must reuse the reaper constant, not a copy.
        self::assertSame(180, ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS);

        $method = new \ReflectionMethod(ShareServerLiveness::class, 'derive');
        $parameter = $method->getParameters()[4];
        self::assertSame(
            ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS,
            $parameter->getDefaultValue(),
        );
    }

    public function testFutureTimestampWithinClockSkewIsReachable(): void
    {
        // last_seen from NOW() and the reference from time() can straddle one
        // second of skew; a (tiny) negative age must not read as dead.
        self::assertSame(
            ShareServerLiveness::REACHABLE,
            ShareServerLiveness::derive(ServerInfoDto::STATUS_ONLINE, self::NOW + 2, false, self::NOW),
        );
    }
}
