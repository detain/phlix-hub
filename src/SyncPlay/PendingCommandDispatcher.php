<?php

/**
 * Phlix hub component: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\SyncPlay;

use Channel\Client as ChannelClient;
use JsonException;
use Phlix\Hub\Common\Logger\StructuredLogger;

use function is_array;
use function is_string;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * `:8804`-worker side of the cross-process pending-command push (S93).
 *
 * Lives in the single SyncPlay relay worker process, which is the only process
 * that holds the live client sockets ({@see SyncPlayRelayWorker::$clients} is a
 * per-process static). Wired as the {@see PendingCommandProtocol::PUSH_EVENT}
 * subscriber in {@see SyncPlayRelayWorker::onWorkerStart()}; the mirror image of
 * {@see \Phlix\Hub\Relay\RelayProxyManager} on the relay side of the HTTP proxy.
 *
 * ## A malformed push produces NO reply at all
 *
 * {@see onPush()} validates every field and, when one is missing or empty, logs
 * and returns WITHOUT publishing a reply. That is deliberate: a reply is a
 * statement about delivery, and the only reply this dispatcher could honestly
 * make for a push it never understood is a fake `delivered` count. Staying silent
 * lets the publisher's own timeout do the honest thing — degrade to 0 — instead of
 * receiving a number that was never measured.
 *
 * @package Phlix\Hub\SyncPlay
 * @since   S93 (Alexa: play in an already-open app)
 */
final class PendingCommandDispatcher
{
    /**
     * @var callable(string, array<string, mixed>): void
     */
    private $publisher;

    /**
     * @param StructuredLogger                                    $logger    Relay logger.
     * @param (callable(string, array<string, mixed>): void)|null $publisher Channel publisher
     *        (defaults to {@see ChannelClient::publish()}; overridable for tests).
     */
    public function __construct(
        private readonly StructuredLogger $logger,
        ?callable $publisher = null,
    ) {
        $this->publisher = $publisher ?? static function (string $event, array $data): void {
            ChannelClient::publish($event, $data);
        };
    }

    /**
     * Handle one pending-command push published by an HTTP worker.
     *
     * Writes the frame to every socket {@see SyncPlayRelayWorker::deliverToUser()}
     * matches, then publishes the REAL number written back on the push's
     * `reply_event`. The count is measured, never assumed — it is the only thing
     * standing between the Alexa skill and a confirmation for a command nobody
     * received.
     *
     * @param mixed $data The published push payload.
     */
    public function onPush(mixed $data): void
    {
        if (!is_array($data)) {
            $this->logger->warning('SyncPlay pending command: push payload was not an array');

            return;
        }

        /** @var array<string, mixed> $data */
        $requestId = self::field($data, 'request_id');
        $replyEvent = self::field($data, 'reply_event');
        $userId = self::field($data, 'user_id');
        $serverId = self::field($data, 'server_id');
        $mediaId = self::field($data, 'media_id');
        $title = self::field($data, 'title');

        if (
            $requestId === null
            || $replyEvent === null
            || $userId === null
            || $serverId === null
            || $mediaId === null
            || $title === null
        ) {
            // No reply: see the class docblock. A malformed push must not
            // produce a delivered count nobody measured.
            $this->logger->warning('SyncPlay pending command: malformed push, no reply sent', [
                'has_request_id' => $requestId !== null,
                'has_reply_event' => $replyEvent !== null,
                'has_user_id' => $userId !== null,
                'has_server_id' => $serverId !== null,
                'has_media_id' => $mediaId !== null,
                'has_title' => $title !== null,
            ]);

            return;
        }

        try {
            $frameJson = json_encode([
                'type' => PendingCommandProtocol::FRAME_TYPE,
                'command' => PendingCommandProtocol::COMMAND_PLAY_MEDIA,
                'server_id' => $serverId,
                'media_id' => $mediaId,
                'title' => $title,
                'issued_at' => time(),
                'source' => 'alexa',
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('SyncPlay pending command: frame could not be encoded', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $delivered = SyncPlayRelayWorker::deliverToUser($userId, $serverId, $frameJson);

        ($this->publisher)($replyEvent, [
            'request_id' => $requestId,
            'delivered' => $delivered,
        ]);

        $this->logger->info('SyncPlay pending command: dispatched', [
            'user_id' => $userId,
            'server_id' => $serverId,
            'media_id' => $mediaId,
            'delivered' => $delivered,
        ]);
    }

    /**
     * A non-empty string field, or null.
     *
     * @param array<string, mixed> $source
     */
    private static function field(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
