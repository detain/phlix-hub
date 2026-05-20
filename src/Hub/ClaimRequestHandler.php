<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

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
 * @since 0.3.0
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
        $normalizedCode = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $claimCode));
        if ($normalizedCode === '') {
            $this->audit->logFailedAuth('CLAIM_CODE_INVALID', ['claim_code' => $claimCode]);
            throw new InvalidArgumentException('CLAIM_CODE_NOT_FOUND');
        }

        $now = time();

        $this->db->query(
            "SELECT * FROM server_claims WHERE claim_code = :code FOR UPDATE",
            ['code' => $normalizedCode],
        );
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM server_claims WHERE claim_code = :code FOR UPDATE",
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

        $this->db->query(
            'UPDATE server_claims
             SET claimed_by = :user_id, claimed_at = :claimed_at
             WHERE id = :id',
            ['user_id' => $userId, 'claimed_at' => $nowUnix, 'id' => $claimRowId],
        );

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
                'last_seen_at' => $nowUnix,
                'created_at' => $nowUnix,
                'enrolled_at' => $nowUnix,
            ],
        );

        $this->db->query("DELETE FROM server_claims WHERE id = :id", ['id' => $claimRowId]);

        $jwtService = new EnrollmentJwtService($this->keyManager, $this->hubBaseUrl);
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
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
