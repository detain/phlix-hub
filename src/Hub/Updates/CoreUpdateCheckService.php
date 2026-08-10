<?php

/**
 * Phlix hub component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub\Updates;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Version;
use Throwable;
use Workerman\Timer;

/**
 * Core (hub application) update check — S75 / updates.md #48.
 *
 * Fetches the repository's root `VERSION` marker and compares it against the
 * compiled {@see Version::VERSION} constant. The outcome is PERSISTED in
 * `hub_settings` so the admin HTTP surface can answer
 * `GET /api/v1/admin/updates/status` from the database with **no outbound I/O
 * inside an HTTP handler** — the fetch happens on the maintenance worker
 * ({@see CoreUpdateCheckWorker}), never on a request.
 *
 * ## Persistence
 *
 * Three `hub_settings` rows carry the check RESULT. They are deliberately NOT
 * in {@see HubSettingsRepository::ALLOWED_KEYS}: that allow-list gates what an
 * admin may EDIT through the generic settings API, and these are outputs, not
 * knobs. They are read back through `getOverride()` (not `getEffective()`), so
 * they need no `config/*.php` default:
 *
 *  - `updates.latest_version`   — last successfully fetched marker.
 *  - `updates.last_checked_at`  — unix time of the last COMPLETED check.
 *  - `updates.last_error`       — error text of the last failed check (`''` when clean).
 *
 * The one genuine setting, `updates.check_enabled`, IS resolved as an
 * *effective* value (override → `config/updates.php` default), so an admin
 * toggle applies to the very next poll with no restart.
 *
 * ## What this class will never do
 *
 * It does not, and must not, apply an update: no git, no composer, no
 * systemctl, no shelling out at all. {@see status()} surfaces
 * `updateCommand` — a copy-to-clipboard one-liner the operator runs on the box
 * themselves.
 *
 * ## S308 — the fetch is DUE-GATED, SINGLE-FLIGHT and DEADLINE-BOUNDED
 *
 * S75 drove this from a boot catch-up: {@see CoreUpdateCheckWorker::start()}
 * fetched the marker synchronously while the maintenance worker was still
 * booting. Measured in a container on 2026-08-10, that had two failure
 * geometries, both on the boot path:
 *
 *  - **DNS answers, the connect fails** (`--add-host raw.githubusercontent.com:
 *    127.0.0.1`): two `stream_socket_client(): Unable to connect to
 *    tcp://raw.githubusercontent.com:443 (Connection refused)` PHP warnings on
 *    stdout per check — one from `ConnectionPool::fetch()`'s `connect()` and one
 *    from `Client::process()`'s `reconnect()` — for a single logical failure;
 *  - **DNS blackholes** (a bridge with no route out, i.e. any egress-filtered or
 *    air-gapped install): the Swoole-hooked resolver never returns, `check()`
 *    never completes, `start()` never returns, and the daily poll timer is
 *    therefore NEVER ARMED. The feature is dead AND a boot coroutine is parked.
 *
 * So the drive moved off the boot path onto a 60-second sweep
 * ({@see CoreUpdateCheckWorker}) and three properties were added HERE, where the
 * check's lifecycle actually lives:
 *
 *  1. {@see isDue()} / {@see checkIfDue()} — a fetch happens only when a poll
 *     interval has genuinely elapsed since `updates.last_checked_at`. A hub that
 *     restarts every ten minutes now checks once a day, not every ten minutes.
 *  2. **Single flight** — a second `check()` while one is outstanding is refused.
 *     Without it a 60-second sweep against a hung transport would launch a new
 *     coroutine every minute, forever.
 *  3. **A deadline** — a one-shot {@see Timer} records a timeout error if the
 *     fetcher has not called back in `$deadlineSeconds`. The vendor client's own
 *     `timeout` option does NOT bound the hooked DNS phase for the calling
 *     coroutine (measured: > 60 s with no callback), so without this the
 *     single-flight guard above would wedge the feature until the next restart.
 *
 * Failures are also logged ONCE: the same error text repeating (an air-gapped
 * install answers identically every day, forever) drops to `debug` after the
 * first `warning`, and a success re-arms the warning.
 *
 * @package Phlix\Hub\Hub\Updates
 * @since   S75 (core update check)
 */
final class CoreUpdateCheckService
{
    /** Effective setting: master switch for the periodic check. */
    public const SETTING_CHECK_ENABLED = 'updates.check_enabled';

    /** Persisted result: last successfully fetched marker version. */
    public const STATE_LATEST_VERSION = 'updates.latest_version';

    /** Persisted result: unix timestamp of the last completed check. */
    public const STATE_LAST_CHECKED_AT = 'updates.last_checked_at';

    /** Persisted result: error text of the last failed check (empty string = clean). */
    public const STATE_LAST_ERROR = 'updates.last_error';

    /**
     * Default seconds before an unanswered fetch is recorded as a timeout.
     *
     * Deliberately LONGER than `config/updates.php`'s `timeout_seconds` (the
     * value handed to the vendor HTTP client): when the transport can report for
     * itself, its own error is the better message, and this deadline is only the
     * backstop for the case it cannot — a hooked DNS resolution that never
     * returns.
     */
    public const DEFAULT_DEADLINE_SECONDS = 20;

    /**
     * Accepted shape of a version marker: semver, optional leading `v`,
     * optional pre-release / build metadata.
     */
    private const MARKER_PATTERN = '/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/';

    /**
     * Completion callback for the in-flight {@see check()}, if any.
     *
     * Single-slot and cleared as soon as it fires: the only production caller
     * is the count=1 maintenance worker's timer, which never has two checks in
     * flight, so this cannot grow without bound.
     *
     * @var (callable(CoreUpdateStatus):void)|null
     */
    private $pendingCompletion = null;

    /**
     * True between issuing a fetch and recording its outcome (S308 single
     * flight). One bool, not a collection: it can never grow.
     */
    private bool $inFlight = false;

    /** Timer id of the in-flight fetch's deadline, or null when none is armed. */
    private ?int $deadlineTimerId = null;

    /**
     * Error text of the last failure ESCALATED to `warning`, so an identical
     * repeat can be demoted to `debug` (S308 "logged once"). One nullable
     * string; cleared on success.
     */
    private ?string $lastLoggedError = null;

    /**
     * @param HubSettingsRepository          $settings        Hub settings store (defaults + overrides + result rows).
     * @param VersionMarkerFetcherInterface  $fetcher         Non-blocking marker fetcher.
     * @param StructuredLogger               $logger          Hub logger.
     * @param string                         $markerUrl       Absolute URL of the remote `VERSION` marker.
     * @param string                         $updateCommand   Copy-to-clipboard update command.
     * @param string                         $currentVersion  Compiled version; overridable for tests.
     * @param int                            $deadlineSeconds Seconds before an unanswered fetch is recorded
     *                                                        as a timeout (S308). `<= 0` disables the
     *                                                        deadline.
     */
    public function __construct(
        private readonly HubSettingsRepository $settings,
        private readonly VersionMarkerFetcherInterface $fetcher,
        private readonly StructuredLogger $logger,
        private readonly string $markerUrl,
        private readonly string $updateCommand,
        private readonly string $currentVersion = Version::VERSION,
        private readonly int $deadlineSeconds = self::DEFAULT_DEADLINE_SECONDS,
    ) {
    }

    /**
     * Effective `updates.check_enabled` (override → config default → true).
     *
     * Fail-OPEN: an absent/unreadable config resolves to enabled, because the
     * failure mode of checking when you meant not to is a single HTTP GET a
     * day, while the failure mode of the inverse is an operator who never
     * learns a security release shipped.
     *
     * @return bool
     */
    public function isCheckEnabled(): bool
    {
        /** @var mixed $value */
        $value = $this->settings->getEffective(self::SETTING_CHECK_ENABLED);
        if ($value === null) {
            return true;
        }

        return (bool) $value;
    }

    /**
     * Persist the `updates.check_enabled` override.
     *
     * @param bool $enabled New value.
     *
     * @return void
     */
    public function setCheckEnabled(bool $enabled): void
    {
        $this->settings->set(self::SETTING_CHECK_ENABLED, $enabled, 'bool');
    }

    /**
     * Run one check: fetch the marker, compare, persist, then report.
     *
     * Non-blocking — the fetch is handed to {@see VersionMarkerFetcherInterface}
     * and `$onComplete` fires on the event loop once the response (or the
     * error) arrives. When the check is DISABLED nothing is fetched and nothing
     * is persisted; `$onComplete` still receives the current persisted status,
     * so a caller never has to special-case the toggle.
     *
     * @param callable(CoreUpdateStatus):void|null $onComplete Optional completion callback.
     *
     * @return void
     */
    public function check(?callable $onComplete = null): void
    {
        if (!$this->isCheckEnabled()) {
            $this->logger->debug('Updates: core update check is disabled, skipping fetch');
            $this->complete($onComplete);
            return;
        }

        // S308 SINGLE FLIGHT. The drive is a 60-second sweep; a transport that
        // never answers (a hooked DNS resolution on an egress-filtered box does
        // not return at all) would otherwise accumulate one parked coroutine a
        // minute for the life of the process.
        if ($this->inFlight) {
            $this->logger->debug('Updates: a core update check is already in flight, skipping this sweep', [
                'url' => $this->markerUrl,
            ]);
            $this->complete($onComplete);
            return;
        }

        // ORDER MATTERS: the completion slot is armed BEFORE the fetch is
        // issued. A fetcher is free to call back synchronously (every test
        // double does, and a cached/failed-fast transport may too), in which
        // case record() -> complete() runs inside the call below — arming the
        // slot afterwards would silently drop the callback.
        $this->pendingCompletion = $onComplete;
        $this->inFlight          = true;
        $this->armDeadline();

        try {
            $this->fetcher->fetch($this->markerUrl, function (?string $body, ?string $error): void {
                $this->record($body, $error);
            });
        } catch (Throwable $e) {
            // A synchronous throw must NOT escape while `inFlight` is set: the
            // guard above would then refuse every future sweep and the check
            // would be dead until the next restart. Record it as the failed
            // check it is; the caller gets its completion as usual.
            $this->record(null, $e->getMessage());
        }
    }

    /**
     * Is a poll due? True when the marker has never been fetched, when the
     * configured interval has elapsed since the last COMPLETED check, or when
     * the stored timestamp is in the future (a clock that moved backwards must
     * not silence the check for years).
     *
     * @param int $intervalSeconds Steady-state poll interval, seconds.
     *
     * @return bool
     */
    public function isDue(int $intervalSeconds): bool
    {
        $last = $this->readStateInt(self::STATE_LAST_CHECKED_AT);
        if ($last === null) {
            return true;
        }

        $now = time();
        if ($last > $now) {
            return true;
        }

        return ($now - $last) >= max(0, $intervalSeconds);
    }

    /**
     * Run one check, but only if {@see isDue()} says a poll interval has
     * elapsed. This is what the maintenance sweep calls: it is safe at any
     * cadence, because the interval — not the sweep — decides when the network
     * is touched.
     *
     * @param int                                  $intervalSeconds Steady-state poll interval, seconds.
     * @param callable(CoreUpdateStatus):void|null $onComplete      Optional completion callback.
     *
     * @return bool True when a check was started, false when none was due.
     */
    public function checkIfDue(int $intervalSeconds, ?callable $onComplete = null): bool
    {
        if (!$this->isDue($intervalSeconds)) {
            $this->complete($onComplete);
            return false;
        }

        $this->check($onComplete);

        return true;
    }

    /**
     * Deadline callback: record the in-flight fetch as timed out.
     *
     * Public because the production {@see Timer} holds `[$this, 'expireInFlightCheck']`
     * — a test drives the callback Workerman is actually holding rather than a
     * private seam. A no-op when nothing is in flight, which is the normal case
     * (the deadline is cancelled the moment a fetch reports).
     *
     * @return void
     */
    public function expireInFlightCheck(): void
    {
        if (!$this->inFlight) {
            return;
        }

        // The one-shot has fired; there is nothing left to cancel.
        $this->deadlineTimerId = null;

        $this->record(null, 'update check: no response within ' . $this->deadlineSeconds . ' seconds');
    }

    /**
     * Current status, assembled from persisted state. Performs NO network I/O,
     * which is what makes it safe to call from an HTTP handler.
     *
     * @return CoreUpdateStatus
     */
    public function status(): CoreUpdateStatus
    {
        $latest    = $this->readStateString(self::STATE_LATEST_VERSION);
        $checkedAt = $this->readStateInt(self::STATE_LAST_CHECKED_AT);
        $error     = $this->readStateString(self::STATE_LAST_ERROR);

        return new CoreUpdateStatus(
            $this->currentVersion,
            $latest,
            $latest !== null && self::isNewer($latest, $this->currentVersion),
            $this->isCheckEnabled(),
            $checkedAt,
            $error,
            $this->updateCommand,
        );
    }

    /**
     * Strict "is `$candidate` newer than `$current`" comparison.
     *
     * Both sides are normalised (trimmed, optional leading `v` removed) and
     * must match {@see self::MARKER_PATTERN}; anything else is NOT newer, so a
     * garbage marker can never raise a false "update available".
     *
     * @param string $candidate Remote marker version.
     * @param string $current   Compiled version.
     *
     * @return bool
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $left  = self::normalise($candidate);
        $right = self::normalise($current);
        if ($left === null || $right === null) {
            return false;
        }

        return version_compare($left, $right, '>');
    }

    /**
     * Validate + normalise a raw marker body into a bare semver string.
     *
     * @param string $raw Raw marker text (may carry a trailing newline / `v` prefix).
     *
     * @return string|null Normalised semver, or null when the input is not a version.
     */
    public static function normalise(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $matches = [];
        if (preg_match(self::MARKER_PATTERN, $trimmed, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Persist the outcome of one completed fetch and fire the pending
     * completion callback.
     *
     * @param string|null $body  Marker body when the fetch succeeded.
     * @param string|null $error Error text when it did not.
     *
     * @return void
     */
    private function record(?string $body, ?string $error): void
    {
        $now = time();

        // Release the single-flight guard FIRST, and unconditionally: every
        // return path below must leave the next sweep able to fetch, including
        // the ones that throw on the way out.
        $this->inFlight = false;
        $this->disarmDeadline();

        try {
            if ($error !== null) {
                $this->settings->set(self::STATE_LAST_ERROR, $error, 'string');
                $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');
                $this->logFailure('Updates: core update check failed', $error, [
                    'url'   => $this->markerUrl,
                    'error' => $error,
                ]);
                $this->complete();
                return;
            }

            $latest = self::normalise((string) $body);
            if ($latest === null) {
                $message = 'update check: version marker is not a semver string';
                $this->settings->set(self::STATE_LAST_ERROR, $message, 'string');
                $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');
                $this->logFailure('Updates: core update check returned an unusable marker', $message, [
                    'url' => $this->markerUrl,
                ]);
                $this->complete();
                return;
            }

            $this->settings->set(self::STATE_LATEST_VERSION, $latest, 'string');
            $this->settings->set(self::STATE_LAST_ERROR, '', 'string');
            $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');

            // A success re-arms the warning: the NEXT failure, even an identical
            // one, is escalated again rather than silently demoted forever.
            $this->lastLoggedError = null;

            $this->logger->info('Updates: core update check completed', [
                'current'          => $this->currentVersion,
                'latest'           => $latest,
                'update_available' => self::isNewer($latest, $this->currentVersion),
            ]);
        } catch (Throwable $e) {
            // A DB hiccup on a background poll must never escape into the timer.
            $this->logger->error('Updates: failed to persist core update check result', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->complete();
    }

    /**
     * Log a failed check ONCE (S308).
     *
     * The first occurrence of a given error text is a `warning`; an identical
     * repeat is a `debug` carrying `repeat => true`. An air-gapped install
     * produces the same error on every poll forever, and a daily warning about a
     * condition the operator has already chosen is noise that trains people to
     * ignore the channel.
     *
     * @param string               $message Log message.
     * @param string               $error   Error text used as the dedupe key.
     * @param array<string, mixed> $context Log context.
     *
     * @return void
     */
    private function logFailure(string $message, string $error, array $context): void
    {
        if ($this->lastLoggedError === $error) {
            $context['repeat'] = true;
            $this->logger->debug($message, $context);
            return;
        }

        $this->lastLoggedError = $error;
        $this->logger->warning($message, $context);
    }

    /**
     * Arm the in-flight fetch's deadline.
     *
     * `Timer::add()` throws outside a Workerman runtime
     * (`Timer.php` — `if (!Worker::getAllWorkers()) throw`). In production, on
     * the maintenance worker, it never does; the guard is what keeps a
     * `CoreUpdateCheckService` usable in a plain PHP process (a CLI script, a
     * unit test) where the worst case is simply an unbounded fetch that the
     * caller already had before S308.
     *
     * @return void
     */
    private function armDeadline(): void
    {
        $this->deadlineTimerId = null;
        if ($this->deadlineSeconds <= 0) {
            return;
        }

        try {
            $this->deadlineTimerId = Timer::add(
                $this->deadlineSeconds,
                [$this, 'expireInFlightCheck'],
                [],
                false,
            );
        } catch (Throwable $e) {
            $this->deadlineTimerId = null;
            $this->logger->debug('Updates: no Workerman runtime, the update-check deadline is not armed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel the in-flight fetch's deadline, if one is armed.
     *
     * @return void
     */
    private function disarmDeadline(): void
    {
        if ($this->deadlineTimerId === null) {
            return;
        }

        $timerId = $this->deadlineTimerId;
        $this->deadlineTimerId = null;

        try {
            Timer::del($timerId);
        } catch (Throwable $e) {
            $this->logger->debug('Updates: could not cancel the update-check deadline timer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fire (and clear) the pending completion callback with a fresh status.
     *
     * @param (callable(CoreUpdateStatus):void)|null $override Callback to use instead of the pending one.
     *
     * @return void
     */
    private function complete(?callable $override = null): void
    {
        $callback = $override ?? $this->pendingCompletion;
        $this->pendingCompletion = null;
        if ($callback === null) {
            return;
        }

        try {
            $callback($this->status());
        } catch (Throwable $e) {
            $this->logger->error('Updates: core update check completion callback failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read a persisted string state row, or null when unset/blank.
     *
     * @param string $key Dotted state key.
     *
     * @return string|null
     */
    private function readStateString(string $key): ?string
    {
        $row = $this->settings->getOverride($key);
        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row['value'];
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Read a persisted integer state row, or null when unset/zero.
     *
     * @param string $key Dotted state key.
     *
     * @return int|null
     */
    private function readStateInt(string $key): ?int
    {
        $row = $this->settings->getOverride($key);
        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row['value'];
        if (!is_int($value) || $value <= 0) {
            return null;
        }

        return $value;
    }
}
