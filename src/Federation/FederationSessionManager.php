<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Manages federation sessions between hub peers.
 *
 * Responsibilities:
 *   - Register a new federation session when a peer connects
 *   - Track heartbeats and bytes sent/received per session
 *   - Close sessions gracefully
 *   - Reap stale dead sessions
 *   - Hold the single-use `hub_hello_ack` nonce of each pending handshake (H-4):
 *     the master mints a fresh nonce per session at `hub_hello`, and consumes it
 *     exactly once when verifying the leaf's `hub_hello_auth` proof. A replayed
 *     proof therefore has no live nonce left to satisfy.
 *
 * @package Phlix\Hub\Federation
 */
class FederationSessionManager
{
    /**
     * How long a pending `hub_hello_auth` proof may stay outstanding before the
     * master treats the ceremony as dead (minors campaign M-HUB-11).
     *
     * The leaf arms its own 10-second ACK watchdog (FederationPeerManager::
     * HELLO_ACK_TIMEOUT_SECONDS); 30 s = that bound times three, giving WAN
     * round-trip headroom while still guaranteeing a stuck half-open ceremony
     * retires instead of squatting until the session reaper. Checked on the
     * consume path and from the heartbeat arm — the leaf that received our
     * ACK but never sent its proof keeps heartbeating (the socket is alive),
     * and only this staleness rule closes the link.
     */
    public const int PENDING_HANDSHAKE_TIMEOUT_SECONDS = 30;

    /**
     * Upper bound on the IN-list of each batched statement inside
     * {@see reapDeadSessions()}. A long outage can make thousands of sessions
     * stale at once; chunking keeps every statement's parameter list and
     * packet size bounded instead of growing with the dead-row count.
     */
    private const int REAP_BATCH_SIZE = 500;

    /**
     * session UUID → pending handshake state, in-process (master side only).
     *
     * Lives here — not in the frame handler — because the handshake is
     * session-scoped state, and this manager is the session lifecycle owner.
     * Bounded: at most one entry per peer (beginHandshake prunes the peer's
     * earlier attempts), and every teardown path abandons the peer's entry.
     *
     * @var array<string, array{peer_id: string, nonce: string, begun_at: int}>
     */
    private array $pendingHandshakes = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Register a new federation session for a connected peer.
     *
     * Any previously-live session row for the same peer is marked dead first:
     * a re-hello after a crash must not leave the old row `alive = 1`
     * forever (L-6 hygiene — orphan live sessions masked the real state).
     *
     * @param string $peerId Peer UUID.
     *
     * @return string The session UUID.
     */
    public function registerSession(string $peerId): string
    {
        $sessionId = Ids::uuidV4();

        // A fresh session supersedes any handshake still pending under an
        // older attempt for this peer (re-hello without the old conn having
        // been closed out yet).
        $this->abandonHandshakesForPeer($peerId);

        $this->db->query(
            'UPDATE federation_sessions SET alive = 0 WHERE peer_id = :peer_id AND alive = 1',
            ['peer_id' => $peerId],
        );

        $this->db->query(
            'INSERT INTO federation_sessions (id, peer_id)
             VALUES (:id, :peer_id)',
            [
                'id' => $sessionId,
                'peer_id' => $peerId,
            ],
        );

        $this->db->query(
            'UPDATE federation_peers SET last_connected_at = NOW(), status = :status
             WHERE id = :id',
            [
                'id' => $peerId,
                'status' => 'connected',
            ],
        );

        $this->logger->info('Federation session registered', [
            'session_id' => $sessionId,
            'peer_id' => $peerId,
        ]);

        return $sessionId;
    }

    /**
     * Touch the heartbeat timestamp and increment byte counters.
     *
     * @param string $sessionId Session UUID.
     *
     * @return void
     */
    public function touchHeartbeat(string $sessionId): void
    {
        $this->db->query(
            'UPDATE federation_sessions
             SET last_heartbeat_at = NOW(),
                 bytes_sent = bytes_sent + 1,
                 bytes_received = bytes_received + 1
             WHERE id = :id',
            ['id' => $sessionId],
        );
    }

    /**
     * Touch the heartbeat of the peer's LIVE session, addressed by peer.
     *
     * H-2: callers on the frame paths only know the PEER identity (the WS
     * route/`hub_hello` carries hub UUIDs, never the session UUID minted by
     * registerSession). The old code fed a hub UUID to touchHeartbeat(),
     * whose WHERE clause is `id = :id`, so every heartbeat updated 0 rows
     * and the reaper killed live links after 60s.
     *
     * @param string $peerId Peer UUID whose alive session should be refreshed.
     *
     * @return bool True when a live session row was refreshed.
     */
    public function touchHeartbeatByPeerId(string $peerId): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM federation_sessions
             WHERE peer_id = :peer_id AND alive = 1
             ORDER BY established_at DESC
             LIMIT 1',
            ['peer_id' => $peerId],
        );

        $rawSessionId = $rows[0]['id'] ?? null;
        if (!is_string($rawSessionId) || $rawSessionId === '') {
            return false;
        }

        $this->touchHeartbeat($rawSessionId);

        return true;
    }

    /**
     * Record bytes sent to a peer.
     *
     * @param string $sessionId Session UUID.
     * @param int    $bytes     Number of bytes sent.
     *
     * @return void
     */
    public function recordBytesOut(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE federation_sessions SET bytes_sent = bytes_sent + :bytes WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Record bytes received from a peer.
     *
     * @param string $sessionId Session UUID.
     * @param int    $bytes     Number of bytes received.
     *
     * @return void
     */
    public function recordBytesIn(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE federation_sessions SET bytes_received = bytes_received + :bytes WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Close a federation session and update peer status.
     *
     * @param string $sessionId Session UUID.
     *
     * @return void
     */
    public function closeSession(string $sessionId): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT peer_id FROM federation_sessions WHERE id = :id LIMIT 1',
            ['id' => $sessionId],
        );

        /** @var mixed $peerId */
        $peerId = $rows[0]['peer_id'] ?? null;

        $this->db->query(
            'UPDATE federation_sessions SET alive = 0 WHERE id = :id',
            ['id' => $sessionId],
        );

        if ($peerId !== null) {
            $this->db->query(
                'UPDATE federation_peers SET status = :status WHERE id = :id',
                [
                    'id' => $peerId,
                    'status' => 'disconnected',
                ],
            );
        }

        // The session is gone — its single-use handshake nonce goes with it.
        unset($this->pendingHandshakes[$sessionId]);

        $this->logger->info('Federation session closed', [
            'session_id' => $sessionId,
            'peer_id' => $peerId,
        ]);
    }

    /**
     * Record the single-use `hub_hello_ack` nonce minted for a fresh handshake (H-4).
     *
     * Called by the master immediately before sending the signed `hub_hello_ack`;
     * the nonce lives in the pending-handshake state until {@see consumeHandshake()}
     * verifies the leaf's `hub_hello_auth` proof exactly once.
     *
     * @param string $sessionId Session UUID this handshake belongs to.
     * @param string $peerId    Local peer row UUID the session addresses.
     * @param string $nonce     Fresh nonce embedded in the signed `hub_hello_ack`.
     *
     * @return void
     */
    public function beginHandshake(string $sessionId, string $peerId, string $nonce): void
    {
        $this->abandonHandshakesForPeer($peerId);

        $this->pendingHandshakes[$sessionId] = [
            'peer_id' => $peerId,
            'nonce' => $nonce,
            'begun_at' => time(),
        ];
    }

    /**
     * Consume a session's pending handshake exactly once (H-4).
     *
     * The entry is removed whether or not the caller's proof later verifies,
     * which is what makes a replayed `hub_hello_auth` fail: there is no second read
     * of a live nonce.
     *
     * An entry older than {@see PENDING_HANDSHAKE_TIMEOUT_SECONDS} is also
     * removed and reported as absent (minors campaign M-HUB-11): a ceremony
     * whose proof never arrived must not stay pending indefinitely, and the
     * expiry check lives here so the burn path can never re-adopt a dead one.
     *
     * @param string   $sessionId Session UUID carried by the leaf's `hub_hello_auth`.
     * @param int|null $now       Clock override for tests (epoch seconds); null = time().
     *
     * @return array{peer_id: string, nonce: string, begun_at: int}|null Pending state,
     *         or null when no handshake is pending or the pending one has aged out.
     */
    public function consumeHandshake(string $sessionId, ?int $now = null): ?array
    {
        $pending = $this->pendingHandshakes[$sessionId] ?? null;

        unset($this->pendingHandshakes[$sessionId]);

        if ($pending === null) {
            return null;
        }

        $age = ($now ?? time()) - $pending['begun_at'];
        if ($age > self::PENDING_HANDSHAKE_TIMEOUT_SECONDS) {
            return null; // Aged-out ceremony: indistinguishable from never-begin — proof refused loudly upstream.
        }

        return $pending;
    }

    /**
     * Is this peer's pending handshake aged past the ceremony timeout?
     *
     * Used by the master's heartbeat arm to close half-open links whose leaf
     * received the signed ACK but never completed `hub_hello_auth` (minors
     * campaign M-HUB-11). Peers with no pending handshake (verified channels,
     * or never-helloed sockets) are never stale by this rule.
     *
     * @param string   $peerId Local peer row UUID.
     * @param int|null $now    Clock override for tests (epoch seconds); null = time().
     *
     * @return bool True when a pending ceremony for this peer has aged out.
     */
    public function isPendingHandshakeStale(string $peerId, ?int $now = null): bool
    {
        $cutoff = ($now ?? time()) - self::PENDING_HANDSHAKE_TIMEOUT_SECONDS;

        foreach ($this->pendingHandshakes as $state) {
            if ($state['peer_id'] === $peerId && $state['begun_at'] < $cutoff) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop every pending handshake belonging to a peer (teardown / supersede).
     *
     * @param string $peerId Local peer row UUID.
     *
     * @return void
     */
    public function abandonHandshakesForPeer(string $peerId): void
    {
        foreach ($this->pendingHandshakes as $sessionId => $state) {
            if ($state['peer_id'] === $peerId) {
                unset($this->pendingHandshakes[$sessionId]);
            }
        }
    }

    /**
     * Get the active session for a peer, if any.
     *
     * @param string $peerId Peer UUID.
     *
     * @return array<string, mixed>|null Session record or null.
     */
    public function getActiveSession(string $peerId): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, peer_id, established_at, last_heartbeat_at, bytes_sent, bytes_received, alive'
             . ' FROM federation_sessions'
             . "\n             WHERE peer_id = :peer_id AND alive = 1"
             . "\n             ORDER BY established_at DESC"
             . "\n             LIMIT 1",
            ['peer_id' => $peerId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Reap sessions that have not received a heartbeat within the threshold.
     *
     * @param int $thresholdSeconds Sessions alive longer than this without heartbeat are reaped.
     *
     * @return int Number of sessions reaped.
     */
    public function reapDeadSessions(int $thresholdSeconds = 60): int
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, peer_id FROM federation_sessions
             WHERE alive = 1
               AND last_heartbeat_at < DATE_SUB(NOW(), INTERVAL :threshold SECOND)',
            ['threshold' => $thresholdSeconds],
        );

        if ($rows === []) {
            return 0;
        }

        // Batched instead of 2 statements PER dead session: a mass eviction
        // (link flap, peer restart storm) used to serialise thousands of
        // single-row round-trips through one blocking tick of the reaper. The
        // work is now one bounded-IN UPDATE per table per chunk — constant
        // statement count per chunk regardless of row count. Peer ids are
        // de-duplicated: N dead sessions of one peer used to rewrite that
        // peer's status N times.
        /** @var list<string> $sessionIds */
        $sessionIds = [];
        /** @var array<string, true> $peerIdSet */
        $peerIdSet = [];
        foreach ($rows as $row) {
            /** @var mixed $rawSessionId */
            $rawSessionId = $row['id'] ?? null;
            /** @var mixed $rawPeerId */
            $rawPeerId = $row['peer_id'] ?? null;
            if (is_string($rawSessionId) && $rawSessionId !== '') {
                $sessionIds[] = $rawSessionId;
            }
            if (is_string($rawPeerId) && $rawPeerId !== '') {
                $peerIdSet[$rawPeerId] = true;
            }
        }

        foreach (array_chunk($sessionIds, self::REAP_BATCH_SIZE) as $batch) {
            [$placeholders, $params] = self::inListPlaceholders('sid', $batch);
            $this->db->query(
                "UPDATE federation_sessions SET alive = 0 WHERE id IN ({$placeholders})",
                $params,
            );
        }

        /** @var list<string> $peerIds */
        $peerIds = array_keys($peerIdSet);
        foreach (array_chunk($peerIds, self::REAP_BATCH_SIZE) as $batch) {
            [$placeholders, $params] = self::inListPlaceholders('peer', $batch);
            $this->db->query(
                "UPDATE federation_peers SET status = :status WHERE id IN ({$placeholders})",
                ['status' => 'disconnected'] + $params,
            );
        }

        $count = count($rows);
        $this->logger->info('Reaped dead federation sessions', [
            'count' => $count,
            'threshold_seconds' => $thresholdSeconds,
        ]);

        return $count;
    }

    /**
     * Build one `:prefixN`-keyed IN-list for a batched UPDATE: Workerman's
     * Connection binds named parameters only (positional `?` breaks bindMore —
     * house DB law), so every value gets its own name. Values are NEVER
     * interpolated into the SQL — they travel in the returned bind map.
     *
     * @param list<string> $values
     *
     * @return array{0: string, 1: array<string, string>} [":prefix0, :prefix1, …", name => value]
     */
    private static function inListPlaceholders(string $prefix, array $values): array
    {
        $names = [];
        $params = [];
        foreach ($values as $i => $value) {
            $name = $prefix . $i;
            $names[] = ':' . $name;
            $params[$name] = $value;
        }

        return [implode(', ', $names), $params];
    }
}
