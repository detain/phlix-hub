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
 * Handles enrollment-JWT renewal for a paired server.
 *
 * A server presents its CURRENT (still-valid) enrollment JWT and receives a
 * freshly minted JWT with a full TTL, allowing it to stay enrolled past the
 * original 7-day expiry without re-claiming.
 *
 * @package Phlix\Hub\Hub
 */
class RenewHandler
{
    /**
     * @param Connection           $db         MySQL connection.
     * @param EnrollmentJwtService $jwtService JWT validation/minting service.
     * @param StructuredLogger     $logger     Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly EnrollmentJwtService $jwtService,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Validate the presented enrollment JWT and mint a fresh one.
     *
     * @param string $serverId       Server UUID from the path.
     * @param string $enrollmentJwt The server's current enrollment JWT.
     *
     * @return string The freshly minted enrollment JWT.
     *
     * @throws InvalidArgumentException When JWT is invalid/expired (401) or server not found (404).
     */
    public function handle(string $serverId, string $enrollmentJwt): string
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
            'SELECT id FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (empty($rows)) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        $newToken = $this->jwtService->createEnrollmentJwt($serverId);

        $this->logger->info('Enrollment JWT renewed', [
            'server_id' => $serverId,
        ]);

        return $newToken;
    }
}
