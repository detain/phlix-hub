<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Common\Database\ConnectionPool;
use Throwable;

/**
 * HTTP-surface maintenance-mode gate for the hub (`hub.maintenance_mode`).
 *
 * When the setting is on, the hub answers 503 (code `provider_unavailable`)
 * for API traffic while keeping the escape-hatch surface reachable, so an
 * operator can always sign in, flip the toggle back off, and (for a wedged
 * runtime) trigger the graceful restart. The exemption list below is the
 * complete audit of what stays live during maintenance:
 *
 * - `/health`                — monitoring & container healthchecks; alerting
 *                             must keep seeing the process, just degraded.
 * - `/api/v1/auth/`          — register/login/logout/refresh: without these
 *                             the settings page is unreachable, and the only
 *                             way to turn maintenance off via the API would
 *                             be the shell. (Signup itself stays governed by
 *                             `auth.signups_disabled`.)
 * - `/api/v1/me/hub-settings` and `/api/v1/admin/settings`
 *                            — the off-switch itself (GET+PUT), admin-gated.
 * - `/api/v1/admin/restart`  — the graceful-restart control for changes that
 *                             need a worker recycle while blocked routes are
 *                             down.
 *
 * Non-API paths (the `/app` SPA shell, hashed `/assets`) are NOT blocked:
 * the static fast-path serves them before this gate runs, and the SPA is how
 * an admin reaches the settings page. A hub under maintenance still boots
 * its own console by design.
 *
 * Read posture: LIVE, with a one-second per-worker memo. Live means an admin
 * PUT is observed by every HTTP worker within one second — the memo bounds
 * the hot-path cost (one unique-index SELECT/second/worker) without ever
 * serving a stale-forever value; resident-process law forbids caches that
 * never refresh, not caches that tick. On any settings outage the gate fails
 * OPEN to the boot-time default (env `HUB_MAINTENANCE_MODE`, config
 * `config/hub.php`): maintenance is an operator posture, and a DB blip must
 * not silently take a healthy hub down — the inverse of the signups gate,
 * whose fallback direction is documented at its own call site.
 *
 * @package Phlix\Hub\Hub
 */
final class MaintenanceGate
{
    /**
     * Path prefixes that stay reachable while maintenance mode is on.
     *
     * @var list<string>
     */
    public const EXEMPT_PREFIXES = [
        '/health',
        '/api/v1/auth/',
        '/api/v1/me/hub-settings',
        '/api/v1/admin/settings',
        '/api/v1/admin/restart',
    ];

    /** Memo TTL in seconds for the live setting read. */
    private const MEMO_TTL_SECONDS = 1;

    private ?HubSettingsRepository $repository = null;

    private int $memoExpiresAt = 0;

    private bool $memoEnabled;

    /**
     * @param bool           $bootEnabled Value from env/config at wire time:
     *                                    the answer when no store is reachable.
     * @param callable():int|null $clock  Injectable monotone-ish second clock
     *                                    (tests pin it; production uses time()).
     */
    public function __construct(
        private readonly bool $bootEnabled = false,
        private readonly mixed $clock = null,
    ) {
        $this->memoEnabled = $bootEnabled;
    }

    /**
     * Whether this request path must be refused while maintenance is on.
     *
     * Exempt paths are answered purely from the static allow-list — no store
     * read at all — so the off-switch can never depend on the same DB round
     * trip it exists to bypass.
     */
    public function shouldBlock(string $path): bool
    {
        if (self::isExempt($path)) {
            return false;
        }

        return $this->enabled();
    }

    /**
     * Static exemption check (no I/O) — public for tests and reuse.
     */
    public static function isExempt(string $path): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current effective maintenance flag with a one-second memo.
     */
    public function enabled(): bool
    {
        $now = $this->now();
        if ($now < $this->memoExpiresAt) {
            return $this->memoEnabled;
        }

        $this->memoEnabled    = $this->readLive();
        $this->memoExpiresAt  = $now + self::MEMO_TTL_SECONDS;

        return $this->memoEnabled;
    }

    /**
     * Live read against hub_settings, failing open to the boot default.
     */
    private function readLive(): bool
    {
        try {
            if (!$this->repository instanceof HubSettingsRepository) {
                // Uninitialised pool (unit tests, CLI): the boot flag IS the
                // effective gate. Checked explicitly — getConnection() on a
                // dead pool warns rather than throws.
                if (ConnectionPool::getInstance() === null) {
                    return $this->bootEnabled;
                }
                $this->repository = new HubSettingsRepository(ConnectionPool::getConnection('mysql'));
            }

            /** @var mixed $value */
            $value = $this->repository->getEffective('hub.maintenance_mode');

            return is_bool($value) ? $value : $this->bootEnabled;
        } catch (Throwable) {
            return $this->bootEnabled;
        }
    }

    private function now(): int
    {
        if (is_callable($this->clock)) {
            /** @var int $tick */
            $tick = ($this->clock)();

            return $tick;
        }

        return time();
    }
}
