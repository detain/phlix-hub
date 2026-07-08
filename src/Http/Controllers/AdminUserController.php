<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Admin JSON API for hub user management (hubby.md H1.3).
 *
 * Serves the `/api/v1/admin/users*` surface the redesigned shared `@phlix/ui`
 * admin console (`AdminUsersApi`) calls — list / get / create / update /
 * delete plus set-admin and reset-password. Ported from `phlix-server`'s
 * `\Phlix\Server\Http\Controllers\Admin\AdminUserController` and adapted to
 * the hub, which differs in a few load-bearing ways:
 *
 *  - hub user ids are **UUID strings**, so every id arrives as `$params['id']`
 *    (a string) — never an int;
 *  - the hub {@see UserRepository::create()} takes a **plain** password (it
 *    hashes internally) and does not accept an `is_admin` flag, so creation
 *    passes the plain password and calls {@see UserRepository::setAdmin()}
 *    afterwards when admin was requested;
 *  - both `username` and `email` carry a UNIQUE index, so create/update
 *    pre-check both (the hub repo does not catch the constraint violation,
 *    which would otherwise surface as a 500);
 *  - the hub has **no profiles table** (profiles are a media-server concept),
 *    so `GET /{id}/profiles` always returns an empty list — wired only so the
 *    shared Users page's "Profiles" action degrades to a clean empty state
 *    rather than a 404 toast;
 *  - every mutation is recorded via {@see AuditLogger::logAdminAction()}
 *    (hub convention — see {@see RequestController}).
 *
 * All routes are gated by {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} +
 * {@see \Phlix\Hub\Http\Middleware\AdminMiddleware} (wired in
 * {@see \Phlix\Hub\Application::registerAdminUserRoutes()}); this controller
 * therefore assumes the caller is already an authenticated admin, and reads
 * the acting admin id from {@see Request::$userId} for audit trails and the
 * self-action guards (cannot delete/demote yourself, cannot remove the last
 * admin).
 *
 * Responses mirror the server controller's shapes so the shared client
 * unwraps them unchanged: `{ users }`, `{ user }`, `{ user_id, message }`,
 * `{ message }`, `{ message, new_password }`, `{ profiles }`; errors carry
 * `{ error, code, field_errors? }`.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class AdminUserController
{
    private const MIN_PASSWORD_LENGTH = 8;
    private const USERNAME_MIN = 3;
    private const USERNAME_MAX = 50;
    private const GENERATED_PASSWORD_LENGTH = 12;

    /**
     * @param UserRepository $users Repository for user data access.
     * @param AuditLogger    $audit Audit logger; records each mutation.
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * `GET /api/v1/admin/users` — list every user (public columns only).
     */
    public function list(Request $request): Response
    {
        $users = array_map(
            fn (array $row): array => $this->publicUser($row),
            $this->users->findAll(),
        );
        return (new Response())->json(['users' => $users]);
    }

    /**
     * `GET /api/v1/admin/users/{id}` — fetch a single user.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function get(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $user = $this->users->findById($id);
        if ($user === null) {
            return $this->notFound();
        }
        return (new Response())->json(['user' => $this->publicUser($user)]);
    }

    /**
     * `POST /api/v1/admin/users` — create a user. Body: `username`, `email`,
     * `password`, optional `is_admin` (boolean).
     */
    public function create(Request $request): Response
    {
        $body = $request->body;

        $username = $this->trimmedString($body, 'username');
        $email = $this->trimmedString($body, 'email');
        $password = $this->rawString($body, 'password');
        $isAdmin = filter_var($body['is_admin'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $usernameError = $this->validateUsername($username);
        if ($usernameError !== null) {
            return $this->fieldError('Invalid username', 'username', $usernameError);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fieldError('Invalid email', 'email', 'Invalid email format');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->fieldError(
                'Invalid password',
                'password',
                'Password must be at least 8 characters',
            );
        }
        if ($this->users->emailExists($email)) {
            return $this->fieldError('Email already exists', 'email', 'This email is already registered');
        }
        if ($this->users->usernameExists($username)) {
            return $this->fieldError('Username already exists', 'username', 'This username is already taken');
        }

        $userId = $this->users->create([
            'username' => $username,
            'email'    => $email,
            'password' => $password,
        ]);
        if ($isAdmin) {
            $this->users->setAdmin($userId, true);
        }

        $this->audit->logAdminAction(
            $request->userId ?? '',
            'user.create',
            $userId,
            ['username' => $username, 'is_admin' => $isAdmin],
        );

        return (new Response())->status(201)->json([
            'user_id' => $userId,
            'message' => 'User created successfully',
        ]);
    }

    /**
     * `PUT /api/v1/admin/users/{id}` — update a user. Any subset of
     * `username`, `email`, `password` may be supplied; omitted fields are
     * left unchanged.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function update(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $existing = $this->users->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        $body = $request->body;
        /** @var array<string, string> $data */
        $data = [];

        if (array_key_exists('username', $body)) {
            $username = $this->trimmedString($body, 'username');
            $usernameError = $this->validateUsername($username);
            if ($usernameError !== null) {
                return $this->fieldError('Invalid username', 'username', $usernameError);
            }
            $clash = $this->users->findByUsername($username);
            if ($clash !== null && $this->str($clash, 'id') !== $id) {
                return $this->fieldError('Username already in use', 'username', 'This username is already taken');
            }
            $data['username'] = $username;
        }

        if (array_key_exists('email', $body)) {
            $email = $this->trimmedString($body, 'email');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->fieldError('Invalid email', 'email', 'Invalid email format');
            }
            $clash = $this->users->findByEmail($email);
            if ($clash !== null && $this->str($clash, 'id') !== $id) {
                return $this->fieldError('Email already in use', 'email', 'This email is already registered');
            }
            $data['email'] = $email;
        }

        if (array_key_exists('password', $body)) {
            $password = $this->rawString($body, 'password');
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                return $this->fieldError(
                    'Invalid password',
                    'password',
                    'Password must be at least 8 characters',
                );
            }
            $data['password'] = $password;
        }

        if ($data !== []) {
            $this->users->update($id, $data);
            $this->audit->logAdminAction(
                $request->userId ?? '',
                'user.update',
                $id,
                ['fields' => array_keys($data)],
            );
        }

        return (new Response())->json(['message' => 'User updated successfully']);
    }

    /**
     * `DELETE /api/v1/admin/users/{id}` — delete a user. Refuses to delete
     * the caller's own account or the final remaining admin.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function delete(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $existing = $this->users->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        if (($request->userId ?? '') === $id) {
            return $this->badRequest('Cannot delete your own account', 'cannot_delete_self');
        }
        if ($this->intFlag($existing, 'is_admin') === 1 && $this->users->countAdmins() <= 1) {
            return $this->badRequest('Cannot delete the last admin', 'last_admin');
        }

        $this->users->delete($id);
        $this->audit->logAdminAction($request->userId ?? '', 'user.delete', $id);

        return (new Response())->json(['message' => 'User deleted successfully']);
    }

    /**
     * `POST /api/v1/admin/users/{id}/set-admin` — promote or demote a user.
     * Body: `is_admin` (boolean). Refuses to demote the caller or the final
     * remaining admin.
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function setAdmin(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $existing = $this->users->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        $isAdmin = filter_var($request->body['is_admin'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $actingId = $request->userId ?? '';

        if (!$isAdmin && $actingId === $id) {
            return $this->badRequest('Cannot demote yourself', 'cannot_demote_self');
        }
        if (!$isAdmin && $this->intFlag($existing, 'is_admin') === 1 && $this->users->countAdmins() <= 1) {
            return $this->badRequest('Cannot demote the last admin', 'last_admin');
        }

        $this->users->setAdmin($id, $isAdmin);
        $this->audit->logAdminAction($actingId, 'user.set_admin', $id, ['is_admin' => $isAdmin]);

        return (new Response())->json(['message' => 'User admin status updated successfully']);
    }

    /**
     * `POST /api/v1/admin/users/{id}/reset-password` — generate a new random
     * password, store its hash, and return the plaintext once (the only time
     * it is ever exposed).
     *
     * @param array<string, string> $params Path params; expects `id`.
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $existing = $this->users->findById($id);
        if ($existing === null) {
            return $this->notFound();
        }

        $newPassword = $this->generatePassword();
        $this->users->update($id, ['password' => $newPassword]);
        $this->audit->logAdminAction($request->userId ?? '', 'user.reset_password', $id);

        return (new Response())->json([
            'message'      => 'Password reset successfully',
            'new_password' => $newPassword,
        ]);
    }

    /**
     * `GET /api/v1/admin/users/{id}/profiles` — the hub has no profile
     * subsystem, so this always returns an empty list. See the class docblock.
     *
     * @param array<string, string> $params Path params (unused).
     */
    public function listProfiles(Request $request, array $params): Response
    {
        return (new Response())->json(['profiles' => []]);
    }

    /**
     * Project a raw user row to the public shape the shared client expects
     * (`User` in `@phlix/ui`): id, username, email, is_admin (0|1), created_at,
     * updated_at — and crucially NOT password_hash.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *     id: string,
     *     username: string,
     *     email: string,
     *     is_admin: int,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    private function publicUser(array $row): array
    {
        return [
            'id'         => $this->str($row, 'id'),
            'username'   => $this->str($row, 'username'),
            'email'      => $this->str($row, 'email'),
            'is_admin'   => $this->intFlag($row, 'is_admin'),
            'created_at' => $this->str($row, 'created_at'),
            'updated_at' => $this->str($row, 'updated_at'),
        ];
    }

    /**
     * Validate a username; returns a human message on failure, or null when
     * the username is acceptable (3-50 chars, alphanumeric + underscore).
     */
    private function validateUsername(string $username): ?string
    {
        $length = strlen($username);
        if ($length < self::USERNAME_MIN || $length > self::USERNAME_MAX) {
            return 'Username must be 3-50 characters';
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $username) !== 1) {
            return 'Username must be alphanumeric with underscores only';
        }
        return null;
    }

    /**
     * Read a string body field, trimmed; non-strings collapse to `''`.
     *
     * @param array<string, mixed> $body
     */
    private function trimmedString(array $body, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $body[$key] ?? null;
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Read a string body field verbatim (no trim — passwords may be padded
     * intentionally); non-strings collapse to `''`.
     *
     * @param array<string, mixed> $body
     */
    private function rawString(array $body, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Coerce a row value to a string (handles the int/string ambiguity of
     * the MySQL driver); anything else collapses to `''`.
     *
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $row[$key] ?? '';
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Coerce a row's truthy flag column to the strict 0|1 the wire contract
     * uses, regardless of whether the driver hands back an int, a numeric
     * string, or a bool.
     *
     * @param array<string, mixed> $row
     */
    private function intFlag(array $row, string $key): int
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $row[$key] ?? 0;
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return is_numeric($value) && (int) $value !== 0 ? 1 : 0;
    }

    /**
     * 404 — user not found.
     */
    private function notFound(): Response
    {
        return (new Response())->status(404)->json([
            'error' => 'User not found',
            'code'  => 'user_not_found',
        ]);
    }

    /**
     * 400 — a business-rule rejection (`{ error, code }`).
     */
    private function badRequest(string $error, string $code): Response
    {
        return (new Response())->status(400)->json([
            'error' => $error,
            'code'  => $code,
        ]);
    }

    /**
     * 400 — a field validation failure (`{ error, code, field_errors }`),
     * matching the server controller so the shared client surfaces it.
     */
    private function fieldError(string $error, string $field, string $detail): Response
    {
        return (new Response())->status(400)->json([
            'error'        => $error,
            'code'         => 'validation_failed',
            'field_errors' => [$field => $detail],
        ]);
    }

    /**
     * Generate a random 12-character password from a mixed alphabet using a
     * cryptographically secure RNG.
     */
    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $max = strlen($chars) - 1;
        $password = '';
        for ($i = 0; $i < self::GENERATED_PASSWORD_LENGTH; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}
