<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;

/**
 * Handles relay server WebSocket connections and frame multiplexing.
 *
 * On the hub, each server connects via WSS and sends HTTP request frames.
 * This handler:
 *   - Validates the server's enrollment JWT on connect
 *   - Registers the relay session in the DB
 *   - Forwards inbound HTTP requests to the server over its WSS connection
 *   - Sends HTTP responses from the server back to the client
 *   - Handles ping/pong keep-alive
 *
 * @package Phlix\Hub\Hub
 * @since 0.12.0
 */
final class RelayServerHandler
{
    /** @var RelaySessionManager */
    private RelaySessionManager $sessionManager;

    /** @var EnrollmentJwtService */
    private EnrollmentJwtService $jwtService;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var string Worker node identifier. */
    private string $workerNode;

    /**
     * @param RelaySessionManager $sessionManager Session manager.
     * @param EnrollmentJwtService $jwtService   JWT validation service.
     * @param StructuredLogger     $logger       Application logger.
     * @param string                $workerNode   Workerman worker ID string.
     */
    public function __construct(
        RelaySessionManager $sessionManager,
        EnrollmentJwtService $jwtService,
        StructuredLogger $logger,
        string $workerNode = 'unknown',
    ) {
        $this->sessionManager = $sessionManager;
        $this->jwtService = $jwtService;
        $this->logger = $logger;
        $this->workerNode = $workerNode;
    }

    /**
     * Handle a new WebSocket connection from a server.
     *
     * @param string $serverId      Server UUID from the URL path.
     * @param string $enrollmentJwt JWT from the connection headers.
     *
     * @return string The relay session ID.
     *
     * @throws InvalidArgumentException When JWT is invalid (401) or server not found (404).
     *
     * @since 0.12.0
     */
    public function onConnect(string $serverId, string $enrollmentJwt): string
    {
        $kid = $this->extractKid($enrollmentJwt);
        if ($kid === null) {
            throw new InvalidArgumentException('INVALID_TOKEN');
        }

        $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
        if ($payload === null) {
            throw new InvalidArgumentException('INVALID_TOKEN');
        }

        if (($payload['server_id'] ?? '') !== $serverId) {
            throw new InvalidArgumentException('SERVER_MISMATCH');
        }

        $sessionId = $this->sessionManager->registerServer($serverId, $this->workerNode);

        $this->logger->info('Relay server connected', [
            'server_id' => $serverId,
            'session_id' => $sessionId,
        ]);

        return $sessionId;
    }

    /**
     * Handle an incoming frame from a server.
     *
     * @param string                 $sessionId Relay session UUID.
     * @param array<string, mixed>    $frame    Decoded frame (seq, type, payload).
     *
     * @return array<string, mixed>|null Response to send back, or null for ping/pong.
     *
     * @since 0.12.0
     */
    public function onFrame(string $sessionId, array $frame): ?array
    {
        $typeValue = $frame['type'];
        $type = is_int($typeValue) ? $typeValue : (is_numeric($typeValue) ? (int) $typeValue : 0);

        if ($type === 3) {
            return ['type' => 4, 'seq' => $frame['seq'] ?? 0, 'payload' => []];
        }

        if ($type === 4) {
            return null;
        }

        $this->logger->debug('Relay frame received', [
            'session_id' => $sessionId,
            'type' => $type,
            'seq' => $frame['seq'] ?? 0,
        ]);

        return null;
    }

    /**
     * Handle a server disconnection.
     *
     * @param string $sessionId Relay session UUID.
     * @param string $reason    Disconnect reason.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function onClose(string $sessionId, string $reason = 'server_disconnect'): void
    {
        $this->sessionManager->closeSession($sessionId, $reason);

        $this->logger->info('Relay server disconnected', [
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Extract the `kid` from a JWT header.
     *
     * @param string $token JWT string.
     *
     * @return string|null Key ID or null.
     */
    private function extractKid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($decoded === false) {
                return null;
            }
            /** @var array<string, mixed> $header */
            $header = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
            /** @var string|null $kid */
            $kid = $header['kid'] ?? null;
            return is_string($kid) ? $kid : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
