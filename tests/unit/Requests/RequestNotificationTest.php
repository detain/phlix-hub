<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Requests;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Requests\RequestNotification;

/**
 * Unit tests for {@see RequestNotification}.
 *
 * @package Phlix\Hub\Tests\unit\Requests
 * @since 0.6.0
 *
 * @covers \Phlix\Hub\Requests\RequestNotification
 */
final class RequestNotificationTest extends TestCase
{
    public function testNotifySubmittedLogsMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Request submitted notification',
                self::callback(static fn (array $ctx) => $ctx['user_id'] === 'u1' && $ctx['title'] === 'My Movie'),
            );

        (new RequestNotification($logger))->notifySubmitted('u1', 'My Movie');
    }

    public function testNotifyApprovedLogsMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Request approved notification', self::isType('array'));

        (new RequestNotification($logger))->notifyApproved('u1', 'My Movie');
    }

    public function testNotifyRejectedAppendsReasonToMessage(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Request rejected notification',
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
            ->with('Request available notification', self::isType('array'));

        (new RequestNotification($logger))->notifyAvailable('u1', 'My Movie');
    }
}
