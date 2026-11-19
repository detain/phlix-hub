<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use InvalidArgumentException;
use Phlix\Shared\Auth\JwtClaims;
use Phlix\Shared\Auth\SessionFamily;
use Phlix\Shared\Security\Jwt;
use Throwable;

/**
 * HS256 JWT token handler for the Phlix Hub.
 *
 * Differences from `phlix-server`'s {@see \Phlix\Auth\JwtHandler}:
 *
 *  - Issuer (`iss`) defaults to {@see JwtClaims::ISS_PHLIX_HUB} ("phlix-hub"),
 *    not "phlix". Hub-minted tokens never collide with server-minted ones.
 *  - Audience (`aud`) defaults to {@see JwtClaims::AUD_HUB} ("hub"); the
 *    server expects {@see JwtClaims::AUD_SERVER}.
 *  - {@see self::validateToken()} returns a `?\Phlix\Shared\Auth\JwtClaims`
 *    instance, not an `array`. This is the proof-of-design for the
 *    cross-repo DTO from `phlix-shared` v0.2.0.
 *
 * Symmetric HMAC-SHA256 signing only — no support for RS256/ES256
 * (a potential future hardening task).
 *
 * @package Phlix\Hub\Auth
 */
final class JwtHandler
{
    /**
     * JWT claim carrying the session-family id (CP-029 / D-1a — the hub twin
     * of the server's `JwtHandler::CLAIM_SESSION_ID`, same literal, now
     * aliased to the shared {@see SessionFamily::CLAIM_SESSION_ID} SSOT —
     * FQCN references and the 'sid' wire bytes are unchanged). The shared
     * {@see JwtClaims} DTO is a fixed-field value object and does NOT model
     * this key: it rides the payload map beside the DTO, survives
     * encode/decode untouched, and is read only through
     * {@see self::sessionIdClaim()} / the `*WithSession()` seams below.
     * Unknown-key tolerance of `JwtClaims::fromPayload()` is what makes this
     * legal — verified, and pinned by the family suite's legacy-shape legs.
     */
    public const string CLAIM_SESSION_ID = SessionFamily::CLAIM_SESSION_ID;

    /**
     * Secret used for HMAC signing. Must be at least 32 bytes for HS256.
     */
    private string $secretKey;

    /**
     * Issuer string stamped into every token. Defaults to "phlix-hub".
     */
    private string $issuer;

    /**
     * Audience string stamped into every token. Defaults to "hub".
     */
    private string $audience;

    /**
     * Access token TTL in seconds.
     */
    private int $accessTtl;

    /**
     * Refresh token TTL in seconds.
     */
    private int $refreshTtl;

    /**
     * Optional live-TTL resolver. Called with the dotted hub-setting key and
     * the boot-time fallback, and expected to return the *effective* TTL in
     * seconds (hub_settings override → config default).
     *
     * This is what makes `auth.access_ttl` / `auth.refresh_ttl` genuinely
     * live rather than boot-bound: without it, an admin override would sit in
     * the `hub_settings` table and never reach a minted token. Wired by
     * {@see \Phlix\Hub\Common\Container\Providers\AuthServicesProvider}; left
     * null in unit tests and in the CLI smoke command, where the constructor
     * arguments are authoritative.
     *
     * Holds shared configuration lookup logic, never per-request state, so it
     * is safe on a resident-memory singleton.
     *
     * @var \Closure(string, int): int|null
     */
    private ?\Closure $ttlResolver;

    /**
     * Build a JwtHandler.
     *
     * @param string $secretKey  HMAC secret (≥32 bytes for HS256).
     * @param string $issuer     Issuer claim. Defaults to "phlix-hub".
     * @param string $audience   Audience claim. Defaults to "hub".
     * @param int    $accessTtl  Access TTL seconds (default 3600 = 1h). Used as
     *                           the fallback when no resolver is wired or the
     *                           resolver fails.
     * @param int    $refreshTtl Refresh TTL seconds (default 604800 = 7d). Same
     *                           fallback semantics as `$accessTtl`.
     * @param (callable(string, int): int)|null $ttlResolver Optional effective-TTL
     *                           resolver; see {@see self::$ttlResolver}.
     *
     * @throws InvalidArgumentException When the secret is too short.
     */
    public function __construct(
        string $secretKey,
        string $issuer = JwtClaims::ISS_PHLIX_HUB,
        string $audience = JwtClaims::AUD_HUB,
        int $accessTtl = 3600,
        int $refreshTtl = 604800,
        ?callable $ttlResolver = null,
    ) {
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT secret must be at least 32 bytes for HS256.');
        }
        $this->secretKey = $secretKey;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->accessTtl = $accessTtl;
        $this->refreshTtl = $refreshTtl;
        // Coerced at the boundary to a native \Closure (review hub Major 34
        // tail); the constructor still accepts any callable.
        $this->ttlResolver = $ttlResolver === null ? null : \Closure::fromCallable($ttlResolver);
    }

    /**
     * Mint a signed access token for a user.
     *
     * Output is `<header>.<payload>.<sig>` base64url-encoded. Caller can
     * feed the result back through {@see self::validateToken()} to round-trip
     * into a {@see JwtClaims}.
     *
     * @param string       $userId       Subject — the user UUID.
     * @param list<string> $scope        Permission strings; empty for unscoped.
     * @param ?string      $serverId     Optional `serverId` claim (used for
     *                                   hub-minted client tokens that target
     *                                   a specific server).
     * @param ?string      $sessionId    Optional `sid` session-family claim
     *                                   (CP-029 / D-1a). Null/'' mints the
     *                                   pre-055 shape byte-ident.
     *
     * @return string Encoded JWT.
     */
    public function createAccessToken(
        string $userId,
        array $scope = [],
        ?string $serverId = null,
        ?string $sessionId = null,
    ): string {
        $now = time();
        $claims = new JwtClaims(
            iss: $this->issuer,
            aud: $this->audience,
            sub: $userId,
            iat: $now,
            exp: $now + $this->getAccessTtl(),
            nbf: null,
            type: JwtClaims::TYPE_ACCESS,
            jti: null,
            scope: $scope,
            serverId: $serverId,
        );
        return $this->encode($this->withSessionClaim($claims->toPayload(), $sessionId));
    }

    /**
     * Mint a signed refresh token for a user. Refresh tokens carry a `jti`
     * precisely so revocation can be checked by id — {@see
     * \Phlix\Hub\Auth\RefreshTokenRevocationService} now records cut jtis and
     * {@see \Phlix\Hub\Auth\AuthManager::refresh()} consults it, so a logout
     * genuinely ends the token lineage instead of merely auditing it.
     *
     * @param string  $userId     Subject — the user UUID.
     * @param ?string $sessionId  Optional `sid` session-family claim
     *                            (CP-029 / D-1a). Null/'' mints the pre-055
     *                            shape byte-ident.
     *
     * @return string Encoded JWT.
     */
    public function createRefreshToken(string $userId, ?string $sessionId = null): string
    {
        $now = time();
        $claims = new JwtClaims(
            iss: $this->issuer,
            aud: $this->audience,
            sub: $userId,
            iat: $now,
            exp: $now + $this->getRefreshTtl(),
            nbf: null,
            type: JwtClaims::TYPE_REFRESH,
            jti: bin2hex(random_bytes(16)),
            scope: [],
            serverId: null,
        );
        return $this->encode($this->withSessionClaim($claims->toPayload(), $sessionId));
    }

    /**
     * Validate `$token` and return the decoded claims, or null when:
     * - the format is malformed,
     * - the signature does not verify,
     * - the issuer does not match this handler's configured `$issuer`,
     * - the audience does not match this handler's configured `$audience`,
     * - the token is expired (`exp` < now),
     * - the token is not yet valid (`{@see \Phlix\Shared\Auth\JwtClaims::isNotYetValid()}`
     *   with zero leeway — the minting side never sets `nbf`, so a future-dated
     *   `nbf` is forgery or clock drift, and strictness is the safe default),
     * - or the payload cannot be coerced into a {@see JwtClaims}.
     *
     * @param string $token Encoded JWT.
     *
     * @return JwtClaims|null Hydrated claims when valid; null otherwise.
     */
    public function validateToken(string $token): ?JwtClaims
    {
        $lineage = $this->validateTokenWithSession($token);

        return $lineage === null ? null : $lineage['claims'];
    }

    /**
     * The full validation pipeline in ONE decode, additionally surfacing the
     * `sid` session-family claim that the fixed-field {@see JwtClaims} DTO
     * cannot carry (CP-029 / D-1a). {@see self::validateToken()} is a thin
     * projection of this method — the historical validation behavior (the
     * order and shape of every rejection) is byte-ident.
     *
     * @return array{claims: JwtClaims, session_id: ?string}|null Hydrated
     *         claims plus the trimmed family id (null when the claim is
     *         absent, non-string, or blank) when the token is valid.
     */
    public function validateTokenWithSession(string $token): ?array
    {
        try {
            $payload = $this->decode($token);
        } catch (Throwable) {
            return null;
        }

        try {
            $claims = JwtClaims::fromPayload($payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($claims->iss !== $this->issuer) {
            return null;
        }
        if ($claims->aud !== $this->audience) {
            return null;
        }
        if ($claims->isExpired()) {
            return null;
        }
        if ($claims->isNotYetValid()) {
            return null;
        }

        return ['claims' => $claims, 'session_id' => self::sessionIdClaim($payload)];
    }

    /**
     * Read the `sid` claim out of a decoded (signature-verified) payload.
     *
     * The whole law in one place: absent → null; non-string → null; blank or
     * whitespace-only → null (trimmed). A caller seeing a non-null id has a
     * family id worth adjudicating; null means "pre-055 token — structural
     * outsider", NEVER "revoked" (mirrors the server twin verbatim).
     *
     * @param array<string, mixed>|null $payload
     */
    public static function sessionIdClaim(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        $raw = $payload[self::CLAIM_SESSION_ID] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $sessionId = trim($raw);

        return $sessionId === '' ? null : $sessionId;
    }

    /**
     * Stamp (or omit) the `sid` claim on a payload about to be encoded.
     *
     * Null/'' leaves the map untouched — the pre-055 mint shape stays
     * byte-identical, which the family suite's legacy legs pin.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withSessionClaim(array $payload, ?string $sessionId): array
    {
        if ($sessionId !== null && $sessionId !== '') {
            $payload[self::CLAIM_SESSION_ID] = $sessionId;
        }

        return $payload;
    }

    /**
     * Convenience: validate `$token` AND check the token type matches
     * {@see JwtClaims::TYPE_ACCESS}. Returns null when the token is
     * invalid, expired, or a refresh token.
     */
    public function validateAccessToken(string $token): ?JwtClaims
    {
        $claims = $this->validateToken($token);
        if ($claims === null) {
            return null;
        }
        return $claims->type === JwtClaims::TYPE_ACCESS ? $claims : null;
    }

    /**
     * Convenience: validate `$token` AND check the token type matches
     * {@see JwtClaims::TYPE_REFRESH}. Returns null when the token is
     * invalid, expired, or an access token.
     */
    public function validateRefreshToken(string $token): ?JwtClaims
    {
        $claims = $this->validateToken($token);
        if ($claims === null) {
            return null;
        }
        return $claims->type === JwtClaims::TYPE_REFRESH ? $claims : null;
    }

    /**
     * Convenience: {@see self::validateTokenWithSession()} AND the token type
     * must be {@see JwtClaims::TYPE_ACCESS}. The CP-029 family-aware read
     * seam for access paths (AuthMiddleware, AlexaAccountLink).
     *
     * @return array{claims: JwtClaims, session_id: ?string}|null
     */
    public function validateAccessTokenWithSession(string $token): ?array
    {
        $lineage = $this->validateTokenWithSession($token);
        if ($lineage === null) {
            return null;
        }

        return $lineage['claims']->type === JwtClaims::TYPE_ACCESS ? $lineage : null;
    }

    /**
     * Convenience: {@see self::validateTokenWithSession()} AND the token type
     * must be {@see JwtClaims::TYPE_REFRESH}. The CP-029 family-aware read
     * seam for rotation ({@see AuthManager::refresh()}).
     *
     * @return array{claims: JwtClaims, session_id: ?string}|null
     */
    public function validateRefreshTokenWithSession(string $token): ?array
    {
        $lineage = $this->validateTokenWithSession($token);
        if ($lineage === null) {
            return null;
        }

        return $lineage['claims']->type === JwtClaims::TYPE_REFRESH ? $lineage : null;
    }

    /**
     * *Effective* access token TTL, in seconds — the `auth.access_ttl`
     * hub-settings override when one is stored, else the boot-time config
     * default. Also the source of the JSON `expires_in` field in auth
     * responses, so `expires_in` can never disagree with the `exp` claim.
     */
    public function getAccessTtl(): int
    {
        return $this->resolveTtl('auth.access_ttl', $this->accessTtl);
    }

    /**
     * *Effective* refresh token TTL, in seconds (`auth.refresh_ttl` override
     * → config default). Used by the cookie max-age.
     */
    public function getRefreshTtl(): int
    {
        return $this->resolveTtl('auth.refresh_ttl', $this->refreshTtl);
    }

    /**
     * Ask the wired resolver for the effective TTL, falling back to the
     * boot-time value.
     *
     * Fail-safe by construction: a missing resolver, a thrown exception (the
     * DB being unreachable, say), or a non-positive result all degrade to
     * `$fallback`. Token minting must never fail because a settings lookup
     * did.
     *
     * @param string $key      Dotted hub-setting key.
     * @param int    $fallback Boot-time TTL in seconds.
     *
     * @return int Effective TTL in seconds; always > 0.
     */
    private function resolveTtl(string $key, int $fallback): int
    {
        if ($this->ttlResolver === null) {
            return $fallback;
        }

        try {
            $resolved = ($this->ttlResolver)($key, $fallback);
        } catch (Throwable) {
            return $fallback;
        }

        return $resolved > 0 ? $resolved : $fallback;
    }

    /**
     * Encode a payload as a signed JWT string.
     *
     * Delegates to the shared {@see Jwt::create()} kernel (wave S2 / DESIGN
     * §0, M-01): the mint header is the fixed `{"alg":"HS256","typ":"JWT"}`
     * pair in that key order, serialized exactly as this method did before,
     * so minted tokens are byte-identical to the pre-delegation shape.
     *
     * @param array<string, mixed> $payload Already shaped per {@see JwtClaims::toPayload()}.
     *
     * @throws \JsonException When the payload contains non-encodable data.
     */
    private function encode(array $payload): string
    {
        return Jwt::create($payload, $this->secretKey);
    }

    /**
     * Decode and signature-verify a JWT string.
     *
     * Delegates to the shared {@see Jwt::verify()} kernel — the canonical
     * decode order this class originated (segment count → header JSON with
     * `JSON_THROW_ON_ERROR` → alg/typ pin BEFORE any signature work →
     * `hash_equals` HMAC check → payload object, string keys only) now lives
     * there verbatim; rejections arrive as {@see \Phlix\Shared\Security\JwtException}.
     * The only caller, {@see self::validateTokenWithSession()}, catches
     * `Throwable` and maps every rejection to null exactly as before, so the
     * observable validation behavior is unmoved.
     *
     * @return array<string, mixed> Decoded payload.
     *
     * @throws \Phlix\Shared\Security\JwtException When the token is malformed, the
     *         header lies about alg/typ, or the signature does not verify.
     */
    private function decode(string $token): array
    {
        return Jwt::verify($token, $this->secretKey)->payload;
    }
}
