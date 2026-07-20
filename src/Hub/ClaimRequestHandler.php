<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Common\Support\Ids;
use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Shared\Hub\ClaimRequest;
use Phlix\Shared\Hub\ClaimResponse;
use Workerman\MySQL\Connection;

/**
 * On `handleClaimCode`:
 *   1. Looks up claim code in server_claims (with lock).
 *   2. Validates not expired, not already claimed.
 *   3. Atomically updates claimed_by, claimed_at and deletes the claim row.
 *   4. Inserts servers row with status 'online'.
 *   5. Returns enrollment JWT via EnrollmentJwtService.
 *
 * @package Phlix\Hub\Hub
 */
class ClaimRequestHandler
{
    /**
     * @param Connection          $db         MySQL connection.
     * @param Ed25519KeyManager   $keyManager Key manager (used indirectly via EnrollmentJwtService).
     * @param StructuredLogger     $logger     Application logger.
     * @param AuditLogger          $audit     Audit logger for security events.
     * @param string               $hubBaseUrl Hub's public base URL.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Ed25519KeyManager $keyManager,
        private readonly StructuredLogger $logger,
        private readonly AuditLogger $audit,
        private readonly string $hubBaseUrl,
    ) {
    }

    /**
     * Build the enrollment-JWT minter used by the claim paths.
     *
     * Wires the {@see HubSettingsRepository} so the mint reads the EFFECTIVE
     * `server.enrollment_ttl` (admin override → `config/server.php` default)
     * rather than a hardcoded 7 days — see
     * {@see EnrollmentJwtService::effectiveTtl()}. Built per call (it is a
     * stateless wrapper) so no per-request state is retained on this
     * long-lived, resident-memory handler.
     */
    private function makeEnrollmentJwtService(): EnrollmentJwtService
    {
        return new EnrollmentJwtService(
            $this->keyManager,
            $this->hubBaseUrl,
            new HubSettingsRepository($this->db),
        );
    }

    /**
     * Process a new server claim request.
     *
     * @param ClaimRequest $request Validated claim request from the server.
     *
     * @return ClaimResponse Response containing claim code and metadata.
     */
    public function handleNewClaim(ClaimRequest $request): ClaimResponse
    {
        $this->validateClaimRequest($request);

        $existingRow = $this->findExistingPendingClaim($request->publicKeysJwk);
        if ($existingRow !== null) {
            /** @var array<string, mixed> $row */
            $row = $existingRow;
            /** @var string */
            $claimCode = is_string($row['claim_code'] ?? null) ? $row['claim_code'] : '';
            /** @var int */
            $expiresAt = is_int($row['expires_at'] ?? null) ? $row['expires_at'] : 0;
            /** @var string */
            $claimId = is_string($row['id'] ?? null) ? $row['id'] : '';
            $this->logger->info('Returning existing claim code for duplicate request', [
                'claim_code' => $claimCode,
                'server_name' => $request->serverName,
            ]);
            return new ClaimResponse(
                claimCode: $claimCode,
                expiresIn: max(0, $expiresAt - time()),
                claimId: $claimId,
                hubBaseUrl: $this->hubBaseUrl,
            );
        }

        $claimId = $this->generateUuid();
        $claimCode = $this->generateClaimCode();
        $now = time();
        /** @var int */
        $ttl = 600;
        $expiresAt = $now + $ttl;

        $this->db->query(
            'INSERT INTO server_claims
                (id, claim_code, server_name, version, public_key_jwk,
                 hostname_candidates_json, protocol_version, expires_at, created_at)
             VALUES
                (:id, :claim_code, :server_name, :version, :public_key_jwk,
                 :hostname_candidates_json, :protocol_version, :expires_at, :created_at)',
            [
                'id' => $claimId,
                'claim_code' => $claimCode,
                'server_name' => $request->serverName,
                'version' => $request->version,
                'public_key_jwk' => json_encode($request->publicKeysJwk),
                'hostname_candidates_json' => json_encode($request->hostnameCandidates),
                'protocol_version' => $request->protocolVersion,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
        );

        $this->logger->info('Created new server claim', [
            'claim_id' => $claimId,
            'claim_code' => $claimCode,
            'server_name' => $request->serverName,
        ]);

        /** @var int */
        $ttl = 600;
        return new ClaimResponse(
            claimCode: $claimCode,
            expiresIn: $ttl,
            claimId: $claimId,
            hubBaseUrl: $this->hubBaseUrl,
        );
    }

    /**
     * Claim a server using a claim code.
     *
     * @param string $claimCode The claim code (e.g. "ABCD-1234").
     * @param string $userId    The user claiming the server.
     *
     * @return array{enrollment_jwt: string, hub_jwks_url: string, server_id: string}
     *
     * @throws InvalidArgumentException When code is not found, expired, or already claimed.
     */
    public function handleClaimCode(string $claimCode, string $userId): array
    {
        // Strip separators/spaces first, THEN uppercase, so lenient input
        // like "dr4q 7axb" or "dr4q-7axb" normalises to "DR4Q7AXB".
        $normalizedCode = strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $claimCode));
        if ($normalizedCode === '') {
            $this->audit->logFailedAuth('CLAIM_CODE_INVALID', ['claim_code' => $claimCode]);
            throw new InvalidArgumentException('CLAIM_CODE_NOT_FOUND');
        }

        $now = time();

        // Wrap the SELECT … FOR UPDATE and the subsequent INSERT/UPDATE in an
        // explicit transaction. Without it MySQL autocommit releases the
        // FOR UPDATE row lock the instant the SELECT returns, so two
        // coroutines could both read the same unclaimed row before either
        // writes — defeating the ALREADY_CLAIMED guard (double-claim race).
        // PhlixMySQLConnection::beginTrans() additionally holds the
        // per-connection coroutine mutex for the whole transaction, so no
        // other coroutine can interleave a query onto the shared socket
        // between the read and the writes.
        $this->db->beginTrans();
        try {
            // claim_code is stored in its dashed display form (e.g. ABCD-1234);
            // compare against the de-dashed stored value so the normalised input
            // (separators removed) still matches.
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->db->query(
                "SELECT * FROM server_claims WHERE REPLACE(claim_code, '-', '') = :code FOR UPDATE",
                ['code' => $normalizedCode],
            );

            if (empty($rows)) {
                $this->audit->logFailedAuth('CLAIM_CODE_NOT_FOUND', ['claim_code' => $normalizedCode]);
                throw new InvalidArgumentException('CLAIM_CODE_NOT_FOUND');
            }
            /** @var array<string, mixed> $row */
            $row = $rows[0];
            /** @var int */
            $expiresAt = is_int($row['expires_at'] ?? null) ? $row['expires_at'] : 0;
            $claimedBy = $row['claimed_by'] ?? null;

            if ($expiresAt < $now) {
                $this->audit->logFailedAuth('CLAIM_CODE_EXPIRED', ['claim_code' => $normalizedCode]);
                throw new InvalidArgumentException('CLAIM_CODE_EXPIRED');
            }
            if ($claimedBy !== null) {
                $this->audit->logFailedAuth('CLAIM_CODE_ALREADY_CLAIMED', [
                    'claim_code' => $normalizedCode,
                    'claimed_by' => $claimedBy,
                ]);
                throw new InvalidArgumentException('CLAIM_CODE_ALREADY_CLAIMED');
            }

            $serverId = $this->generateUuid();
            $nowUnix = time();

            /** @var string */
            $publicKeyJwk = is_string($row['public_key_jwk'] ?? null) ? $row['public_key_jwk'] : '';
            /** @var string */
            $hostnameCandidates = is_string($row['hostname_candidates_json'] ?? null)
                ? $row['hostname_candidates_json']
                : '[]';
            /** @var string */
            $serverName = is_string($row['server_name'] ?? null) ? $row['server_name'] : '';
            /** @var string */
            $version = is_string($row['version'] ?? null) ? $row['version'] : '';
            /** @var string */
            $claimRowId = is_string($row['id'] ?? null) ? $row['id'] : '';

            $nowDateTime = date('Y-m-d H:i:s', $nowUnix);

            $this->db->query(
                'INSERT INTO servers
                    (id, user_id, server_name, version, public_key_jwk, hostname_candidates_json,
                     status, last_seen_at, created_at, enrolled_at)
                 VALUES
                    (:id, :user_id, :server_name, :version, :public_key_jwk, :hostname_candidates_json,
                     \'online\', :last_seen_at, :created_at, :enrolled_at)',
                [
                    'id' => $serverId,
                    'user_id' => $userId,
                    'server_name' => $serverName,
                    'version' => $version,
                    'public_key_jwk' => $publicKeyJwk,
                    'hostname_candidates_json' => $hostnameCandidates,
                    // servers.last_seen_at / created_at are DATETIME columns.
                    'last_seen_at' => $nowDateTime,
                    'created_at' => $nowDateTime,
                    // servers.enrolled_at is INT UNSIGNED (migration 012).
                    'enrolled_at' => $nowUnix,
                ],
            );

            // Mark the claim paired and record the new server id, instead of
            // deleting the row. The headless server has no JWT yet and the
            // enrollment material is delivered to it by polling
            // GET /api/v1/server-claims/{id}; that poll regenerates the
            // enrollment JWT from paired_server_id and then consumes the row.
            $this->db->query(
                'UPDATE server_claims
                    SET status = \'paired\', claimed_by = :user_id, claimed_at = :claimed_at,
                        paired_at = :paired_at, paired_server_id = :server_id
                  WHERE id = :id',
                [
                    'user_id' => $userId,
                    'claimed_at' => $nowUnix,
                    'paired_at' => $nowUnix,
                    'server_id' => $serverId,
                    'id' => $claimRowId,
                ],
            );

            $this->db->commitTrans();
        } catch (\Throwable $e) {
            $this->db->rollBackTrans();
            throw $e;
        }

        // JWT minting is read-only/CPU work — do it after committing so the
        // transaction (and the per-connection coroutine mutex it holds) is
        // released as soon as the row writes land.
        $jwtService = $this->makeEnrollmentJwtService();
        $enrollmentJwt = $jwtService->createEnrollmentJwt($serverId);

        $this->logger->info('Server claimed successfully', [
            'server_id' => $serverId,
            'user_id' => $userId,
            'claim_code' => $normalizedCode,
        ]);

        return [
            'enrollment_jwt' => $enrollmentJwt,
            'hub_jwks_url' => $jwtService->getHubJwksUrl(),
            'server_id' => $serverId,
        ];
    }

    /**
     * Poll the status of a claim by its id (the headless pairing flow).
     *
     * Returns `{status: pending}` while the claim is unclaimed, and once a
     * user has claimed it on the hub portal `{status: claimed}` plus a
     * freshly-minted enrollment JWT — then consumes the claim so the JWT
     * can't be fetched again. Unknown or expired claims report `expired`.
     *
     * The enrollment JWT is regenerated here from paired_server_id rather
     * than stored at rest, so no bearer credential lingers in the database.
     *
     * @param string $claimId The claim id returned by handleNewClaim().
     *
     * @return array{status: string, enrollment_jwt?: string, hub_jwks_url?: string, server_id?: string}
     */
    public function getClaimStatus(string $claimId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM server_claims WHERE id = :id',
            ['id' => $claimId],
        );
        if (empty($rows)) {
            return ['status' => 'expired'];
        }
        /** @var array<string, mixed> $row */
        $row = $rows[0];

        /** @var string|null $claimedBy */
        $claimedBy = $row['claimed_by'] ?? null;
        /** @var string $serverId */
        $serverId = is_string($row['paired_server_id'] ?? null) ? $row['paired_server_id'] : '';
        /** @var string $status */
        $status = is_string($row['status'] ?? null) ? $row['status'] : 'pending';
        /** @var int $expiresAt */
        $expiresAt = is_int($row['expires_at'] ?? null) ? $row['expires_at'] : 0;

        if ($status === 'paired' && is_string($claimedBy) && $claimedBy !== '' && $serverId !== '') {
            $jwtService = $this->makeEnrollmentJwtService();
            $enrollmentJwt = $jwtService->createEnrollmentJwt($serverId);
            // One-time retrieval: drop the claim now the server has its JWT.
            $this->db->query('DELETE FROM server_claims WHERE id = :id', ['id' => $claimId]);

            return [
                'status' => 'claimed',
                'enrollment_jwt' => $enrollmentJwt,
                'hub_jwks_url' => $jwtService->getHubJwksUrl(),
                'server_id' => $serverId,
            ];
        }

        if ($expiresAt > 0 && $expiresAt < time()) {
            return ['status' => 'expired'];
        }

        return ['status' => 'pending'];
    }

    /**
     * Generate a 4+4 uppercase alphanumeric claim code (no 0, O, I, 1).
     *
     * Format: ABCD-1234
     */
    public function generateClaimCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, 31)];
        }
        $code .= '-';
        for ($i = 0; $i < 4; $i++) {
            $code .= $chars[random_int(0, 31)];
        }
        return $code;
    }

    /**
     * Validate the JWK structure of the claim request.
     *
     * @throws InvalidArgumentException When validation fails.
     */
    private function validateClaimRequest(ClaimRequest $request): void
    {
        if ($request->protocolVersion !== 'v1') {
            throw new InvalidArgumentException('HUB_PROTOCOL_UNSUPPORTED');
        }
        if ($request->serverName === '' || strlen($request->serverName) > 255) {
            throw new InvalidArgumentException('Invalid server_name');
        }
        if ($request->version === '' || strlen($request->version) > 32) {
            throw new InvalidArgumentException('Invalid version');
        }
        $this->validateJwkStructure($request->publicKeysJwk);
    }

    /**
     * Validate Ed25519 JWK structure.
     *
     * @param array<string, mixed> $jwk JWK to validate.
     *
     * @throws InvalidArgumentException When JWK is malformed.
     */
    private function validateJwkStructure(array $jwk): void
    {
        if (($jwk['kty'] ?? '') !== 'OKP') {
            throw new InvalidArgumentException('SERVER_KEY_INVALID');
        }
        if (($jwk['crv'] ?? '') !== 'Ed25519') {
            throw new InvalidArgumentException('SERVER_KEY_INVALID');
        }
        if (empty($jwk['x']) || !is_string($jwk['x'])) {
            throw new InvalidArgumentException('SERVER_KEY_INVALID');
        }
        $decoded = base64_decode(strtr($jwk['x'], '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new InvalidArgumentException('SERVER_KEY_INVALID');
        }
    }

    /**
     * Find existing pending claim for this public key fingerprint.
     *
     * @param array<string, mixed> $publicKeyJwk
     *
     * @return array<string, mixed>|null Existing row or null.
     */
    private function findExistingPendingClaim(array $publicKeyJwk): ?array
    {
        $fingerprint = $this->jwkFingerprint($publicKeyJwk);
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM server_claims
             WHERE claimed_by IS NULL AND expires_at > :now
             ORDER BY created_at DESC LIMIT 1',
            ['now' => time()],
        );

        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            /** @var array<string, mixed> $claimRow */
            $claimRow = $row;
            /** @var string|null */
            $existingJwkRaw = $claimRow['public_key_jwk'] ?? null;
            /** @var array<string, mixed>|null $existingJwk */
            $existingJwk = is_string($existingJwkRaw) ? json_decode($existingJwkRaw, true) : null;
            if (is_array($existingJwk) && $this->jwkFingerprint($existingJwk) === $fingerprint) {
                return $claimRow;
            }
        }
        return null;
    }

    /**
     * Compute a fingerprint for a JWK (SHA-256 of canonical JSON).
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkFingerprint(array $jwk): string
    {
        ksort($jwk);
        $canonical = json_encode($jwk);
        return hash('sha256', $canonical !== false ? $canonical : '', true);
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
