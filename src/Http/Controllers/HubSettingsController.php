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
     * Reject numeric values outside the merged-meta minimum/maximum.
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
     * Per-key meta block sourced directly from the shared hub settings schema.
     *
     * Each key in the returned map corresponds to a property in
     * `hub-settings.schema.json`.  The meta block carries everything the
     * admin SPA needs to render a settings row: label, help text, help links,
     * tier, group, enum constraints, min/max bounds, default value, and the
     * secret/restart flags.
     *
     * The vendored schema covers the four original keys; `detain/phlix-shared`
     * v0.50.0 shipped `auth.signups_disabled` and the first bridge was retired
     * at that pin (its docblock's stated yield condition). The W5 Phase-6 wave
     * re-introduces the SAME bridge shape for the fourteen new keys because
     * the vendored schema is immutable from inside this repo (SchemaPaths
     * resolves strictly inside the phlix-shared package): each entry is
     * MERGED UNDER the schema output — if upstream ever ships a property for
     * one of these keys, the upstream block wins and the local entry becomes
     * dead weight to delete, exactly the previous bridge's contract.
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
            $meta = self::loadSchemaMeta();
            foreach (self::SUPPLEMENTAL_META as $key => $block) {
                if (!array_key_exists($key, $meta)) {
                    $meta[$key] = $block;
                }
            }
            self::$schemaMeta = $meta;
        }

        return self::$schemaMeta;
    }

    /**
     * Hub-local meta projection for the W5 Phase-6 keys, merged UNDER the
     * vendored schema (see {@see schemaMeta()} for the yield contract).
     *
     * Bounds here are NOT advisory: putSettings() validates incoming values
     * against minimum/maximum (the F-09 residual — the vendored schema's
     * bounds were never enforced server-side — is fixed for these keys and
     * retroactively covers any future schema-shipped bounds, since the
     * validation reads the merged projection).
     *
     * @var array<string, array<string, mixed>>
     */
    private const array SUPPLEMENTAL_META = [
        'hub.maintenance_mode' => [
            'label'      => 'Maintenance mode',
            'helpText'   => 'When enabled, the hub answers 503 provider_unavailable for API '
                . 'traffic. Auth, hub-settings, admin settings/restart and /health stay '
                . 'reachable so the off-switch is never locked behind the gate; the /app SPA '
                . 'shell keeps serving. Live within ~1s per worker; a settings outage fails '
                . 'OPEN to the HUB_MAINTENANCE_MODE boot flag.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'hub',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => false,
            'secret'     => false,
            'restart'    => false,
        ],
        'federation.enabled' => [
            'label'      => 'Enable hub federation',
            'helpText'   => 'Master switch for hub-to-hub federation. Off: peer mutations 409 '
                . 'provider.not_configured, inbound handshake/DATA frames are refused or dropped, '
                . 'and outbound dials stop. Reads and hub-config CRUD stay open; the :8805 '
                . 'listener stays bound until a restart. Re-enabling instantly re-admits traffic '
                . 'on links that survived the off-window, and re-establishes links that dropped '
                . 'while off within <=60s automatically (the reconnect chain parks at the <=60s '
                . 'backoff cap during the disabled window and re-checks on that cadence — no '
                . 'restart or explicit trigger needed). The same cap also throttles the '
                . 'disabled-window re-check cadence; a hub-config save / peer relay toggle still '
                . 'dials immediately.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'federation',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => true,
            'secret'     => false,
            'restart'    => false,
        ],
        'requests.auto_approve' => [
            'label'      => 'Auto-approve media requests',
            'helpText'   => 'Newly filed requests are approved immediately after the pending '
                . 'row lands (best-effort: any approval failure — arr disabled, down, quota — '
                . 'leaves the request pending exactly like the manual queue). Live per create.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'requests',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => false,
            'secret'     => false,
            'restart'    => false,
        ],
        'invite.default_expiry_seconds' => [
            'label'      => 'Default invite expiry (seconds)',
            'helpText'   => 'Invite lifetime applied ONLY when the creator omits expires_in '
                . '(an explicit body value always wins). 0 = never expire; 604800 = the 7-day '
                . 'default invites shipped with before this setting. Live per create.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'invite',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => 0,
            'maximum'    => 31536000,
            'default'    => 604800,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.max_servers_per_user' => [
            'label'      => 'Max servers per user',
            'helpText'   => 'Per-account server quota checked at claim time against ALL owned '
                . 'servers (any status). 0 = unlimited, the shipped behavior. Rare concurrent '
                . 'claims of DIFFERENT codes may overshoot by the concurrency count — a quota '
                . 'guard, not a billing-strict invariant.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => 0,
            'maximum'    => 1000,
            'default'    => 0,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.max_users_per_server' => [
            'label'      => 'Max collaborators per server',
            'helpText'   => 'Distinct active collaborator users per server, enforced on share '
                . 'create and invite redeem. 0 = unlimited, the shipped behavior. Returning '
                . 'collaborators (reactivation, or already active on another library of the '
                . 'same server) never count as new.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => 0,
            'maximum'    => 10000,
            'default'    => 0,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.metrics.enabled' => [
            'label'      => 'Enable metrics collection',
            'helpText'   => 'Read once per worker at boot (HTTP/relay workers arm their '
                . 'collectors in onWorkerStart); mid-lifetime flips apply to NEW workers — use '
                . 'the graceful restart to recycle. Metrics flush/prune stop when every worker '
                . 'boots with this off.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => true,
            'secret'     => false,
            'restart'    => true,
        ],
        'server.metrics.retention_days' => [
            'label'      => 'Metrics retention (days)',
            'helpText'   => 'Rollup/connections older than this are pruned on the relay '
                . 'worker flush ticks. Live: re-read every prune, so shrinking the window '
                . 'deletes within one tick. Clamped 1..3650 at read.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => 1,
            'maximum'    => 3650,
            'default'    => 7,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.relay.reconnect_drain_grace_seconds' => [
            'label'      => 'Reconnect drain grace (seconds)',
            'helpText'   => 'Seconds a displaced incumbent tunnel keeps draining in-flight '
                . 'requests after a validated reconnect. 0 = immediate hard displacement. '
                . 'Live at each displacement event; clamped 0..300 at read.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => 0,
            'maximum'    => 300,
            'default'    => 5.0,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.rate_limit' => [
            'label'      => 'Rate-limit overrides (JSON)',
            'helpText'   => 'Sparse override blob over config/server.php rate_limit, e.g. '
                . '{"login":{"max":10},"cap":5000}. Known surface keys: login, proxy, '
                . 'heartbeat, jwks, relay_connect, client_mount, mcp, alexa, signup, cap. '
                . 'Values must be integers > 0 to count; unknown keys are ignored. Applied '
                . 'per worker at boot — use the graceful restart to recycle. Tightening '
                . 'signup/login limits while locked out is not possible: limits exist to '
                . 'protect the hub, and the exempt maintenance paths do not bypass them.',
            'helpLinks'  => [],
            'tier'       => 'advanced',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => [],
            'secret'     => false,
            'restart'    => true,
        ],
        'server.arr.sonarr.enabled' => [
            'label'      => 'Sonarr integration enabled',
            'helpText'   => 'Overrides HUB_SONARR_ENABLED live at each approval. The API key '
                . 'stays env-only (HUB_SONARR_API_KEY) and is never exposed through the '
                . 'settings surface — see the DENIED_KEYS admission law.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => false,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.arr.sonarr.url' => [
            'label'      => 'Sonarr base URL',
            'helpText'   => 'Live at each approval. Admin-trust boundary: this URL is fetched '
                . 'server-side from the settings write surface (same trust class as other '
                . 'admin-controlled URLs); api_key never leaves the env.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => 'http://localhost:8989',
            'secret'     => false,
            'restart'    => false,
        ],
        'server.arr.radarr.enabled' => [
            'label'      => 'Radarr integration enabled',
            'helpText'   => 'Overrides HUB_RADARR_ENABLED live at each approval. The API key '
                . 'stays env-only (HUB_RADARR_API_KEY) and is never exposed through the '
                . 'settings surface — see the DENIED_KEYS admission law.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => false,
            'secret'     => false,
            'restart'    => false,
        ],
        'server.arr.radarr.url' => [
            'label'      => 'Radarr base URL',
            'helpText'   => 'Live at each approval. Admin-trust boundary: this URL is fetched '
                . 'server-side from the settings write surface (same trust class as other '
                . 'admin-controlled URLs); api_key never leaves the env.',
            'helpLinks'  => [],
            'tier'       => 'standard',
            'group'      => 'server',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => null,
            'maximum'    => null,
            'default'    => 'http://localhost:7878',
            'secret'     => false,
            'restart'    => false,
        ],
    ];

    /**
     * Read and decode the shared `hub-settings.schema.json` and project
     * every property into a per-key meta block.
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
                'minimum'    => isset($def['minimum']) && is_numeric($def['minimum'])
                    ? (float) $def['minimum']
                    : null,
                'maximum'    => isset($def['maximum']) && is_numeric($def['maximum'])
                    ? (float) $def['maximum']
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
