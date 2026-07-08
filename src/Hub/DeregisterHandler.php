<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Jwt\JwtHeader;
use Workerman\MySQL\Connection;

/**
 * Handles voluntary server deregistration.
 *
 * @package Phlix\Hub\Hub
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
        $tokenKid = JwtHeader::kid($enrollmentJwt);
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
}
