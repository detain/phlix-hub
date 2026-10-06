<?php

/**
 * A throwaway federation MASTER hub speaking real TLS — the counterpart of
 * {@see \Phlix\Hub\Tests\Unit\Federation\FederationMasterTlsDialTest}.
 *
 * argv: <port> <markerDir> <serverPem> <masterKeyPem> <leafPubB64> <masterHubId>
 *
 * This is the process-level proof that the https→ws+transport=ssl client seam
 * is real: a workerman {@see Worker} on `websocket://0.0.0.0:<port>` with
 * `$worker->transport = 'ssl'` and a local_cert from the test CA, driving the
 * genuine H-4 ceremony with the genuine src classes (FederationHandshake,
 * Ed25519KeyManager, FrameEncoder/FrameDecoder, RelayFrameType). No protocol
 * mocks anywhere on this side — if SNI/peer_name/verify_peer were decorative,
 * the client could not complete the upgrade and nothing here would ever fire.
 *
 * Protocol handled (mirrors FederationFrameHandler's master leg):
 *   hub_hello (JSON)      → identity-checked against leafPubB64, answered with
 *                           a master-key-signed hub_hello_ack (marker `hello-ok`,
 *                           or `hello-ok-mismatch` + silence on foreign keys).
 *   hub_hello_auth (JSON) → signature verified against leafPubB64 over the
 *                           canonical auth string (marker `verified`), then one
 *                           binary DATA frame pushing a library-share offer
 *                           whose wire peer_id is THIS master's hub id
 *                           (marker `offer-pushed`).
 *   binary frames         → DATA before `verified` writes marker
 *                           `pre-verified-data`; after it, the payload is stored
 *                           verbatim into `leaf-data.json` + marker `leaf-data`
 *                           (that is the leaf's share push riding back down the
 *                           TLS channel). HEARTBEAT ignored.
 *
 * `ready` is written in onWorkerStart, after the listener is bound; a watchdog
 * exits the child after 90s whatever happens, so a wedged parent can never
 * orphan a listening process.
 *
 * @copyright 2026 Phlix
 * @license MIT
 */

declare(strict_types=1);

use Phlix\Hub\Federation\FederationHandshake;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrameType;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

$port = (int) ($argv[1] ?? 0);
$markerDir = (string) ($argv[2] ?? '');
$serverPem = (string) ($argv[3] ?? '');
$masterKeyPem = (string) ($argv[4] ?? '');
$leafPubB64 = (string) ($argv[5] ?? '');
$masterHubId = (string) ($argv[6] ?? 'master-hub-uuid');

if ($port <= 0 || $markerDir === '' || !is_file($serverPem) || !is_file($masterKeyPem) || $leafPubB64 === '') {
    fwrite(STDERR, "usage: tls_federation_master_server.php <port> <markerDir> <serverPem>"
        . " <masterKeyPem> <leafPubB64> <masterHubId>\n");
    exit(1);
}

require __DIR__ . '/../../../vendor/autoload.php';

// Workerman defaults its log file to the START-SCRIPT's directory — that would
// drop workerman.log into tests/Support/Federation/ and dirty the tree on every
// run. Keep every artifact of this child inside the test-owned marker dir.
Worker::$logFile = $markerDir . '/workerman.log';

$masterSecret = (new Ed25519KeyManager($masterKeyPem))->getOrCreateKeyPair()['private'];
$encoder = new FrameEncoder();

/**
 * Per-connection ceremony state.
 *
 * Shape: array{sessionId: ?string, nonce: ?string, leafHubId: ?string,
 * verified: bool, decoder: FrameDecoder}.
 *
 * @var SplObjectStorage<
 *     TcpConnection,
 *     array{sessionId: ?string, nonce: ?string, leafHubId: ?string, verified: bool, decoder: FrameDecoder}
 * > $states
 */
$states = new SplObjectStorage();

$freshState = static fn (): array => [
    'sessionId' => null,
    'nonce' => null,
    'leafHubId' => null,
    'verified' => false,
    'decoder' => new FrameDecoder(),
];

$touch = static function (string $name, string $contents = '') use ($markerDir): void {
    file_put_contents($markerDir . '/' . $name, $contents);
};

$worker = new Worker('websocket://0.0.0.0:' . $port, [
    'ssl' => [
        'local_cert' => $serverPem,
    ],
]);
// The exact server-side twin of the client idiom under test: workerman has no
// 'wss' scheme — TLS rides transport=ssl on a websocket:// listener.
$worker->transport = 'ssl';
$worker->count = 1;

$worker->onWorkerStart = static function () use ($touch): void {
    $touch('ready', (string) getmypid());
    // Hard watchdog: a parent that dies mid-ceremony must not leave a TLS
    // listener bound for the rest of CI's life.
    Timer::add(90.0, static function (): void {
        exit(3);
    }, [], false);
};

$worker->onMessage = static function (
    TcpConnection $connection,
    string $data
) use (
    $states,
    $freshState,
    $encoder,
    $masterSecret,
    $leafPubB64,
    $masterHubId,
    $markerDir,
    $touch,
): void {
    $state = $states->contains($connection) ? $states[$connection] : $freshState();

    // Leaf handshake messages are JSON text; relay frames start with the
    // big-endian seq (0x00 for the seq-0 pushes this test exchanges).
    if ($data !== '' && $data[0] === '{') {
        /** @var array<string, mixed>|null $msg */
        $msg = json_decode($data, true);
        $type = is_array($msg) && is_string($msg['type'] ?? null) ? $msg['type'] : '';

        if ($type === 'hub_hello') {
            $helloKey = is_array($msg) && is_string($msg['public_key'] ?? null) ? $msg['public_key'] : '';
            $hubId = is_array($msg) && is_string($msg['hub_id'] ?? null) ? $msg['hub_id'] : '';
            if ($helloKey !== $leafPubB64) {
                $touch('hello-ok-mismatch', json_encode(['expected' => $leafPubB64, 'got' => $helloKey]) ?: '');

                return;
            }

            $state['sessionId'] = bin2hex(random_bytes(16));
            $state['nonce'] = FederationHandshake::newNonce();
            $state['leafHubId'] = $hubId;
            $states[$connection] = $state;

            $touch('hello-ok', json_encode([
                'session_id' => $state['sessionId'],
                'leaf_hub_id' => $hubId,
            ]) ?: '');

            $ackCanonical = FederationHandshake::helloAckCanonical(
                (string) $state['sessionId'],
                $masterHubId,
                (string) $state['nonce'],
            );

            $connection->send(json_encode([
                'type' => 'hub_hello_ack',
                'session_id' => $state['sessionId'],
                'master_hub_id' => $masterHubId,
                'role' => 'master',
                'capabilities' => ['library_shares', 'relay', 'admin_delegation'],
                'nonce' => $state['nonce'],
                'signature' => FederationHandshake::sign($ackCanonical, $masterSecret),
            ]) ?: '');

            return;
        }

        if ($type === 'hub_hello_auth') {
            $proofOk = is_array($msg)
                && is_string($state['sessionId'])
                && is_string($state['nonce'])
                && is_string($state['leafHubId'])
                && is_string($msg['signature'] ?? null)
                && FederationHandshake::verify(
                    FederationHandshake::helloAuthCanonical($state['sessionId'], $state['nonce'], $state['leafHubId']),
                    (string) $msg['signature'],
                    $leafPubB64,
                );

            if (!$proofOk) {
                $touch('auth-rejected', $data);
                $connection->close();

                return;
            }

            $state['verified'] = true;
            $states[$connection] = $state;

            $touch('verified', json_encode(['session_id' => $state['sessionId']]) ?: '');

            // One offer push over the now-verified binary channel. Wire
            // peer_id = THIS master's hub id — exactly what the real master
            // stamps (M-5), so the leaf's rebasing runs for real downstream.
            $offerPayload = json_encode([
                'shares' => [
                    [
                        'id' => 'tls-offer-1',
                        'peer_id' => $masterHubId,
                        'library_id' => 'lib-tls',
                        'library_name' => 'TLS Library',
                        'permission' => 'read',
                        'status' => 'active',
                    ],
                ],
            ]) ?: '';

            $connection->send($encoder->encode(RelayFrameType::DATA, 0, $offerPayload));
            $touch('offer-pushed', $offerPayload);

            return;
        }

        return;
    }

    foreach ($state['decoder']->decodeAll($data) as $frame) {
        if ($frame->type === RelayFrameType::DATA) {
            if (!$state['verified']) {
                $touch('pre-verified-data', $frame->payload);

                continue;
            }

            file_put_contents($markerDir . '/leaf-data.json', $frame->payload);
            $touch('leaf-data', $frame->payload);

            continue;
        }

        // HEARTBEAT (and anything else this test never sends meaningfully)
        // is accepted-and-ignored: the ceremony is what is under proof.
    }
    $states[$connection] = $state;
};

Worker::runAll();
