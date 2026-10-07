<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Shared\Schema\SchemaPaths;
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
     * Lazily-loaded cache of the per-key meta block derived from the hub
     * settings schema: dotted key → meta block. Populated once by
     * {@see loadSchemaMeta()} on the first call and reused thereafter.
     *
     * This is immutable config data (the schema is shipped read-only in the
     * vendored package), NOT per-request state, so caching it in a static is
     * resident-memory-safe.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $schemaMeta = null;

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
     * Reject numeric values outside the schema-meta minimum/maximum.
     *
     * Null (in-range, or key carries no numeric bounds / no meta) means
     * "acceptable". Value arrives already type-validated by the caller.
     */
    private static function boundsError(mixed $value, string $key): ?string
    {
        $block = self::schemaMeta()[$key] ?? null;
        if (!is_array($block)) {
            return null;
        }

        /** @var mixed $min */
        $min = $block['minimum'] ?? null;
        /** @var mixed $max */
        $max = $block['maximum'] ?? null;
        if (!is_numeric($value)) {
            // No numeric bounds apply — the type law already rejected garbage.
            return null;
        }
        $numeric = (float) $value;

        if (is_numeric($min) && $numeric < (float) $min) {
            return sprintf('Must be >= %s.', (string) (float) $min);
        }
        if (is_numeric($max) && $numeric > (float) $max) {
            return sprintf('Must be <= %s.', (string) (float) $max);
        }

        return null;
    }

    /**
     * Per-key meta block sourced from the shared hub settings schema.
     *
     * Each key in the returned map corresponds to a property in
     * `hub-settings.schema.json`.  The meta block carries everything the
     * admin SPA needs to render a settings row: label, help text, help links,
     * tier, group, enum constraints, min/max bounds, default value, and the
     * secret/restart flags.
     *
     * The vendored `detain/phlix-shared` schema is the SOLE source.  The W5
     * Phase-6 keys briefly lived in a hub-local `SUPPLEMENTAL_META` bridge
     * merged UNDER the schema output; upstreaming all fourteen into
     * `hub-settings.schema.json` at shared v0.52.0 satisfied the bridge's
     * stated yield condition, and the bridge was deleted here — its contract
     * was "if upstream ever ships a property for one of these keys, the local
     * entry becomes dead weight to delete".
     *
     * `restart` is honest per key: TRUE only where the value is consumed at
     * worker boot (metrics enabled at first per-worker resolve; rate-limiter
     * instances are per-worker singletons — the graceful-restart endpoint
     * recycles workers to apply them), FALSE where a live resolver reads the
     * EFFECTIVE value at every use.
     *
     * @return array<string, array<string, mixed>> Dotted setting key → meta block.
     */
    public static function schemaMeta(): array
    {
        if (self::$schemaMeta === null) {
            self::$schemaMeta = self::loadSchemaMeta();
        }

        return self::$schemaMeta;
    }

    /**
     * Read and decode the shared `hub-settings.schema.json` and project
     * every property into a per-key meta block.
     *
     * Numeric bounds pass through VERBATIM in the JSON literal type the
     * schema author chose (`json_decode` yields int for `60`, float for
     * `60.0`); meta consumers compare with assertSame, and the wire
     * encoding is type-independent (`JSON_PRETTY_PRINT` without
     * `JSON_PRESERVE_ZERO_FRACTION` renders both as `60`).
     *
     * Fail-safe: any unreadable, unparseable, or structurally-unexpected
     * schema yields an empty map `[]` rather than an exception.
     *
     * @return array<string, array<string, mixed>> Dotted setting key → meta block.
     */
    private static function loadSchemaMeta(): array
    {
        $path = SchemaPaths::hubSettings();
        $raw  = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (!isset($decoded['properties']) || !is_array($decoded['properties'])) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $meta */
        $meta = [];
        foreach ($decoded['properties'] as $key => $def) {
            if (!is_string($key) || !is_array($def)) {
                continue;
            }

            $meta[$key] = [
                'label'      => $def['label'] ?? null,
                'helpText'   => $def['helpText'] ?? null,
                'helpLinks'  => isset($def['helpLinks']) && is_array($def['helpLinks'])
                    ? $def['helpLinks']
                    : [],
                'tier'       => $def['tier'] ?? 'standard',
                'group'      => $def['group'] ?? null,
                'enum'       => isset($def['enum']) && is_array($def['enum']) ? $def['enum'] : null,
                'enumLabels' => isset($def['enumLabels']) && is_array($def['enumLabels'])
                    ? $def['enumLabels']
                    : null,
                'optionHelp' => isset($def['optionHelp']) && is_array($def['optionHelp'])
                    ? $def['optionHelp']
                    : null,
                'minimum'    => (isset($def['minimum']) && (is_int($def['minimum']) || is_float($def['minimum'])))
                    ? $def['minimum']
                    : null,
                'maximum'    => (isset($def['maximum']) && (is_int($def['maximum']) || is_float($def['maximum'])))
                    ? $def['maximum']
                    : null,
                'default'    => array_key_exists('default', $def) ? $def['default'] : null,
                'secret'     => !empty($def['secret']),
                'restart'    => !empty($def['restart']),
            ];
        }

        return $meta;
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
     *     "types": { "<key>": "<type>", ... },
     *     "meta": { "<key>": { label, helpText, ... }, ... }
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
                'meta' => self::schemaMeta(),
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
     *   "message": "Settings updated.",
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
     * Response shape (validation error, 400):
     * {
     *   "success": false,
     *   "error": "Validation failed",
     *   "errors": { "<key>": "<human message>", ... }
     * }
     *
     * The `errors` MAP (not a single first-failure `error` code) is the shape
     * the shared `@phlix/ui` admin Settings page consumes: it reads
     * `e.body.errors` to paint inline per-field messages
     * (`phlix-ui/src/pages/admin/SettingsPage.vue:288-291`). It is identical,
     * field for field, to the server's
     * {@see \Phlix\Server\Http\Controllers\Admin\AdminSettingsController::update()}
     * so one page component can serve both back ends. Every submitted key is
     * checked, so the user sees ALL problems at once rather than one per
     * round-trip.
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

        if (!is_array($settings) || $settings === []) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error' => 'Invalid payload',
                'message' => 'Body must contain a non-empty "settings" object.',
            ]);
        }

        $allowedKeys = HubSettingsRepository::ALLOWED_KEYS;

        // Validate EVERY key/type before persisting anything, accumulating an
        // errors map instead of bailing on the first failure.
        /** @var array<string, string> $errors */
        $errors = [];
        /** @var array<string, array{value: mixed, type: string}> $validated */
        $validated = [];

        /** @var mixed $value */
        foreach ($settings as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $allowedKeys)) {
                $errors[(string) $key] = 'Unknown setting key.';
                continue;
            }

            $expectedType = $allowedKeys[$key];
            [$valid, $actualType] = $this->validateValueType($value, $expectedType);

            if (!$valid) {
                $errors[$key] = sprintf('Expected type %s, got %s.', $expectedType, $actualType);
                continue;
            }

            // W5 bounds law (closes the F-09 "advisory only" residual):
            // numeric values are validated against the merged meta
            // minimum/maximum — enforcement lives HERE (plus a clamp on every
            // effective read at the consumer), so the schema bounds are a
            // contract, not a suggestion.
            if ($expectedType === 'int' || $expectedType === 'float') {
                $boundsError = self::boundsError($value, $key);
                if ($boundsError !== null) {
                    $errors[$key] = $boundsError;
                    continue;
                }
            }

            $validated[$key] = ['value' => $value, 'type' => $expectedType];
        }

        if ($errors !== []) {
            return (new Response())->status(400)->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $errors,
            ]);
        }
        // All-or-nothing persist.
        foreach ($validated as $key => $entry) {
            $this->settings->set($key, $entry['value'], $entry['type']);
        }

        // Re-resolve the full effective set so the response can echo the new
        // values + overridden list (the shared admin Settings page refreshes
        // its "custom" badges from this without a follow-up GET).
        /** @var list<string> $allKeys */
        $allKeys   = array_keys($allowedKeys);
        $effective = $this->settings->getEffectiveMany($allKeys);

        return (new Response())->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data' => [
                'settings' => $effective['values'],
                'overridden' => $effective['overridden'],
            ],
        ]);
    }
}
