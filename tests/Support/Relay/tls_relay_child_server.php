<?php

/**
 * A throwaway REAL-TLS :8802 relay listener for the hub relay TLS E2E
 * ({@see \Phlix\Hub\Tests\Unit\Relay\RelayWorkerTlsHandshakeE2ETest}).
 *
 * argv: <port> <markerDir> <serverPem> start
 *
 * Why process-level: the seam under proof is the ACCEPT side of the relay
 * tunnel — Workerman's `websocket://` listener bound with
 * `$worker->transport = 'ssl'` and the context from
 * {@see RelayWorker::relayTlsContext()} (via {@see RelayWorker::start()}
 * itself, not a re-approximation of it). Trusting a fake TLS peer would make
 * the entire point — that a strict client genuinely completes TLS + upgrade +
 * a deframed round trip against the production construction site — untestable.
 * The venue is estate-proven: tests/Support/Federation/tls_federation_master_server.php
 * runs the same shape for the federation OUTBOUND dial; this is its INBOUND
 * sibling on the relay surface.
 *
 * The worker is the genuine RelayWorker: start() applies the real M21 bind-time
 * TLS posture, then this harness replaces ONLY the onWorkerStart hook (the
 * cross-process channel broker / reapers / metrics are container+DB machinery
 * outside this proof). Everything below the hook stays production code:
 * onWebSocketConnect runs the real rate-limit ladder (stub container throws on
 * get() → the documented best-effort null path → unlimited), onMessage runs the
 * real handleHello — so a first frame of `{"type":"hello"}` (no server_id)
 * answers with a real verdict frame carrying 'missing_server_id' WITHOUT ever
 * touching the container or a database. If TLS or framing were decorative,
 * none of those bytes would exist.
 *
 * `ready` is written in onWorkerStart, after the listener is bound; a watchdog
 * exits the child after 90s whatever happens, so a wedged parent can never
 * orphan a listening process. All Workerman scratch (log/pid/status) is pinned
 * into the marker dir — the tests/ tree stays clean.
 *
 * @copyright 2026 Phlix
 * @license MIT
 */

declare(strict_types=1);

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Relay\RelayWorker;
use Psr\Container\ContainerInterface;
use Workerman\Timer;
use Workerman\Worker;

$port = (int) ($argv[1] ?? 0);
$markerDir = (string) ($argv[2] ?? '');
$serverPem = (string) ($argv[3] ?? '');

if ($port <= 0 || $markerDir === '' || !is_file($serverPem)) {
    fwrite(STDERR, "usage: tls_relay_child_server.php <port> <markerDir> <serverPem> start\n");
    exit(1);
}

require __DIR__ . '/../../../vendor/autoload.php';

// Keep every workerman artifact inside the test-owned marker dir: defaults
// derive from the START SCRIPT's directory and would dirty tests/Support/.
Worker::$logFile = $markerDir . '/workerman.log';
Worker::$pidFile = $markerDir . '/workerman.pid';
Worker::$statusFile = $markerDir . '/workerman.status';

// The production relay process boots with an initialised LoggerFactory (DI
// core-services provider), and the F-3/F-4 observability lines (2026-11-05)
// put relay-channel logging ON the live HELLO-reject and tunnel-less-close
// paths — so this bare harness must perform the same one-time bootstrap. The
// capture is pinned into the test-owned marker dir, mirroring the workerman
// scratch discipline above; the E2E asserts wire verdicts, never log text,
// but the line the process really emits must land somewhere real (S345 law).
$relayLoggerConfig = $markerDir . '/logger.php';
file_put_contents(
    $relayLoggerConfig,
    "<?php return ['default' => 'relay_e2e', 'handlers' => ['relay_e2e' => "
    . "['type' => 'stream', 'path' => '" . $markerDir . "/relay.log', 'level' => 'debug']]];",
);
LoggerFactory::init($relayLoggerConfig);

/**
 * PSR-11 stub with no bindings: the relay connect hook resolves the rate
 * limiter best-effort (Throwable → null → unlimited) and the missing_server_id
 * verdict returns before anything else asks the container. The throw is part
 * of the proof — it exercises that exact catch path on a real socket.
 */
$container = new class implements ContainerInterface {
    public function get(string $id): mixed
    {
        throw new RuntimeException('TLS relay E2E stub container has no bindings: ' . $id);
    }

    public function has(string $id): bool
    {
        return false;
    }
};

// One PEM bundle for both args: the throwaway server.pem carries cert AND key
// (the OpenSSL local_pk parse succeeds on the combined file). The production
// shape passes separate paths; neither the guard nor the context cares.
$relay = new RelayWorker(
    $container,
    $port,
    1,
    '127.0.0.1',
    RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
    true,
    $serverPem,
    $serverPem,
    false,
);

$worker = $relay->start();

$worker->onWorkerStart = static function () use ($markerDir): void {
    file_put_contents($markerDir . '/ready', '1');
    Timer::add(90.0, static function (): void {
        exit(0);
    }, [], false);
};

Worker::runAll();
