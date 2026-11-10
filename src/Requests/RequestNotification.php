<?php

/**
 * Phlix hub component: Requests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Requests;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;

/**
 * Notification side-channel for request lifecycle events.
 *
 * DELIVERY TRUTH (review hub Major 43): the ONLY sink today is the `HUB`
 * log. No user ever receives the `message` text — no email, push or
 * WebSocket fan-out exists, and nothing outside the operator's log reader
 * consumes these entries. Every event name therefore says "logged, not
 * delivered" and each record carries a `delivery` field spelling out that
 * no user has been told; the `message` is the sentence a future real
 * channel WOULD send. Layering email / push / WebSocket fan-out on top of
 * this class is future work — until then, do not treat a line here as
 * proof a member was notified.
 *
 * @package Phlix\Hub\Requests
 */
class RequestNotification
{
    /**
     * Honest delivery status stamped on every record (Major 43).
     */
    private const string NO_CHANNEL_NOTE =
        'log-only — the hub has no email/push/WebSocket fan-out yet; no user was notified. '
        . 'The message field is the text a future delivery channel WOULD send.';

    private StructuredLogger $logger;

    /**
     * @param StructuredLogger|null $logger Optional logger; defaults to the HUB channel.
     */
    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::HUB);
    }

    /**
     * Log that a user's requested media became available in their library.
     *
     * @param string $userId User UUID the request belongs to.
     * @param string $title  Title of the now-available media.
     */
    public function notifyAvailable(string $userId, string $title): void
    {
        $this->logger->info('Request available — logged, not delivered', [
            'user_id'  => $userId,
            'title'    => $title,
            'delivery' => self::NO_CHANNEL_NOTE,
            'message'  => "Your request \u{2018}{$title}\u{2019} is now available in your library.",
        ]);
    }

    /**
     * Log that a user's request was rejected.
     *
     * @param string $userId User UUID the request belongs to.
     * @param string $title  Title of the rejected media.
     * @param string $reason Optional rejection reason (admin-supplied free text from the wire).
     */
    public function notifyRejected(string $userId, string $title, string $reason): void
    {
        // Major 44: reasons are normal prose and routinely end with a
        // period; appending one here produced "…rejected: …region..".
        // Trim the trailing sentence terminator (and stray whitespace)
        // before adding our own, so the sentence ends exactly once.
        $trimmedReason = rtrim(trim($reason), '.');
        $reasonText = $trimmedReason !== '' ? ": {$trimmedReason}" : '';
        $this->logger->info('Request rejected — logged, not delivered', [
            'user_id'  => $userId,
            'title'    => $title,
            'reason'   => $reason,
            'delivery' => self::NO_CHANNEL_NOTE,
            'message'  => "Your request \u{2018}{$title}\u{2019} was rejected{$reasonText}.",
        ]);
    }

    /**
     * Log that a user's request was approved and entered the
     * download/transcode pipeline.
     *
     * @param string $userId User UUID the request belongs to.
     * @param string $title  Title of the approved media.
     */
    public function notifyApproved(string $userId, string $title): void
    {
        $this->logger->info('Request approved — logged, not delivered', [
            'user_id'  => $userId,
            'title'    => $title,
            'delivery' => self::NO_CHANNEL_NOTE,
            'message'  => "Your request \u{2018}{$title}\u{2019} has been approved and is being processed.",
        ]);
    }

    /**
     * Log that a request was submitted and is pending review.
     *
     * @param string $userId User UUID that submitted the request.
     * @param string $title  Title of the requested media.
     */
    public function notifySubmitted(string $userId, string $title): void
    {
        $this->logger->info('Request submitted — logged, not delivered', [
            'user_id'  => $userId,
            'title'    => $title,
            'delivery' => self::NO_CHANNEL_NOTE,
            'message'  => "Your request for \u{2018}{$title}\u{2019} has been submitted and is pending review.",
        ]);
    }
}
