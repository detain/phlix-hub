<?php

declare(strict_types=1);

namespace Phlex\Hub\Requests;

use Phlex\Hub\Common\Logger\LogChannels;
use Phlex\Hub\Common\Logger\LoggerFactory;
use Phlex\Hub\Common\Logger\StructuredLogger;

/**
 * Notification side-channel for request lifecycle events.
 *
 * The hub deliberately keeps this thin: every transition (submitted,
 * approved, rejected, available) is logged through the `HUB` channel.
 * Future work can layer email / push / WebSocket fan-out on top of
 * this class without changing controllers.
 *
 * @package Phlex\Hub\Requests
 * @since 0.6.0
 */
class RequestNotification
{
    private StructuredLogger $logger;

    /**
     * @param StructuredLogger|null $logger Optional logger; defaults to the HUB channel.
     */
    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::HUB);
    }

    /**
     * Notify a user that their requested media is now available in their library.
     *
     * @param string $userId User UUID to notify.
     * @param string $title  Title of the now-available media.
     */
    public function notifyAvailable(string $userId, string $title): void
    {
        $this->logger->info('Request available notification', [
            'user_id' => $userId,
            'title'   => $title,
            'message' => "Your request '{$title}' is now available in your library.",
        ]);
    }

    /**
     * Notify a user that their request was rejected.
     *
     * @param string $userId User UUID to notify.
     * @param string $title  Title of the rejected media.
     * @param string $reason Optional rejection reason.
     */
    public function notifyRejected(string $userId, string $title, string $reason): void
    {
        $reasonText = $reason !== '' ? ": {$reason}" : '';
        $this->logger->info('Request rejected notification', [
            'user_id' => $userId,
            'title'   => $title,
            'reason'  => $reason,
            'message' => "Your request '{$title}' was rejected{$reasonText}.",
        ]);
    }

    /**
     * Notify a user that their request was approved and is now in the
     * download/transcode pipeline.
     *
     * @param string $userId User UUID to notify.
     * @param string $title  Title of the approved media.
     */
    public function notifyApproved(string $userId, string $title): void
    {
        $this->logger->info('Request approved notification', [
            'user_id' => $userId,
            'title'   => $title,
            'message' => "Your request '{$title}' has been approved and is being processed.",
        ]);
    }

    /**
     * Notify a user that their request has been submitted for review.
     *
     * @param string $userId User UUID to notify.
     * @param string $title  Title of the requested media.
     */
    public function notifySubmitted(string $userId, string $title): void
    {
        $this->logger->info('Request submitted notification', [
            'user_id' => $userId,
            'title'   => $title,
            'message' => "Your request for '{$title}' has been submitted and is pending review.",
        ]);
    }
}
