<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Workerman\MySQL\Connection;

/**
 * Hub-wide settings store (Step H.5a).
 *
 * Persists hub-admin-editable *overrides* on top of the read-only
 * `config/*.php` files. The runtime contract is identical to the server
 * {@see \Phlix\Admin\SettingsRepository}:
 *
 *   - **default**  — the value baked into `config/<file>.php` (boot-time).
 *   - **override** — a row in the `hub_settings` table written via the
 *     hub admin settings API (H.5b).
 *   - **effective** — the override when present, else the default.
 *
 * Keys are *dotted*: the first segment names the config file and the
 * remaining segments walk into the array it returns. For example
 * `server.enrollment_ttl` resolves the `enrollment_ttl` key of
 * `config/server.php`.
 *
 * Storage notes:
 *   - `setting_value` is always stored as text; `value_type`
 *     (string|int|bool|float|json) records how to decode it back into a
 *     PHP value. {@see self::encode()} / {@see self::decode()}.
 *   - Upserts use `INSERT ... ON DUPLICATE KEY UPDATE` against the
 *     `uq_hub_settings_key` unique index, mirroring the server
 *     {@see \Phlix\Admin\SettingsRepository} pattern.
 *
 * Database access is exclusively through the async
 * {@see \Workerman\MySQL\Connection} client with parameterised queries —
 * never PDO/mysqli, never string-interpolated SQL — per the hub runtime
 * rules.
 *
 * @package Phlix\Hub\Hub
 * @since   H.5a (Hub settings store infrastructure)
 */
class HubSettingsRepository
{
    /**
     * Allowed setting keys and their value types.
     *
     * Maps dotted setting keys to their serialisable type.
     * The controller (H.5b) validates incoming keys against this list;
     * the repository itself does not enforce it (keeps it simple and
     * aligned with the server SettingsRepository pattern).
     *
     * @var array<string, string>
     */
    public const ALLOWED_KEYS = [
        // config/server.php
        'server.enrollment_ttl'       => 'int',
        'server.relay_ping_interval'   => 'int',
        'server.max_servers_per_user'  => 'int',
        'server.public_domain'        => 'string',
        // config/auth.php
        'auth.access_token_ttl'       => 'int',
        'auth.refresh_token_ttl'      => 'int',
        // config/logger.php
        'logger.level'                => 'string',
        'logger.channels'            => 'json',
    ];

    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /** @var string Absolute or relative directory holding `config/*.php`. */
    private string $configDir;

    /**
     * In-process cache of decoded config files, keyed by file segment.
     * Bounded by the (small, fixed) number of config files referenced by
     * the allow-list, so it is not an unbounded resident-memory leak.
     * Shared-default config (not request data), so caching on the
     * instance is safe — the repository is request-scoped via the
     * container.
     *
     * @var array<string, array<array-key, mixed>|null>
     */
    private array $configCache = [];

    /**
     * @param Connection  $db        Workerman MySQL connection.
     * @param string|null $configDir Directory containing `config/*.php`.
     *                               Defaults to `config` relative to the
     *                               project root. Injectable so tests can
     *                               point at fixtures.
     *
     * @since H.5a
     */
    public function __construct(Connection $db, ?string $configDir = null)
    {
        $this->db = $db;
        $this->configDir = $configDir ?? dirname(__DIR__, 2) . '/config';
    }

    /**
     * Read the raw override row for a single key.
     *
     * @param string $key Dotted setting key.
     *
     * @return array{value: mixed, value_type: string}|null Decoded override
     *         and its declared type, or null when no override exists.
     *
     * @since H.5a
     */
    public function getOverride(string $key): ?array
    {
        $rows = $this->db->query(
            'SELECT setting_value, value_type FROM hub_settings WHERE setting_key = ?',
            [$key],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }

        $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : 'string';
        $raw  = is_string($row['setting_value'] ?? null) ? $row['setting_value'] : '';

        return [
            'value'      => self::decode($raw, $type),
            'value_type' => $type,
        ];
    }

    /**
     * Read every override currently stored, keyed by setting key.
     *
     * @return array<string, mixed> Map of setting_key → decoded override.
     *
     * @since H.5a
     */
    public function getAllOverrides(): array
    {
        $rows = $this->db->query(
            'SELECT setting_key, setting_value, value_type FROM hub_settings',
        );

        $out = [];
        if (!is_array($rows)) {
            return $out;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['setting_key'] ?? null)) {
                continue;
            }
            $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : 'string';
            $raw  = is_string($row['setting_value'] ?? null) ? $row['setting_value'] : '';
            $out[$row['setting_key']] = self::decode($raw, $type);
        }

        return $out;
    }

    /**
     * Persist (insert or update) an override.
     *
     * The caller is responsible for validating that `$key` is allowed and
     * that `$value` matches `$valueType` (the admin controller does this
     * against its typed allow-list). This method only serialises and upserts.
     *
     * @param string $key       Dotted setting key (must be unique).
     * @param mixed  $value     PHP value to persist.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @since H.5a
     */
    public function set(string $key, mixed $value, string $valueType): void
    {
        $id      = $this->generateUuid();
        $encoded = self::encode($value, $valueType);

        // Upsert on the unique `setting_key` index.
        $sql = 'INSERT INTO hub_settings (id, setting_key, setting_value, value_type)'
            . ' VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE'
            . ' setting_value = VALUES(setting_value),'
            . ' value_type = VALUES(value_type)';

        $this->db->query($sql, [$id, $key, $encoded, $valueType]);
    }

    /**
     * Resolve the *default* (config-file) value for a dotted key.
     *
     * @param string $key Dotted setting key.
     *
     * @return mixed The config value, or null when the file/path is absent.
     *
     * @since H.5a
     */
    public function getDefault(string $key): mixed
    {
        $segments = explode('.', $key);
        $file     = array_shift($segments);
        if ($file === null || $file === '') {
            return null;
        }

        $config = $this->loadConfig($file);
        if ($config === null) {
            return null;
        }

        $cursor = $config;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Effective value for a single key: override when present, else default.
     *
     * @param string $key Dotted setting key.
     *
     * @return mixed The effective value.
     *
     * @since H.5a
     */
    public function getEffective(string $key): mixed
    {
        $override = $this->getOverride($key);
        if ($override !== null) {
            return $override['value'];
        }

        return $this->getDefault($key);
    }

    /**
     * Build the effective-value map for a known set of keys, plus the list
     * of keys that are currently overridden.
     *
     * The hub admin controller (H.5b) passes its typed allow-list here so
     * the response only ever exposes curated keys (never arbitrary config
     * internals).
     *
     * @param list<string> $keys Allow-listed dotted keys.
     *
     * @return array{values: array<string, mixed>, overridden: list<string>}
     *
     * @since H.5a
     */
    public function getEffectiveMany(array $keys): array
    {
        $overrides = $this->getAllOverrides();

        $values     = [];
        $overridden = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $overrides)) {
                $values[$key] = $overrides[$key];
                $overridden[] = $key;
            } else {
                $values[$key] = $this->getDefault($key);
            }
        }

        return ['values' => $values, 'overridden' => $overridden];
    }

    /**
     * Load and cache a single `config/<file>.php`.
     *
     * @param string $file Config file segment (no extension), e.g. `server`.
     *
     * @return array<array-key, mixed>|null Decoded config, or null when
     *         missing / not an array.
     */
    private function loadConfig(string $file): ?array
    {
        if (array_key_exists($file, $this->configCache)) {
            return $this->configCache[$file];
        }

        // Jail the lookup to the config directory: reject any traversal in
        // the key's first segment so a crafted setting_key cannot include
        // arbitrary PHP files. Only simple file names are permitted.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $file)) {
            return $this->configCache[$file] = null;
        }

        $path = $this->configDir . '/' . $file . '.php';
        if (!is_file($path)) {
            return $this->configCache[$file] = null;
        }

        /** @psalm-suppress UnresolvableInclude $path is a jailed config file resolved at runtime */
        $loaded = @include $path;

        return $this->configCache[$file] = is_array($loaded) ? $loaded : null;
    }

    /**
     * Serialise a PHP value to its text representation for storage.
     *
     * @param mixed  $value     Value to encode.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return string Text form suitable for the `setting_value` column.
     */
    private static function encode(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'bool'  => ($value ? '1' : '0'),
            'int'   => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => (string) (float) (is_numeric($value) ? $value : 0),
            'json'  => (string) json_encode($value),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }

    /**
     * Decode a stored text value back into a PHP value per its type.
     *
     * @param string $raw       Stored text value.
     * @param string $valueType One of string|int|bool|float|json.
     *
     * @return mixed The decoded PHP value.
     */
    private static function decode(string $raw, string $valueType): mixed
    {
        return match ($valueType) {
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'json'  => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * Generate a random UUID v4.
     *
     * Mirrors the inline sprintf pattern used by other hub classes
     * (per the repo's no-UUID-library rule).
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
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
            mt_rand(0, 0xffff)
        );
    }
}
