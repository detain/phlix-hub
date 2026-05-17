<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

use InvalidArgumentException;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Handles voluntary server deregistration.
 *
 * @package Phlex\Hub\Hub
 * @since 0.3.0
 */
class DeregisterHandler
{
    /**
     * @param Connection           $db         MySQL connection.
     * @param EnrollmentJwtService $jwtService JWT validation service.
     * @param StructuredLogger     $logger     Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly EnrollmentJwtService $jwtService,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Deregister a server (voluntary disconnect).
     *
     * @param string $serverId       Server UUID.
     * @param string $enrollmentJwt The server's enrollment JWT.
     *
     * @throws InvalidArgumentException When JWT is invalid or server not found.
     */
    public function handle(string $serverId, string $enrollmentJwt): void
    {
        $tokenKid = $this->extractKidFromToken($enrollmentJwt);
        $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $tokenKid ?? '');
        if ($payload === null) {
            throw new InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED');
        }

        if (($payload['server_id'] ?? '') !== $serverId) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'DELETE FROM servers WHERE id = :id RETURNING id',
            ['id' => $serverId],
        );

        if (empty($rows)) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        $this->logger->info('Server deregistered', [
            'server_id' => $serverId,
        ]);
    }

    /**
     * Extract the `kid` from a JWT header without validating the token.
     *
     * @param string $token The JWT to extract from.
     *
     * @return string|null Key ID or null when header is malformed.
     */
    private function extractKidFromToken(string $token): ?string
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
            /** @var string|null */
            $kid = $header['kid'] ?? null;
            return is_string($kid) ? $kid : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
