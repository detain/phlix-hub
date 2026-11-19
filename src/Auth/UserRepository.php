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
use Phlix\Hub\Common\Support\Ids;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Hub-side users repository.
 *
 * Mirrors the public surface of `phlix-server`'s
 * `\Phlix\Auth\UserRepository` but adapted to:
 *
 *  - the hub schema in `migrations/001_users.sql` (no `user_settings`
 *    table, no `avatar_url`, no `last_login`-named column — the hub
 *    uses `updated_at` for now);
 *  - workerman/mysql's named-placeholder requirement (see
 *    {@see \Phlix\Hub\Common\Database\MigrationRunner::recordApplied()}
 *    for the binding quirk).
 *
 * @package Phlix\Hub\Auth
 */
class UserRepository
{
    /**
     * @param Connection $db Workerman MySQL connection.
     */
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Fetch a user row by primary key.
     *
     * @return array<string, mixed>|null Row map, or null when not found.
     */
    public function findById(string $id): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, email, password_hash, display_name, is_admin, created_at, updated_at '
            . 'FROM users WHERE id = :id',
            ['id' => $id],
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        return $this->normaliseRow($result[0]);
    }

    /**
     * Fetch a user row by username.
     *
     * @return array<string, mixed>|null Row map, or null when not found.
     */
    public function findByUsername(string $username): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, email, password_hash, display_name, is_admin, created_at, updated_at '
            . 'FROM users WHERE username = :username',
            ['username' => $username],
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        return $this->normaliseRow($result[0]);
    }

    /**
     * Fetch a user row by email.
     *
     * @return array<string, mixed>|null Row map, or null when not found.
     */
    public function findByEmail(string $email): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, email, password_hash, display_name, is_admin, created_at, updated_at '
            . 'FROM users WHERE email = :email',
            ['email' => $email],
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        return $this->normaliseRow($result[0]);
    }

    /**
     * Look up a user row only when `is_admin = 1`. Returns null for both
     * unknown ids and known-but-non-admin users. Used by
     * {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}.
     *
     * @return array<string, mixed>|null
     */
    public function findAdminById(string $id): ?array
    {
        $result = $this->db->query(
            'SELECT id, username, email, password_hash, display_name, is_admin, created_at, updated_at '
            . 'FROM users WHERE id = :id AND is_admin = 1',
            ['id' => $id],
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        return $this->normaliseRow($result[0]);
    }

    /**
     * Total user count. Used by
     * {@see \Phlix\Hub\Auth\AuthManager::register()} to detect the very
     * first registration and auto-promote that user to admin.
     */
    public function countUsers(): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM users');
        if (!is_array($rows) || $rows === []) {
            return 0;
        }
        $row = $rows[0];
        if (!is_array($row)) {
            return 0;
        }
        /**
         * @var mixed $raw
         * @psalm-suppress MixedAssignment
         */
        $raw = $row['c'] ?? 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Toggle the `is_admin` flag on a user.
     */
    public function setAdmin(string $id, bool $isAdmin): void
    {
        $this->db->query(
            'UPDATE users SET is_admin = :flag WHERE id = :id',
            ['flag' => $isAdmin ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * Insert a new user row. The caller supplies the plain password — this
     * method hashes it with Argon2ID before persisting.
     *
     * Email uniqueness is enforced by the `uk_users_email` index; this
     * method does NOT pre-check (race-free behaviour is the index's job).
     * Callers should catch the thrown exception and translate it to a
     * domain-level "email already registered" error if needed.
     *
     * Credential arms — EXACTLY one is required (Minor 45):
     *  - `password` — plaintext; hashed here with Argon2id (the classic path).
     *  - `password_hash` — a PRECOMPUTED Argon2id digest, so a caller that
     *    already opened a transaction can keep the ~50-100 ms CPU-bound hash
     *    OUT of it (register() elects an admin inside the same txn; holding
     *    the `auth_signup_guard` gap locks across pure hashing stretches
     *    lock-hold time under signup bursts on resident workers).
     * Both arms, or neither, is a caller bug and throws immediately.
     *
     * @param array{
     *     username: string, email: string, password?: string,
     *     password_hash?: string, display_name?: ?string
     * } $data
     *
     * @return string Generated UUID for the new row.
     */
    public function create(array $data): string
    {
        $id = Ids::uuidV4();

        if (isset($data['password_hash']) && isset($data['password'])) {
            throw new InvalidArgumentException(
                'create() needs exactly one credential arm: password (plaintext) or password_hash (precomputed digest)'
            );
        }

        if (isset($data['password_hash'])) {
            /** @var mixed $digest */
            $digest = $data['password_hash'];
            if (!is_string($digest)) {
                throw new InvalidArgumentException('password_hash must be a precomputed Argon2id digest string');
            }
            if (!str_starts_with($digest, '$argon2id$')) {
                throw new InvalidArgumentException('password_hash must be a precomputed Argon2id digest string');
            }
            $passwordHash = $digest;
        } elseif (isset($data['password'])) {
            /** @var mixed $plaintext */
            $plaintext = $data['password'];
            if (!is_string($plaintext)) {
                throw new InvalidArgumentException('password must be a string');
            }
            $passwordHash = PasswordHasher::hash($plaintext);
        } else {
            throw new InvalidArgumentException(
                'create() needs exactly one credential arm: password (plaintext) or password_hash (precomputed digest)'
            );
        }

        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash, display_name) '
            . 'VALUES (:id, :username, :email, :pwd, :display)',
            [
                'id'       => $id,
                'username' => $data['username'],
                'email'    => $data['email'],
                'pwd'      => $passwordHash,
                'display'  => $data['display_name'] ?? $data['username'],
            ],
        );

        return $id;
    }

    /**
     * Return every user row ordered oldest-first, projected to the public
     * columns only — `password_hash` is never selected, so it cannot leak
     * through the admin user list (`GET /api/v1/admin/users`).
     *
     * @return list<array<string, mixed>> Zero or more user rows.
     */
    public function findAll(): array
    {
        $result = $this->db->query(
            'SELECT id, username, email, is_admin, created_at, updated_at '
            . 'FROM users ORDER BY created_at ASC, username ASC',
        );
        if (!is_array($result)) {
            return [];
        }
        $out = [];
        /**
         * @var mixed $row
         * @psalm-suppress MixedAssignment
         */
        foreach ($result as $row) {
            if (is_array($row)) {
                $out[] = $this->normaliseRow($row);
            }
        }
        return $out;
    }

    /**
     * Apply a partial update to a user. Only the keys present in `$data`
     * are written; every other column is left untouched, and passing no
     * recognised key is a no-op (no query is issued). A `password` entry
     * is treated as a PLAIN password and hashed with Argon2ID before
     * persisting — mirroring {@see self::create()} so callers never deal
     * in hashes. `updated_at` bumps automatically via the column's
     * `ON UPDATE CURRENT_TIMESTAMP`.
     *
     * Column names are a fixed allow-list (never interpolated from user
     * input) and every value is bound through a named placeholder.
     *
     * @param array<string, string> $data Recognised keys: `username`,
     *        `email`, `display_name`, and `password` (plain text).
     */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $params = [];

        foreach (['username', 'email', 'display_name'] as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $data[$column];
            }
        }

        if (array_key_exists('password', $data)) {
            $sets[] = 'password_hash = :password_hash';
            $params['password_hash'] = PasswordHasher::hash($data['password']);
        }

        if ($sets === []) {
            return;
        }

        $params['id'] = $id;
        $this->db->query(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
    }

    /**
     * Permanently delete a user row by primary key.
     */
    public function delete(string $id): void
    {
        $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Count users flagged `is_admin = 1`. Used to refuse demoting or
     * deleting the final administrator. Mirrors {@see self::countUsers()}
     * with a fixed `is_admin = 1` predicate.
     */
    public function countAdmins(): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1');
        if (!is_array($rows) || $rows === []) {
            return 0;
        }
        $row = $rows[0];
        if (!is_array($row)) {
            return 0;
        }
        /**
         * @var mixed $raw
         * @psalm-suppress MixedAssignment
         */
        $raw = $row['c'] ?? 0;
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Refresh `updated_at` for a user — the hub treats this as a
     * surrogate for "last activity" (there is no dedicated
     * `last_login_at` column).
     */
    public function updateLastLogin(string $id): void
    {
        $this->db->query(
            'UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Verify a plain password against the stored Argon2ID hash.
     *
     * Three law-bearing arms (B-06 hub twin of the server's shape):
     *  - absent user: the timing burn runs HERE, on the repository lookup
     *    path, so every entry point pays one full Argon2id verification and
     *    response time cannot enumerate account existence;
     *  - failed verify: plain false — a bad password never reaches the
     *    re-mint line below, and no write happens on a wrong guess;
     *  - successful verify of a non-canonical hash: forward-only cost
     *    correction (see below).
     */
    public function verifyPassword(string $id, string $password): bool
    {
        $user = $this->findById($id);
        if ($user === null) {
            $this->burnPasswordVerifyTime($password);
            return false;
        }
        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash)) {
            return false;
        }
        if (!PasswordHasher::verify($password, $hash)) {
            return false;
        }

        // B-06 (2026-11-19) forward-only cost correction: a credential that
        // just authenticated but was minted outside the canonical ARGON2ID
        // parameters — old bare password_hash() calls, drifted host ini
        // defaults, bcrypt/argon2i history — is re-minted now, at the moment
        // the plaintext is legitimately in hand. No migration exists or is
        // needed: password verification always reads the parameters embedded
        // in each hash, so stale shapes keep working until their owner logs
        // in.
        if (PasswordHasher::needsRehash($hash)) {
            // Hub divergence from the server shape (B-06 dispatch brief,
            // item c): the re-mint is a best-effort cost refresh — a failed
            // write must NEVER undo an already-authenticated login. The old
            // hash keeps verifying (parameters are embedded in the hash),
            // so the worst case is exactly the pre-B-06 status quo and the
            // owner's next login retries the refresh.
            try {
                $this->persistPasswordHash($id, PasswordHasher::hash($password));
            } catch (Throwable) {
                // Swallowed by design: a failed UPDATE leaves the stored
                // hash untouched, so there is no half-written state to
                // reconcile — the refresh is simply deferred, not lost.
            }
        }

        return true;
    }

    /**
     * Persist a re-minted credential from {@see self::verifyPassword()}.
     *
     * A transparent cost re-mint keeps the SAME secret valid: sessions and
     * tokens minted under the old (weaker) parameters stay valid — unlike
     * {@see self::update()} with a `password` key, which is a real password
     * change. The hub repository holds no request-scoped row cache, so no
     * cache invalidation is needed here. The CALLER owns the failure
     * posture: see the try/catch in verifyPassword() — a throw from this
     * method never reaches the login responder.
     */
    private function persistPasswordHash(string $id, string $hash): void
    {
        $this->db->query(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id',
            ['password_hash' => $hash, 'id' => $id],
        );
    }

    /**
     * Spend one Argon2id verification's worth of CPU on a password that will
     * never match (L-1 lineage — login timing oracle).
     *
     * An unknown identifier returning immediately while a known one burned a
     * full argon2id verify made the response time itself enumerate accounts.
     * AuthManager's login path calls this on its no-such-user arm; since
     * B-06 the repository's own {@see self::verifyPassword()} absent-user
     * arm pays it too, so any future entry point that hits the repository
     * directly inherits the equalisation. The dummy hash below is a real
     * argon2id hash minted with the same canonical cost parameters
     * {@see PasswordHasher::ARGON2ID_OPTIONS} that every mint in the hub now
     * passes explicitly (m=65536,t=4,p=1); its plaintext is unknown by
     * construction and the stored value is irrelevant — only its cost shape
     * matters, and being a class constant it is deterministic across
     * instances and processes (the pre-B-06 shape minted a fresh random
     * dummy lazily per AuthManager instance).
     *
     * @param string $password The candidate password to burn-verify.
     *
     * @return void
     */
    public function burnPasswordVerifyTime(string $password): void
    {
        PasswordHasher::verify($password, self::TIMING_BURN_DUMMY_HASH);
    }

    /**
     * Argon2id (canonical costs — {@see PasswordHasher::ARGON2ID_OPTIONS})
     * dummy hash for {@see burnPasswordVerifyTime()}. Regenerate it in
     * lockstep with any change to the canonical options; PasswordHasherTest
     * pins the embedded parameters against the SSOT constant.
     */
    private const string TIMING_BURN_DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$ajhMV1lZWk0wNUZYMUJJeA'
        . '$GnAZV0jZAc3B24RwsTj5qLoSiy6ITh4BFMMHxFIDdSQ';

    /**
     * Quick existence probe for email (used by signup pre-validation).
     */
    public function emailExists(string $email): bool
    {
        /**
         * @var mixed $result
         * @psalm-suppress MixedAssignment
         */
        $result = $this->db->query(
            'SELECT 1 FROM users WHERE email = :email',
            ['email' => $email],
        );
        return is_array($result) && $result !== [];
    }

    /**
     * Quick existence probe for user id — used by
     * {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} to confirm a valid
     * userId claim without loading the full row on every authenticated request.
     */
    public function userExists(string $id): bool
    {
        /**
         * @var mixed $result
         * @psalm-suppress MixedAssignment
         */
        $result = $this->db->query(
            'SELECT 1 FROM users WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
        return is_array($result) && $result !== [];
    }

    /**
     * Quick existence probe for username (used by signup pre-validation).
     */
    public function usernameExists(string $username): bool
    {
        /**
         * @var mixed $result
         * @psalm-suppress MixedAssignment
         */
        $result = $this->db->query(
            'SELECT 1 FROM users WHERE username = :username',
            ['username' => $username],
        );
        return is_array($result) && $result !== [];
    }

    /**
     * Coerce a raw DB row (mixed array shape) into a string-keyed map.
     *
     * @param array<int|string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        $out = [];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
