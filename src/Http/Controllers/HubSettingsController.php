<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Hub admin settings API controller.
 *
 * `GET /api/v1/me/hub-settings` — returns all hub-wide settings with their
 * effective values, overridden status, and declared types.
 *
 * `PUT /api/v1/me/hub-settings` — persists all-or-nothing overrides for
 * the submitted setting keys.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   H.5 (Hub admin settings UI)
 */
final class HubSettingsController
{
    /**
     * @param HubSettingsRepository $settings Hub settings store.
     */
    public function __construct(
        private readonly HubSettingsRepository $settings,
    ) {
    }

    /**
     * Validate that a value matches the expected type.
     *
     * @param mixed  $value        The value to validate.
     * @param string $expectedType One of int|bool|float|json|string.
     *
     * @return array{bool, string} [isValid, actualTypeString]
     */
    private function validateValueType(mixed $value, string $expectedType): array
    {
        return match ($expectedType) {
            'int' => [is_int($value), gettype($value)],
            'bool' => [is_bool($value), gettype($value)],
            'float' => [is_float($value) || is_int($value), gettype($value)],
            'json' => [is_array($value), gettype($value)],
            'string' => [is_string($value), gettype($value)],
            default => [false, gettype($value)],
        };
    }

    /**
     * `GET /api/v1/me/hub-settings` — return all hub settings.
     *
     * Response shape:
     * {
     *   "success": true,
     *   "data": {
     *     "settings": { "<key>": <value>, ... },
     *     "overridden": ["<key>", ...],
     *     "types": { "<key>": "<type>", ... }
     *   }
     * }
     *
     * Status codes:
     * - 200: success
     * - 401: not authenticated (handled by AuthMiddleware upstream)
     * - 403: not admin (handled by AdminMiddleware upstream)
     */
    public function getSettings(Request $request): Response
    {
        /** @var list<string> $allKeys */
        $allKeys = array_keys(HubSettingsRepository::ALLOWED_KEYS);
        $effective = $this->settings->getEffectiveMany($allKeys);

        $types = [];
        foreach (HubSettingsRepository::ALLOWED_KEYS as $key => $type) {
            $types[$key] = $type;
        }

        return (new Response())->json([
            'success' => true,
            'data' => [
                'settings' => $effective['values'],
                'overridden' => $effective['overridden'],
                'types' => $types,
            ],
        ]);
    }

    /**
     * `PUT /api/v1/me/hub-settings` — persist hub setting overrides.
     *
     * Body shape:
     * {
     *   "settings": { "<key>": <value>, ... }
     * }
     *
     * All-or-nothing: if any key is unknown or type is wrong, no setting
     * is persisted.
     *
     * Response shape (success):
     * {
     *   "success": true,
     *   "data": {
     *     "settings": { "<key>": <value>, ... },
     *     "overridden": ["<key>", ...]
     *   }
     * }
     *
     * The `data` envelope echoes the re-resolved effective settings and the
     * new overridden list so the shared `@phlix/ui` admin Settings page can
     * refresh its "custom" badges from the save response without a second GET.
     * It is purely additive: the SSR `/api/v1/me/hub-settings` consumer reads
     * only `success`.
     *
     * Response shape (error):
     * { "success": false, "error": "<code>", "message": "<human message>" }
     *
     * Status codes:
     * - 200: success
     * - 400: validation error (invalid key or wrong value type)
     * - 401: not authenticated (handled by AuthMiddleware upstream)
     * - 403: not admin (handled by AdminMiddleware upstream)
     */
    public function putSettings(Request $request): Response
    {
        $body = $request->body;
        $settings = $body['settings'] ?? null;

        if (!is_array($settings)) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error' => 'invalid_body',
                'message' => 'Body must contain { settings: { ... } }',
            ]);
        }

        $allowedKeys = HubSettingsRepository::ALLOWED_KEYS;

        // Validate all keys and types before persisting anything.
        /** @var mixed $value */
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $allowedKeys)) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'invalid_key',
                    'message' => "Unknown setting key: {$key}",
                ]);
            }

            $expectedType = $allowedKeys[$key];
            [$valid, $actualType] = $this->validateValueType($value, $expectedType);

            if (!$valid) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error' => 'invalid_type',
                    'message' => "Setting '{$key}' expects {$expectedType}, got {$actualType}",
                ]);
            }
        }

        // All-or-nothing persist.
        /** @var mixed $value */
        foreach ($settings as $key => $value) {
            $this->settings->set((string) $key, $value, $allowedKeys[$key]);
        }

        // Re-resolve the full effective set so the response can echo the new
        // values + overridden list (the shared admin Settings page refreshes
        // its "custom" badges from this without a follow-up GET).
        /** @var list<string> $allKeys */
        $allKeys   = array_keys($allowedKeys);
        $effective = $this->settings->getEffectiveMany($allKeys);

        return (new Response())->json([
            'success' => true,
            'data' => [
                'settings' => $effective['values'],
                'overridden' => $effective['overridden'],
            ],
        ]);
    }
}
