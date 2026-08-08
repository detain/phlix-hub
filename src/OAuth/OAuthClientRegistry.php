<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Support\Ids;
use Workerman\MySQL\Connection;

use function explode;
use function hash;
use function implode;
use function is_array;
use function is_string;
use function str_contains;
use function trim;

/**
 * The OAuth client registry — the only place a `client_id` becomes an
 * {@see OAuthClient} (S92).
 *
 * ## Fail-closed lookup
 *
 * {@see find()} returns `null` for every one of: no such client, a disabled
 * client, a row whose `redirect_uris` column is empty, a row whose
 * `allowed_scopes` column is empty or contains nothing this build recognises,
 * and a confidential row with no stored secret hash. All of those arrive here
 * as an {@see InvalidArgumentException} out of {@see OAuthClient::create()} and
 * are logged and swallowed, because the alternative — returning a partially
 * populated client — is a client with an empty allow-list, and an empty
 * allow-list authorises everything.
 *
 * The distinction matters at the endpoint: an unknown `client_id` and a
 * half-provisioned `client_id` produce the identical `invalid_client` refusal,
 * so nothing about the registry's contents leaks to an unauthenticated caller.
 *
 * ## Storage shape
 *
 * `redirect_uris` is newline-delimited. A redirect URI cannot contain a raw
 * newline (it would not survive a `Location` header), so newline is a delimiter
 * no legitimate value can smuggle — unlike a space, which is legal inside a
 * percent-decoded URI, or a comma, which is legal in a path segment.
 * {@see register()} refuses a URI containing one rather than silently splitting
 * it into two registrations.
 *
 * Database access is exclusively through the async
 * {@see \Workerman\MySQL\Connection} client with named, colon-free parameter
 * keys, per the hub runtime rules.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthClientRegistry
{
    /** Delimiter used inside the `redirect_uris` column. */
    public const string URI_DELIMITER = "\n";

    /**
     * @param Connection            $db  Workerman MySQL connection.
     * @param StructuredLogger|null $log Auth-channel log for provisioning errors.
     *                                   Optional so a caller that only needs the
     *                                   lookup does not have to build one; when
     *                                   absent, a rejected row is still refused,
     *                                   just silently.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ?StructuredLogger $log = null,
    ) {
    }

    /**
     * Look up an enabled, fully-provisioned client by its public `client_id`.
     *
     * @param string $clientId The `client_id` presented on the wire.
     *
     * @return OAuthClient|null The client, or null when it is unknown,
     *         disabled, or stored in a shape that could only be permissive.
     */
    public function find(string $clientId): ?OAuthClient
    {
        if ($clientId === '') {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, client_id, name, redirect_uris, allowed_scopes, is_confidential, client_secret_hash'
                . ' FROM oauth_clients'
                . ' WHERE client_id = :client_id AND disabled_at IS NULL'
                . ' LIMIT 1',
            ['client_id' => $clientId],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Turn one raw row into an {@see OAuthClient}, or null.
     *
     * @param array<array-key, mixed> $row Raw result row.
     */
    private function hydrate(array $row): ?OAuthClient
    {
        $id       = $row['id'] ?? null;
        $clientId = $row['client_id'] ?? null;
        /** @var mixed $name */
        $name     = $row['name'] ?? null;
        $uris     = $row['redirect_uris'] ?? null;
        $scopes   = $row['allowed_scopes'] ?? null;

        if (!is_string($id) || !is_string($clientId) || !is_string($uris) || !is_string($scopes)) {
            return null;
        }

        $secretHash = is_string($row['client_secret_hash'] ?? null) ? (string) $row['client_secret_hash'] : null;
        if ($secretHash === '') {
            $secretHash = null;
        }

        $confidential = self::truthy($row['is_confidential'] ?? null);

        $uriList = [];
        foreach (explode(self::URI_DELIMITER, $uris) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $uriList[] = $candidate;
            }
        }

        try {
            return OAuthClient::create(
                $id,
                $clientId,
                is_string($name) ? $name : $clientId,
                $uriList,
                OAuthScopes::parse($scopes),
                $confidential,
                $secretHash,
            );
        } catch (InvalidArgumentException $e) {
            // A row that cannot become a valid client is treated as no client
            // at all. Logged, because it is an operator provisioning error the
            // caller must never be able to distinguish from "unknown client".
            $this->log?->warning(
                'oauth.client.rejected',
                ['client_id' => $clientId, 'reason' => $e->getMessage()],
            );

            return null;
        }
    }

    /**
     * Provision (or re-provision) a client.
     *
     * Used by the Alexa skill registration and by tests. Validates through
     * {@see OAuthClient::create()} BEFORE touching the database, so an invalid
     * client cannot be persisted and then silently ignored at lookup time.
     *
     * @param string       $clientId      Public `client_id`.
     * @param string       $name          Human label shown on the consent screen.
     * @param list<string> $redirectUris  Exact redirect URIs. MUST be non-empty.
     * @param list<string> $allowedScopes Scope ceiling. MUST be non-empty.
     * @param string|null  $secret        Plaintext client secret for a confidential
     *                                    client, or null for a public client. Only
     *                                    its SHA-256 hash is stored.
     *
     * @return OAuthClient The validated client as it was persisted.
     *
     * @throws InvalidArgumentException When the client would be permissive, or
     *         when a redirect URI contains the storage delimiter.
     */
    public function register(
        string $clientId,
        string $name,
        array $redirectUris,
        array $allowedScopes,
        ?string $secret = null,
    ): OAuthClient {
        foreach ($redirectUris as $uri) {
            if (str_contains($uri, self::URI_DELIMITER)) {
                throw new InvalidArgumentException(
                    'A redirect URI must not contain a newline; it would be stored as two separate URIs',
                );
            }
        }

        $secretHash = ($secret !== null && $secret !== '') ? hash('sha256', $secret) : null;

        $client = OAuthClient::create(
            Ids::uuidV4(),
            $clientId,
            $name,
            $redirectUris,
            $allowedScopes,
            $secretHash !== null,
            $secretHash,
        );

        $this->db->query(
            'INSERT INTO oauth_clients'
                . ' (id, client_id, name, redirect_uris, allowed_scopes, is_confidential, client_secret_hash)'
                . ' VALUES (:id, :client_id, :name, :redirect_uris, :allowed_scopes, :is_confidential,'
                . ' :client_secret_hash)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' name = VALUES(name), redirect_uris = VALUES(redirect_uris),'
                . ' allowed_scopes = VALUES(allowed_scopes), is_confidential = VALUES(is_confidential),'
                . ' client_secret_hash = VALUES(client_secret_hash), disabled_at = NULL',
            [
                'id'                 => $client->id,
                'client_id'          => $client->clientId,
                'name'               => $client->name,
                'redirect_uris'      => implode(self::URI_DELIMITER, $client->redirectUris),
                'allowed_scopes'     => OAuthScopes::toStorage($client->allowedScopes),
                'is_confidential'    => $client->requiresSecret() ? 1 : 0,
                'client_secret_hash' => $secretHash,
            ],
        );

        return $client;
    }

    /**
     * Disable a client. Existing tokens are NOT revoked here — that is
     * {@see OAuthTokenService::revokeForClient()}'s job, and the two are kept
     * separate so an operator can disable new grants without cutting live
     * sessions in the same action.
     *
     * @param string $clientId Public `client_id`.
     */
    public function disable(string $clientId): void
    {
        $this->db->query(
            'UPDATE oauth_clients SET disabled_at = NOW() WHERE client_id = :client_id AND disabled_at IS NULL',
            ['client_id' => $clientId],
        );
    }

    /**
     * Coerce a MySQL boolean-ish column to bool without treating the string
     * `"0"` as true (which a naked cast of a `TINYINT` returned as a string
     * would not do, but a `(bool)` on `"false"` would).
     *
     * @param mixed $value Raw column value.
     */
    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return $value === true || $value === 1;
    }
}
