<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Closure;
use InvalidArgumentException;

use function array_keys;
use function implode;
use function is_file;
use function is_readable;

/**
 * TLS material observability for the hub's inbound TLS listeners (:8802,
 * :8805) — the runtime half of the wildcard-rotation audit cure (§3.2 of
 * .reviews/2026-11-09/AUDIT-wildcard-cert-rotation.md).
 *
 * The audit defect: OpenSSL re-reads `local_cert`/`local_pk` PER CONNECTION
 * (vendor TcpConnection::enableCrypto), so TLS material that becomes
 * unreadable AFTER boot — the off-pipeline delivery case (keys land 0600
 * root:root from a manual certbot run without the installer's phlix-tls
 * 640 perms legs) — never trips the boot-only assertTlsMaterialReadable()
 * check, yet every NEW handshake then fails enable_crypto with no log line
 * at all (the workers set no onError handler, so the vendor's
 * CONNECT_FAIL message dies in Worker::safeEcho, which is silent under
 * daemonized/systemd runs). Silent close with zero log is the defect;
 * this class makes the failure LOUD at three points without changing the
 * fail-fast law for genuinely-absent files:
 *
 *  - boot: a configured-but-MISSING PEM still throws (fail fast, pre-bind);
 *  - boot: an exists-but-UNREADABLE PEM degrades to a loud WARNING — the
 *    listener binds and keeps serving the material it can read (or fails
 *    handshakes visibly) instead of killing the hub's boot on a perms miss;
 *  - runtime: each accept re-classifies the material (two stat calls on
 *    the TLS path only) and each enable_crypto failure arrives through
 *    handshakeFailureNotice(); both emit rate-gated ERROR lines and a
 *    recovery line once the state clears.
 *
 * The rate gate is shared between material notices and handshake-failure
 * notices: a scanner hammering a broken listener produces roughly one
 * ERROR line per window (suppressed events counted on the next emitted
 * line), never a line per SYN.
 *
 * Methods take an explicit `int $now` clock and an injectable probe so the
 * whole state machine is unit-testable without sleeps or real chmod games
 * (root can read 0000 files — the probe seam sidesteps the venue).
 */
final class TlsMaterialWatch
{
    /** Material present and readable. */
    public const OK = 'ok';

    /** Path resolves to no file at all (fail-fast case). */
    public const MISSING = 'missing';

    /** File exists but this process cannot read it (degrade-and-shout case). */
    public const UNREADABLE = 'unreadable';

    private bool $degraded = false;

    private int $lastNoticeAt = 0;

    private int $suppressed = 0;

    /** @var Closure(string): string */
    private Closure $probe;

    /**
     * @param string                $surface       Listener label for log lines (e.g. 'relay-ws :8802').
     * @param array<string, string> $envPaths      Operator env name => configured PEM path.
     * @param int                   $windowSeconds Minimum gap between emitted notice lines.
     * @param (callable(string): string)|null $probe Test seam overriding the filesystem probe.
     */
    public function __construct(
        private readonly string $surface,
        private readonly array $envPaths,
        private readonly int $windowSeconds = 60,
        ?callable $probe = null,
    ) {
        $this->probe = $probe !== null ? Closure::fromCallable($probe) : self::probeDefault();
    }

    /**
     * The real-filesystem probe: OK / MISSING / UNREADABLE for one path.
     *
     * @return Closure(string): string
     */
    public static function probeDefault(): Closure
    {
        /** @param string $path */
        return static function (string $path): string {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                return self::MISSING;
            }

            return is_readable($path) ? self::OK : self::UNREADABLE;
        };
    }

    /**
     * Classify every configured path, returning only the problems.
     *
     * @param array<string, string>                $envPaths env name => path
     * @param (callable(string): string)|null      $probe    test seam
     *
     * @return array<string, string> env name => MISSING|UNREADABLE (empty when healthy)
     */
    public static function classify(array $envPaths, ?callable $probe = null): array
    {
        $probe ??= self::probeDefault();
        $problems = [];
        foreach ($envPaths as $envName => $path) {
            $state = $probe($path);
            if ($state === self::OK) {
                continue;
            }

            $problems[$envName] = $state;
        }

        return $problems;
    }

    /**
     * Boot-time split of the audit §3.2 cure.
     *
     * MISSING (either PEM) => a fatal message worded exactly like the
     * historical assertTlsMaterialReadable() throw (env name, path, failed
     * condition, both ways out). UNREADABLE => a warning line per affected
     * env — the listener still binds; the per-connection guard plus the
     * loud handshake-failure notices take over from there. When one path
     * is missing AND the other merely unreadable, the missing path wins
     * (fail fast on the stronger signal; the unreadable one is named by
     * the runtime notices).
     *
     * @param array<string, string>                $envPaths     cert env => path, key env => path (first two keys)
     * @param string                               $workerLabel  e.g. 'relay worker'
     * @param string                               $disableEnv   plaintext opt-out env, e.g. 'HUB_RELAY_TLS'
     * @param (callable(string): string)|null      $probe        test seam
     *
     * @return array{fatal: ?string, warnings: list<string>}
     */
    public static function bootDecision(
        array $envPaths,
        string $workerLabel,
        string $disableEnv,
        ?callable $probe = null
    ): array {
        $envKeys = array_keys($envPaths);
        $certEnv = $envKeys[0] ?? 'TLS cert env';
        $keyEnv = $envKeys[1] ?? 'TLS key env';

        $problems = self::classify($envPaths, $probe);
        $fatal = null;
        $warnings = [];
        foreach ($envPaths as $envName => $path) {
            $state = $problems[$envName] ?? null;
            if ($state === self::MISSING && $fatal === null) {
                $fatal = self::missingMessage($envName, $path, $workerLabel, $certEnv, $keyEnv, $disableEnv);
            } elseif ($state === self::UNREADABLE) {
                $warnings[] = self::unreadableWarning($envName, $path, $workerLabel);
            }
        }

        return ['fatal' => $fatal, 'warnings' => $warnings];
    }

    /**
     * The historical fail-fast text, parameterized (byte-ident for relay).
     */
    public static function missingMessage(
        string $envName,
        string $path,
        string $workerLabel,
        string $certEnv,
        string $keyEnv,
        string $disableEnv
    ): string {
        return 'TLS enabled for ' . $workerLabel . ' but ' . $envName . ' (' . $path . ')'
            . ' does not exist. Point ' . $certEnv . '/' . $keyEnv . ' at existing PEM files, '
            . 'or set ' . $disableEnv . '=false to bind plaintext ws:// with the boot advisory.';
    }

    /**
     * Loud boot warning for the off-pipeline perms case.
     */
    public static function unreadableWarning(string $envName, string $path, string $workerLabel): string
    {
        return 'TLS enabled for ' . $workerLabel . ' but ' . $envName . ' (' . $path . ')'
            . ' exists and is not readable by this process — handshakes WILL fail until the'
            . ' perms legs run (managed installs keep the group phlix-tls mode 640 contract;'
            . ' run the installer fix-up or the deploy hook). Booting anyway; every failure'
            . ' is logged from here on — see docs/websockets.md.';
    }

    /**
     * Per-accept material re-check; returns a line to log, or null.
     *
     * Healthy-and-was-healthy is silent (the common case costs two stats
     * and one branch). The bad->good transition emits the recovery line.
     */
    public function materialNotice(int $now): ?string
    {
        $problems = self::classify($this->envPaths, $this->probe);
        if ($problems === []) {
            if (!$this->degraded) {
                return null;
            }

            $line = 'TLS material for ' . $this->surface
                . ' is readable again' . $this->suppressedSuffix();
            $this->degraded = false;
            $this->lastNoticeAt = 0;
            $this->suppressed = 0;

            return $line;
        }

        $this->degraded = true;
        $detail = [];
        foreach ($problems as $envName => $state) {
            $detail[] = $envName . ' (' . $this->envPaths[$envName] . ') '
                . ($state === self::MISSING ? 'is missing' : 'is not readable');
        }

        return $this->gate($now, 'TLS material for ' . $this->surface . ' ' . implode('; ', $detail) . '.');
    }

    /**
     * Rate-gated loud line for one enable_crypto failure (vendor onError).
     */
    public function handshakeFailureNotice(int $now, string $reason): ?string
    {
        $this->degraded = true;

        return $this->gate(
            $now,
            'TLS handshake failed on ' . $this->surface . ': ' . $reason
                . ' — check the material/perms (group phlix-tls mode 640 contract)'
                . ' and the TLS_CERT/TLS_KEY env targets.',
        );
    }

    /**
     * True while the shared degraded flag is set (test seam + ops probes).
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * Build the InvalidArgumentException fail-fast from a bootDecision fatal.
     */
    public static function failFast(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException($message);
    }

    /**
     * Shared window gate: first event immediate, later in-window events
     * counted, the next out-of-window event carries the count.
     */
    private function gate(int $now, string $text): ?string
    {
        if ($this->lastNoticeAt !== 0 && $now - $this->lastNoticeAt < $this->windowSeconds) {
            $this->suppressed++;

            return null;
        }

        $line = $text . $this->suppressedSuffix();
        $this->lastNoticeAt = $now;
        $this->suppressed = 0;

        return $line;
    }

    private function suppressedSuffix(): string
    {
        return $this->suppressed > 0 ? ' (+' . $this->suppressed . ' similar notices suppressed)' : '';
    }
}
