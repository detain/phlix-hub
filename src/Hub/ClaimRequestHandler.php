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
     * @param (callable(): int)|null $maxServersResolver LIVE reader of
     *        `server.max_servers_per_user` (0 = unlimited; HubSettingsResolvers::int,
     *        clamped 0..1000, fail-safe to boot default). Null (tests, unbooted
     *        pool wiring) disables the cap — the pre-setting behavior.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Ed25519KeyManager $keyManager,
        private readonly StructuredLogger $logger,
        private readonly AuditLogger $audit,
        private readonly string $hubBaseUrl,
        private readonly mixed $maxServersResolver = null,
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
     * Enforce `server.max_servers_per_user` before a claim INSERTs a server.
     *
     * No resolver wired, or an effective cap of 0 → unlimited (the historical
     * behavior). Throws the wire-mapped 'SERVER_CAP_REACHED' code string
     * (ServerClaimController::mapError → 409 quota.exceeded).
     */
    private function assertWithinServerCap(string $userId): void
    {
        $resolver = $this->maxServersResolver;
        if (!is_callable($resolver)) {
            return;
        }

        $cap = $resolver();
        if ($cap <= 0) {
            return;
        }

        /** @var list<array<string, mixed>> $countRows */
        $countRows = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM servers WHERE user_id = :uid',
            ['uid' => $userId],
        );
        /** @var mixed $count */
        $count = $countRows[0]['cnt'] ?? null;
        $owned = is_numeric($count) ? (int) $count : 0;

        if ($owned >= $cap) {
            $this->logger->warning('Server claim refused: per-user quota reached', [
                'user_id' => $userId,
                'owned'   => $owned,
                'cap'     => $cap,
            ]);
            throw new InvalidArgumentException('SERVER_CAP_REACHED');
        }
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
                // R20-04: the claim code is a 10-minute pairing bearer secret —
                // it never goes to logs or the audit trail in plaintext. The
                // sha256 fingerprint keeps records correlatable per code.
                'claim_code_fp' => self::claimCodeFingerprint($claimCode),
                'claim_id' => $claimId,
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
            // R20-04: fingerprint, never the plaintext code (see above).
            'claim_code_fp' => self::claimCodeFingerprint($claimCode),
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
     * SECURITY POSTURE (review hub__exploiter M1, M-HUB-12): this method is
     * the claim-code guess surface. Its per-attempt cost is deliberately
     * asymmetric:
     *
     *  - A REFUSED guess (empty / unknown / expired / already-claimed) writes
     *    a file-log warning ONLY. It used to write a permanent `audit_logs`
     *    row per guess through {@see AuditLogger::logFailedAuth()} — an
     *    attacker-paced INSERT rate against a retained table. The row that
     *    DOES persist is the successful claim ({@see
     *    AuditLogger::logServerClaim()}) plus the rate-limit transition
     *    (ServerClaimController), so the trail records events, not attempts.
     *  - The guess RATE is bounded upstream by the SERVER_CLAIMS limiter
     *    (`server_claims:claim:<ip>`, ServerClaimController::assertPairingBudget)
     *    before this method is ever reached.
     *  - The three refusal frames stay contract-registered-distinct (404/410/
     *    409, errors.ts claim.* trio; @phlix/ui renders distinct copy from
     *    them) but byte-identical in SHAPE — see the mapError docblock on
     *    ServerClaimController for the full oracle-disclosure.
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
            // M12 de-amplification: every failed LOOKUP below is a file-log
            // warning, NOT an audit_logs row. Per-guess DB writes made the
            // grind attacker-paced on disk (one INSERT into a retained table
            // per wrong code, review hub__exploiter M1); the security-relevant
            // events that DO persist are the successful claim (logServerClaim)
            // and the first limiter trip per window (ServerClaimController).
            // Correlation per code survives via the R20-04 fingerprint in the
            // file channel; operators grep `.logs/audit.log`, the table stays
            // truthful about things that actually happened.
            $this->logger->warning('Server claim refused: empty claim code', [
                'event' => 'auth_failure',
                'reason' => 'CLAIM_CODE_INVALID',
            ]);
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
            // R19-01: match the STORED generated column `claim_code_norm`
            // (migration 048) instead of wrapping claim_code in REPLACE(). The
            // functional predicate could not use any index, so this FOR UPDATE
            // full-scanned and next-key-locked the whole table, serialising
            // every pairing. Equality against claim_code_norm is a single
            // const-access hit on uk_server_claims_code_norm — the row lock
            // lands exactly on the claimed row. claim_code itself stays the
            // dashed display form (e.g. ABCD-1234); the column derives the
            // de-dashed value, and its utf8mb4_unicode_ci collation matches
            // this method's uppercased normaliser.
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->db->query(
                'SELECT * FROM server_claims WHERE claim_code_norm = :code FOR UPDATE',
                ['code' => $normalizedCode],
            );

            if (empty($rows)) {
                $this->logger->warning('Server claim refused: unknown claim code', [
                    'event' => 'auth_failure',
                    'reason' => 'CLAIM_CODE_NOT_FOUND',
                    'claim_code_fp' => self::claimCodeFingerprint($normalizedCode),
                ]);
                throw new InvalidArgumentException('CLAIM_CODE_NOT_FOUND');
            }
            /** @var array<string, mixed> $row */
            $row = $rows[0];
            /** @var int */
            $expiresAt = is_int($row['expires_at'] ?? null) ? $row['expires_at'] : 0;
            $claimedBy = $row['claimed_by'] ?? null;

            if ($expiresAt < $now) {
                $this->logger->warning('Server claim refused: expired claim code', [
                    'event' => 'auth_failure',
                    'reason' => 'CLAIM_CODE_EXPIRED',
                    'claim_code_fp' => self::claimCodeFingerprint($normalizedCode),
                ]);
                throw new InvalidArgumentException('CLAIM_CODE_EXPIRED');
            }
            if ($claimedBy !== null) {
                $this->logger->warning('Server claim refused: already-claimed claim code', [
                    'event' => 'auth_failure',
                    'reason' => 'CLAIM_CODE_ALREADY_CLAIMED',
                    'claim_code_fp' => self::claimCodeFingerprint($normalizedCode),
                    'claimed_by' => $claimedBy,
                ]);
                throw new InvalidArgumentException('CLAIM_CODE_ALREADY_CLAIMED');
            }

            // Per-user server quota (server.max_servers_per_user, W5). Read
            // LIVE at claim time; 0 (default) = unlimited, preserving the
            // pre-setting behavior byte-for-byte. Counted inside the claim's
            // FOR UPDATE transaction and checked against ALL status values
            // (online/offline/claiming/disabled — same denominator as the My
            // Servers listing). Honest caveat: two DIFFERENT claim codes
            // claimed concurrently by the same user can each pass this count
            // before either INSERT lands (the FOR UPDATE locks the claim row,
            // not the servers count) — a rare, self-correcting overshoot of
            // at most the concurrent-claim count on a quota guard, not a
            // billing-strict invariant. Documented, not defended.
            $this->assertWithinServerCap($userId);

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
                'INSERT INTO servers
                    (id, user_id, server_name, version, public_key_jwk, hostname_candidates_json,
                     status, last_seen_at, created_at, enrolled_at)
                 VALUES
                    (:id, :user_id, :server_name, :version, :public_key_jwk, :hostname_candidates_json,
                     \'online\', NOW(), NOW(), :enrolled_at)',
                [
                    'id' => $serverId,
                    'user_id' => $userId,
                    'server_name' => $serverName,
                    'version' => $version,
                    'public_key_jwk' => $publicKeyJwk,
                    'hostname_candidates_json' => $hostnameCandidates,
                    // R19-04: servers.last_seen_at / created_at are DATETIME
                    // columns stamped with the DATABASE clock. last_seen_at in
                    // particular is aged against MySQL NOW() by
                    // ServerReaper::markStaleServersOffline(); writing it from
                    // PHP's timezone-formatted date() let host/DB clock skew
                    // false-flip a freshly enrolled server. (Federation
                    // already sets its own last_seen_at with NOW() — same law.)
                    // servers.enrolled_at is INT UNSIGNED (migration 012) —
                    // a Unix epoch is timezone-free, so it stays PHP-written.
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
            // R20-04: fingerprint only — the pairing secret stays out of logs.
            'claim_code_fp' => self::claimCodeFingerprint($normalizedCode),
        ]);

        // M12: this is the ONE claim event that persists to audit_logs. The
        // failed guesses above are file-log-only by design (per-guess DB rows
        // were an attacker-paced disk-growth vector); a server attaching to
        // an account is the security-relevant transition worth keeping.
        $this->audit->logServerClaim($userId, $serverId, self::claimCodeFingerprint($normalizedCode));

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
     * Correlation fingerprint of a claim code for logs/audit (R20-04).
     *
     * The claim code is a short-lived bearer secret that pairs a server to an
     * account; plaintext copies in logs or `audit_logs.context_json` outlive
     * the code's 10-minute TTL in every log-retention window and leak across
     * the admin audit surface. Emit instead the first 8 hex chars of its
     * SHA-256 over the NORMALISED form (separators stripped, uppercased) —
     * display variants of one code fingerprint identically, correlation per
     * code survives, and 32 bits of collision space over the tiny live-code
     * population is ample for a log tag (this is not a security boundary,
     * just a one-way label).
     */
    public static function claimCodeFingerprint(string $claimCode): string
    {
        $normalized = strtoupper((string) preg_replace('/[^a-zA-Z0-9]/', '', $claimCode));

        return substr(hash('sha256', $normalized), 0, 8);
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
