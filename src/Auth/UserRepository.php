<?php

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Workerman\MySQL\Connection;

/**
 * Hub-side users repository.
 *
 * Mirrors the public surface of `phlix-server`'s
 * `\Phlix\Auth\UserRepository` but adapted to:
 *
 *  - the hub schema in `migrations/001_users.sql` (no `user_settings`
 *    table, no `avatar_url`, no `last_login`-named column — the hub
 *    uses `updated_at` for now and Phase C may add a `last_login_at`);
 *  - workerman/mysql's named-placeholder requirement (see
 *    {@see \Phlix\Hub\Common\Database\MigrationRunner::recordApplied()}
 *    for the binding quirk that bit us in B.6).
 *
 * @package Phlix\Hub\Auth
 * @since 0.2.0
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
     * Refresh `updated_at` for a user — the hub treats this as a
     * surrogate for "last activity" until a dedicated `last_login_at`
     * column lands in a follow-up migration.
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
