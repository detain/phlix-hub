<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\CurrentPasswordInvalidException;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Self-service password change for the authenticated hub account.
 *
 * `POST /api/v1/me/password` — body `{current_password, new_password}`.
 * Closes the gap where the only password write path on the hub was the
 * ADMIN-gated `POST /api/v1/admin/users/{id}/reset-password` (an admin
 * action on other users, minting a fresh random secret): an account owner
 * could never rotate their own secret from any client.
 *
 * Failure mapping (zero new wire codes; every code below is in the
 * contracts vocab, used by the login/signup siblings):
 *
 *  - absent/blank fields            → 400 `auth.missing_credentials`
 *    (AuthController::signupJson :84-86 shape);
 *  - wrong current-password proof   → 401 `auth.invalid_credentials`
 *    (AuthController::loginJson :157-158 frame);
 *  - new secret fails policy        → 400 `validation_failed`
 *    (AuthController::signupJson :111-112 frame);
 *  - login budget exhausted         → 429 `rate_limited` + `Retry-After`
 *    (AuthController::rateLimited :179-184 frame — failed proofs consume
 *    the shared per-IP login budget, see AuthManager::changePassword()).
 *
 * Success is the same `{message}` JSON shape as the admin reset
 * (AdminUserController::resetPassword :305-308).
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class UserPasswordController
{
    public function __construct(
        private readonly AuthManager $auth,
    ) {
    }

    /**
     * `POST /api/v1/me/password` — rotate the caller's own password.
     */
    public function changePassword(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            // Defensive: AuthMiddleware should have caught this. Return the
            // same 401 frame as MeController :46-52 so the surface is
            // consistent even if the group gate is ever mis-wired.
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $currentPassword = self::stringField($request, 'current_password');
        $newPassword = self::stringField($request, 'new_password');
        if ($currentPassword === '' || $newPassword === '') {
            return self::errorFrame(400, 'auth.missing_credentials', 'current_password and new_password are required');
        }

        try {
            $this->auth->changePassword(
                $userId,
                $currentPassword,
                $newPassword,
                // Trusted-proxy-aware real client IP — the same key the
                // login limiter buckets on (mirrors loginJson :136-144).
                $request->getTrustedClientIp() ?: 'unknown',
            );
        } catch (CurrentPasswordInvalidException $e) {
            return self::errorFrame(401, 'auth.invalid_credentials', $e->getMessage());
        } catch (RateLimitException $e) {
            // Precede InvalidArgumentException so a budget trip maps to
            // 429 + Retry-After, never a misleading 400/401 (loginJson :153-156).
            return self::rateLimited($e);
        } catch (InvalidArgumentException $e) {
            return self::errorFrame(400, 'validation_failed', $e->getMessage());
        }

        return (new Response())->json([
            'message' => 'Password updated successfully',
        ]);
    }

    /**
     * Read a string field from `$request->body` (JSON). Returns "" when
     * missing or not-a-string — the AuthController::stringField :250-258
     * idiom kept local, as its sibling controllers do.
     */
    private static function stringField(Request $request, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $request->body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * The house error frame (AuthController::errorFrame :168-171):
     * `{error, code}` from Response::error() plus the legacy top-level
     * `message` twin carrying the same human text.
     */
    private static function errorFrame(int $status, string $code, string $message): Response
    {
        return (new Response())->error($status, $code, $message, ['message' => $message]);
    }

    /**
     * Shared 429 envelope — byte-identical frame to AuthController::rateLimited
     * :179-184 (status + Retry-After + `{error, code}`).
     */
    private static function rateLimited(RateLimitException $e): Response
    {
        return (new Response())
            ->status(429)
            ->header('Retry-After', (string) $e->retryAfterSeconds())
            ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited']);
    }
}
