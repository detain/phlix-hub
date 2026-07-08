<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Phlix\Hub\Common\Support\Ids;
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
            'SELECT * FROM users WHERE id = :id',
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
            'SELECT * FROM users WHERE username = :username',
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
            'SELECT * FROM users WHERE email = :email',
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
            'SELECT * FROM users WHERE id = :id AND is_admin = 1',
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
     * @param array{username: string, email: string, password: string, display_name?: ?string} $data
     *
     * @return string Generated UUID for the new row.
     */
    public function create(array $data): string
    {
        $id = self::generateUuid();
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID);

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
            $params['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
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
     */
    public function verifyPassword(string $id, string $password): bool
    {
        $user = $this->findById($id);
        if ($user === null) {
            return false;
        }
        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash)) {
            return false;
        }
        return password_verify($password, $hash);
    }

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

    /**
     * Generate a UUID v4 string in the canonical 8-4-4-4-12 layout. Kept
     * inline so the hub doesn't take a hard dependency on a UUID
     * library; matches the helper used throughout `phlix-server`.
     */
    public static function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
