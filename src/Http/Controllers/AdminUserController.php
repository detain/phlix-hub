<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Admin JSON API for hub user management (shared admin console parity).
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
 * `{ error, code, field_errors? }`. Two deliberate hub-side divergences
 * (SH-078, review hub__webux #6/#7) ride INSIDE that envelope contract:
 * validation answers ONE 400 carrying every failing field (hub's own settings
 * law), and state conflicts (self-delete, last-admin, self-demotion) answer
 * 409 with `{ error: 'Conflict', code, message }` (hub's own federation
 * conflict shape). Codes and field_errors are unchanged in kind, so the
 * shared client's code-keyed surfacing keeps working on both hosts.
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
        $emailValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $passwordValid = strlen($password) >= self::MIN_PASSWORD_LENGTH;

        // SH-078 (review hub__webux #6): every field problem is reported in ONE
        // 400 — the old bail-on-first shape made admins fix one field per
        // round-trip. This mirrors the hub's own settings law
        // (HubSettingsController::putSettings answers ALL problems at once);
        // per-field detail strings ride unchanged inside field_errors.
        /** @var array<string, string> $fieldErrors */
        $fieldErrors = [];
        if ($usernameError !== null) {
            $fieldErrors['username'] = $usernameError;
        }
        if (!$emailValid) {
            $fieldErrors['email'] = 'Invalid email format';
        }
        if (!$passwordValid) {
            $fieldErrors['password'] = 'Password must be at least 8 characters';
        }

        // Uniqueness pre-checks run only when every format check passed: they
        // need the DB, and a malformed value must not spend a lookup whose
        // slot already carries the format detail.
        if ($fieldErrors === []) {
            if ($this->users->emailExists($email)) {
                $fieldErrors['email'] = 'This email is already registered';
            }
            if ($this->users->usernameExists($username)) {
                $fieldErrors['username'] = 'This username is already taken';
            }
        }

        if ($fieldErrors !== []) {
            return $this->fieldErrors($fieldErrors);
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

        // SH-078 (review hub__webux #6): accumulate every field problem into a
        // single 400 (see create() for the law); details keep their exact
        // legacy strings.
        /** @var array<string, string> $fieldErrors */
        $fieldErrors = [];

        if (array_key_exists('username', $body)) {
            $username = $this->trimmedString($body, 'username');
            $usernameError = $this->validateUsername($username);
            if ($usernameError !== null) {
                $fieldErrors['username'] = $usernameError;
            } else {
                $clash = $this->users->findByUsername($username);
                if ($clash !== null && $this->str($clash, 'id') !== $id) {
                    $fieldErrors['username'] = 'This username is already taken';
                } else {
                    $data['username'] = $username;
                }
            }
        }

        if (array_key_exists('email', $body)) {
            $email = $this->trimmedString($body, 'email');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $fieldErrors['email'] = 'Invalid email format';
            } else {
                $clash = $this->users->findByEmail($email);
                if ($clash !== null && $this->str($clash, 'id') !== $id) {
                    $fieldErrors['email'] = 'This email is already registered';
                } else {
                    $data['email'] = $email;
                }
            }
        }

        if (array_key_exists('password', $body)) {
            $password = $this->rawString($body, 'password');
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $fieldErrors['password'] = 'Password must be at least 8 characters';
            } else {
                $data['password'] = $password;
            }
        }

        if ($fieldErrors !== []) {
            return $this->fieldErrors($fieldErrors);
        }

        if ($data !== []) {
            $this->users->update($id, $data);
            $this->audit->logAdminAction(
                $request->userId ?? '',
                'user.update',
                $id,
                ['fields' => array_keys($data)],
            );

            return (new Response())->json(['message' => 'User updated successfully']);
        }

        // SH-078: a PUT that recognized no fields said "updated" anyway. The
        // 200 stays (the request is valid and the row is untouched — nothing
        // failed), but the message now says what actually happened. Copy obeys
        // the hub terminal-period law (SH-083).
        return (new Response())->json(['message' => 'No recognized fields to update']);
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

        // SH-078 (review hub__webux #7): these are conflicts with the CURRENT
        // resource state (who the caller is, how many admins remain), not
        // malformed requests — 409, mirroring the hub's own conflict precedent
        // (FederationController's offer_already_responded shape
        // {error: 'Conflict', code, message}). Codes ride unchanged.
        if (($request->userId ?? '') === $id) {
            return $this->conflict('Cannot delete your own account', 'cannot_delete_self');
        }
        if ($this->intFlag($existing, 'is_admin') === 1 && $this->users->countAdmins() <= 1) {
            return $this->conflict('Cannot delete the last admin', 'last_admin');
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

        // SH-078 #7: state conflicts answer 409 (see delete() for the law).
        if (!$isAdmin && $actingId === $id) {
            return $this->conflict('Cannot demote yourself', 'cannot_demote_self');
        }
        if (!$isAdmin && $this->intFlag($existing, 'is_admin') === 1 && $this->users->countAdmins() <= 1) {
            return $this->conflict('Cannot demote the last admin', 'last_admin');
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
     * 409 — a conflict with the resource's current state
     * (`{ error, code, message }`), mirroring the hub's federation-conflict
     * shape (review hub__webux #7). The prose lives in `message`; `error`
     * carries the status-family word, exactly like the shipped
     * offer_already_responded frame.
     */
    private function conflict(string $message, string $code): Response
    {
        return (new Response())->status(409)->json([
            'error'   => 'Conflict',
            'code'    => $code,
            'message' => $message,
        ]);
    }

    /**
     * 400 — field validation failure(s) (`{ error, code, field_errors }`).
     *
     * SH-078 (review hub__webux #6): ONE response carries EVERY failing field
     * (hub law, mirroring HubSettingsController's all-problems-at-once shape);
     * the umbrella `error` is the fixed string 'Validation failed' and the
     * per-field detail strings live in `field_errors`. The shared client
     * unwraps `code`/`field_errors` unchanged; code stays registered
     * 'validation_failed'.
     *
     * @param array<string, string> $fieldErrors
     */
    private function fieldErrors(array $fieldErrors): Response
    {
        return (new Response())->status(400)->json([
            'error'        => 'Validation failed',
            'code'         => 'validation_failed',
            'field_errors' => $fieldErrors,
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
