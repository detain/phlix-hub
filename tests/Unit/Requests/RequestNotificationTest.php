<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Requests\RequestNotification;

/**
 * Unit tests for {@see RequestNotification}.
 *
 * @package Phlix\Hub\Tests\Unit\Requests
 */
final class RequestNotificationTest extends TestCase
{
    public function testNotifySubmittedLogsMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Request submitted — logged, not delivered',
                self::callback(static fn (array $ctx) => $ctx['user_id'] === 'u1' && $ctx['title'] === 'My Movie'),
            );

        (new RequestNotification($logger))->notifySubmitted('u1', 'My Movie');
    }

    public function testNotifyApprovedLogsMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Request approved — logged, not delivered', self::isArray());

        (new RequestNotification($logger))->notifyApproved('u1', 'My Movie');
    }

    public function testNotifyRejectedAppendsReasonToMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Request rejected — logged, not delivered',
                self::callback(static function (array $ctx) {
                    return $ctx['reason'] === 'not appropriate'
                        && str_contains((string) $ctx['message'], 'not appropriate');
                }),
            );

        (new RequestNotification($logger))->notifyRejected('u1', 'My Movie', 'not appropriate');
    }

    public function testNotifyAvailableLogsMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Request available — logged, not delivered', self::isArray());

        (new RequestNotification($logger))->notifyAvailable('u1', 'My Movie');
    }

    /**
     * Major 44: an admin reason written as normal prose ("Not available here.")
     * must not double the sentence terminator in the composed message.
     */
    public function testRejectedReasonEndingInPeriodDoesNotDoubleUp(): void
    {
        $captured = null;
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Request rejected — logged, not delivered',
                self::callback(static function (array $ctx) use (&$captured): bool {
                    $captured = (string) $ctx['message'];

                    return true;
                }),
            );

        (new RequestNotification($logger))->notifyRejected('u1', 'My Movie', 'Not available in your region.');

        self::assertSame(
            "Your request \u{2018}My Movie\u{2019} was rejected: Not available in your region.",
            $captured,
        );
    }

    /**
     * Major 43: every lifecycle entry must carry the honest no-channel
     * delivery status so a log line is never mistaken for user delivery.
     */
    public function testEveryEntryCarriesTheDeliveryStatusField(): void
    {
        $records = [];
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::exactly(4))
            ->method('info')
            ->with(
                self::matchesRegularExpression('/logged, not delivered$/'),
                self::callback(static function (array $ctx) use (&$records): bool {
                    $records[] = $ctx;

                    $note = (string) ($ctx['delivery'] ?? '');

                    return is_string($ctx['delivery'] ?? null) && str_contains($note, 'no user was notified');
                }),
            );

        $notifier = new RequestNotification($logger);
        $notifier->notifySubmitted('u1', 'T');
        $notifier->notifyApproved('u1', 'T');
        $notifier->notifyRejected('u1', 'T', 'r');
        $notifier->notifyAvailable('u1', 'T');

        self::assertCount(4, $records);
    }
}
