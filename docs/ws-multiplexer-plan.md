# WebSocket Multiplexed Relay Tunnel — Implementation Plan

```
status: not-started
phase: 0
updated: 2026-05-24
```

## Goal

Implement the bidirectional WebSocket relay tunnel described in
`docs/dev/architecture-hub.md` ("Relay tunnel design"), replacing the
current `RelayController` 501 stub with a live multiplexed relay that:
(a) accepts an outbound WS connection from a phlix-server, (b) accepts
inbound WS connections from remote clients, and (c) multiplexes frames
between them while tracking bytes and idle state.

---

## ⚠️ Design Conflict: Two Relay Designs

This plan reconciles two competing relay designs in the codebase.
Failing to account for this would produce a hub that is incompatible
with the existing server-side relay implementation.

### C.6 HTTP-proxy-over-WS (existing, shipping)

- **Source:** `phlix-docs/docs/dev/relay-protocol.md` + server-side
  `RelayMessageFramer` in `phlix-server/src/Hub/RelayMessageFramer.php`
- **Wire:** `[1-byte type][4-byte seq (big-endian uint32)][4-byte payload_len (big-endian uint32)][payload_bytes]`
  - `TYPE_HTTP_REQUEST = 1`, `TYPE_HTTP_RESPONSE = 2`, `TYPE_PING = 3`, `TYPE_PONG = 4`
- **Mount:** single `wss://hub.example.com/api/v1/servers/{id}/relay`
- **Behavior:** Hub proxies HTTP request/response pairs over the tunnel.
  Client makes HTTPS request to `https://{subdomain}.phlix.media/*` → hub
  frames it as `TYPE_HTTP_REQUEST` → server responds with `TYPE_HTTP_RESPONSE`.
- **Session init:** Server sends `TYPE_HTTP_REQUEST` with `method=REGISTER`,
  not a separate HELLO frame.

### Multiplexed WS relay (target, per architecture-hub.md)

- **Source:** `docs/dev/architecture-hub.md` ("Relay tunnel design")
- **Wire (this plan):** Binary framing `[4-byte seq][1-byte type][2-byte length][payload]`
  for DATA/HEARTBEAT frames. JSON text for the initial HELLO handshake.
- **Mounts:** Two separate mounts — `/relay/{id}` (server connects outbound) and
  `/client/{id}` (client connects inbound)
- **Behavior:** Remote client connects via WebSocket to hub; hub multiplexes
  the WS stream directly to the server over the server's outbound tunnel.
  Not an HTTP proxy — raw WebSocket frames are relayed.
- **Session init:** Server sends `{ "type": "hello", "enrollment_jwt": "...", "server_id": "..." }`
  (JSON text sent immediately after WS handshake). Hub responds with
  `{ "type": "hello_ack", "relay_session_id": "...", "tunnel_id": "..." }`.

### Decision

The **multiplexed WS relay** (architecture-hub.md) is the target. The
C.6 HTTP-proxy design is the existing ship, but it is being replaced.
Both sides must be updated together — coordination with the
`detain/phlix-server` repo is required for any change that affects the
wire format or message types.

---

## Context & Decisions

| Decision | Rationale | Source |
|---------|-----------|--------|
| Frame type constants live in `Phlix\Shared\Relay\` | Both hub and server must agree on wire types; shared constants prevent drift | `phlix-shared/src/Hub/` (existing pattern) |
| Wire format: `[4-byte seq][1-byte type][2-byte len][payload]` for DATA frames | Consistent with existing `RelayMessageFramer` (seq+len big-endian); separate type byte avoids ambiguity | `phlix-server/src/Hub/RelayMessageFramer.php` |
| HELLO handshake is JSON text sent immediately after WS upgrade | architecture-hub.md shows JSON text exchange; avoids binary framing complexity for the single init message | `docs/dev/architecture-hub.md` "Relay tunnel design" |
| Sequence numbers per-tunnel incrementing uint32 | Same counterparty assumption as C.6; implicit ack (tcp-like) | `relay-protocol.md` (phlix-docs) |
| Heartbeat: every 30 s, 10 s grace | NAT timeout windows; matches PHLIX_RELAY_PING_INTERVAL=30 in current server config | `phlix-docs/docs/dev/relay-protocol.md` |
| Close codes follow WebSocket RFC 6455 | `1000` normal, `1008` policy violation, `1011` server error, `1012` restart | Standard WS close |
| `TunnelManager` in new `Phlix\Hub\Relay\` namespace | architecture-hub.md § Namespace map already shows `Phlix\Hub\Relay\` | `docs/dev/architecture-hub.md` § Namespace map |
| `Tunnel` class represents per-server outbound WS | Clean separation: one `Tunnel` per server_id; multiple `ClientConnection` per tunnel | architecture-hub.md |
| Idle reaper every 60 s, 90 s stale threshold | Grace period > heartbeat interval avoids false positives | `architecture-hub.md` failure mode: idle reaper |
| `RelaySessionManager` unchanged signatures | Public API already covers all needed operations | `src/Hub/RelaySessionManager.php` |
| `LogChannels::RELAY` must exist before Phase 5 | All relay events route through this channel; ensure it is declared | `src/Common/Logger/LogChannels.php` |

---

## Architecture

```
phlix-server                      phlix-hub                     remote client
     |                                |                               |
     |===== [1] wss://hub/relay/{id] ==============================|======
     |   WS upgrade (auth JWT already validated by RelayController)     |
     |                                                                   |
     |--- JSON text --->                       |                       |
     |   { "type": "hello",                     |                       |
     |     "enrollment_jwt": "eyJ...",          |                       |
     |     "server_id": "..." }                  |                       |
     |                              TunnelManager::acceptServer()        |
     |                              RelaySessionManager::registerServer() |
     |                                                                   |
     |<-- JSON text ---                          |                       |
     |   { "type": "hello_ack",                  |                       |
     |     "relay_session_id": "...",           |                       |
     |     "tunnel_id": "..." }                 |                       |
     |                                                                   |
     |                              |<---- [3] wss://hub/client/{id} ----|
     |                              |   WS upgrade + auth (enrollment JWT)|
     |                              |                                    |
     |                              TunnelManager::acceptClient()         |
     |                                                                   |
     |==== binary DATA frames [4-byte seq][1-byte type][2-byte len] =====|
     |<======================== tunnel frames multiplexed ===============>|
     |                                                                   |
     |   bytes_out += frame.len          |   bytes_in += frame.len       |
     |   last_frame_at = now()           |   last_frame_at = now()       |
     |                                                                   |
     |==[5]== TYPE_DISCONNECTED ======>|==== WS close [6] ============>|
```

### Components to create

| Component | File | Responsibility |
|-----------|------|-----------------|
| `TunnelManager` | `src/Relay/TunnelManager.php` | Owns all active tunnels; maps `server_id → Tunnel`; routes client connections to the right server tunnel |
| `Tunnel` | `src/Relay/Tunnel.php` | Per-server-outbound WS state: server WS handle, client list, sequence, heartbeat timer, byte counters |
| `ClientConnection` | `src/Relay/ClientConnection.php` | Per-client inbound WS: client WS handle, mount path, last-frame timestamp |
| `Frame` | `src/Relay/Frame.php` | Hub-side immutable value object: wraps `Phlix\Shared\Relay\RelayFrame`, adds `toBytes()` / `parse()` using the hub's `FrameDecoder` codec |
| `FrameDecoder` | `src/Relay/FrameDecoder.php` | Implements `Phlix\Shared\Relay\RelayWireCodec`. Stateful streaming parser; handles partial frames. Also handles JSON HELLO/HELLO_ACK text encoding |
| `IdleReaper` | `src/Relay/IdleReaper.php` | Periodic timer: scans all tunnels, closes any with `last_frame_at` stale > 90 s |
| `ClientMountController` | `src/Http/Controllers/ClientMountController.php` | WS upgrade for `GET /client/{server_id}`; delegates to `TunnelManager::acceptClient()` |
| `HubServicesProvider` (modify) | `src/Common/Container/HubServicesProvider.php` | Register `TunnelManager`, `IdleReaper`, start heartbeat timer in `boot()` |

> `RelayFrameType` and `RelayFrame` live in `phlix-shared` (`Phlix\Shared\Relay\`).
> The hub imports them directly — no hub-side duplication of type constants.

### Components to modify

| Component | Change |
|-----------|--------|
| `RelayController::handle()` | After auth + WS upgrade, call `TunnelManager::acceptServer()` instead of 501 |
| `RelaySessionManager` | Add `recordBytesIn(string $sessionId, int $bytes): void`; add `touchLastFrame(string $sessionId): void` |
| `HubServicesProvider` | Register `TunnelManager`, `IdleReaper`, start heartbeat + reaper timers in `boot()` |
| `Router` | Add `GET /client/{server_id}` route |

---

## Wire Format

### Initial HELLO handshake (JSON text, immediately after WS upgrade)

Server sends immediately after WS upgrade completes (before any binary frames):

```
→ Hub:  {"type":"hello","enrollment_jwt":"<ed25519-jwt>","server_id":"<uuid>"}
← Hub:  {"type":"hello_ack","relay_session_id":"<uuid>","tunnel_id":"<uuid>"}
```

If the JWT is invalid the hub closes the WS immediately with RFC 6455
close code `1008` (Policy Violation).

### Binary data frames (after handshake)

All subsequent frames use this binary encoding (all integers big-endian):

```
[4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
```

Maximum frame payload: 65535 bytes.

### Frame type mapping

| Type constant | Value | Direction | Payload |
|-------------|-------|----------|---------|
| `TYPE_HELLO` | 0x01 | S→H | JSON object (handled as text before binary mode) |
| `TYPE_HELLO_ACK` | 0x02 | H→S | JSON object (handled as text before binary mode) |
| `TYPE_CLIENT_CONNECT` | 0x03 | H→S | `{"client_id":"<uuid>","session_id":"<uuid>"}` |
| `TYPE_CLIENT_DISCONNECT` | 0x04 | H→S | `{"client_id":"<uuid>"}` |
| `TYPE_DATA` | 0x05 | S↔H↔C | raw bytes forwarded verbatim |
| `TYPE_HEARTBEAT` | 0x06 | either→either | empty or `{"seq":N}` |
| `TYPE_DISCONNECTED` | 0x07 | H→C | `{"reason":"..."}` |
| `TYPE_ERROR` | 0x08 | H↔any | `{"code":"...","message":"..."}` |

> **Note:** `TYPE_HELLO` and `TYPE_HELLO_ACK` are exchanged as JSON text
> before binary mode is entered. Binary mode begins immediately after the
> `hello_ack` is sent/received. The binary encoder/decoder is NOT used for
> the initial handshake.

---

## Phase 0: Shared relay types in phlix-shared [PENDING]

The relay wire protocol is the shared contract between phlix-server and
phlix-hub. Type constants and codec interfaces live in `phlix-shared`
(`Phlix\Shared\Relay\*`), and both implementations depend on them.
Encoding logic stays in each repo (hub uses `FrameDecoder` in
`src/Relay/`; server uses `RelayMessageFramer` in `src/Hub/`).

> This phase requires changes in **two repos simultaneously**:
> `detain/phlix-shared` (new files) and `detain/phlix-server`
> (update `RelayMessageFramer` to use shared constants).

- [ ] **0.1** `Phlix\Shared\Relay\RelayFrameType` — PHP 8.3 backed enum
  with 8 cases: `HELLO(0x01)`, `HELLO_ACK(0x02)`, `CLIENT_CONNECT(0x03)`,
  `CLIENT_DISCONNECT(0x04)`, `DATA(0x05)`, `HEARTBEAT(0x06)`,
  `DISCONNECTED(0x07)`, `ERROR(0x08)`. Each case carries `public const int value`.
- [ ] **0.2** `Phlix\Shared\Relay\RelayWireCodec` — interface for the
  encoder/decoder pair. Defines:
  ```php
  public function encode(RelayFrameType $type, int $seq, string $payload): string;
  public function encodeHello(string $enrollmentJwt, string $serverId): string;  // JSON text
  public function encodeHelloAck(string $relaySessionId, string $tunnelId): string; // JSON text
  public function decode(string $bytes): ?RelayFrame; // null if incomplete
  ```
- [ ] **0.3** `Phlix\Shared\Relay\RelayFrame` — immutable value object:
  `type (RelayFrameType)`, `seq (int)`, `payload (string)`.
  Only for the binary protocol (not used for JSON HELLO frames).
- [ ] **0.4** `composer.json` in phlix-shared — add `Phlix\Shared\Relay\`
  to autoload `psr-4`; bump `Phlix\Shared\Version::VERSION`
- [ ] **0.5** Server-side: update `RelayMessageFramer` in phlix-server to
  implement `RelayWireCodec`, using shared `RelayFrameType` constants.
  Remove old `TYPE_HTTP_REQUEST/RESPONSE/PING/PONG` constants (or mark
  deprecated). Add `encodeHello()`, `encodeHelloAck()`.
- [ ] **0.6** Update `RelayConsumer` in phlix-server to send JSON HELLO
  immediately after WS handshake, then enter binary mode; handle
  `CLIENT_CONNECT` / `CLIENT_DISCONNECT` frame types from hub.
- [ ] **0.7** All PHPStan level 9 and Psalm green on phlix-shared;
  `phpcs PSR-12` clean; all checks green on phlix-server

**Deliverable (phlix-shared):** `src/Relay/RelayFrameType.php`,
`src/Relay/RelayWireCodec.php`, `src/Relay/RelayFrame.php`; updated
`composer.json`

**Deliverable (phlix-server):** updated `src/Hub/RelayMessageFramer.php`,
updated `src/Hub/RelayConsumer.php`

---

## Phase 1: Frame layer [PENDING]

Implement the hub-side binary wire protocol using the shared codec
interface. The hub's `Relay\FrameDecoder` implements `RelayWireCodec`.

- [ ] **1.1** `Hub\Relay\RelayFrameType` — thin wrapper around
  `Phlix\Shared\Relay\RelayFrameType` for hub use (imports from shared,
  re-exports for convenience). Alternatively, the hub code imports
  `Phlix\Shared\Relay\RelayFrameType` directly and this wrapper is
  not needed — decide in review.
- [ ] **1.2** `Hub\Relay\Frame` — hub-side immutable value object:
  `type (RelayFrameType)`, `seq (int)`, `payload (string)`,
  `toBytes(): string` (binary encode using shared codec),
  static `parse(string $raw): ?self` (binary decode, null if incomplete).
  Can extend or wrap `Phlix\Shared\Relay\RelayFrame`.
- [ ] **1.3** `Hub\Relay\FrameDecoder` — implements `RelayWireCodec`.
  Stateful streaming parser using an internal buffer.
  `append(string $chunk): \Generator<Frame>` yields complete frames.
  Handles partial frames correctly. Also implements `encodeHello()` and
  `encodeHelloAck()` (JSON text output) for the handshake phase.
- [ ] **1.4** Unit tests for `Frame::parse` / `toBytes` round-trip for all
  `RelayFrameType` variants at boundary sizes (0, 1, 255, 256, 65534, 65535
  payload bytes)
- [ ] **1.5** Verify `FrameDecoder` handles: empty chunk, partial header,
  partial payload, multiple complete frames in one chunk

**Deliverable:** `src/Relay/Frame.php`, `src/Relay/FrameDecoder.php`,
`tests/Unit/Relay/FrameTest.php`, `tests/Unit/Relay/FrameDecoderTest.php`

> **Note:** `RelayFrameType` and `RelayFrame` live in `phlix-shared`
> (Phase 0). The hub imports them from there — no duplication.

---

## Phase 2: Server-side outbound connection [PENDING]

Accept the server's outbound WS, authenticate via HELLO, and manage `Tunnel` state.

- [ ] **2.1** `Tunnel` class — `serverId`, `serverWs`, `clientConnections: SplObjectStorage`,
  `seq (int)`, `openedAt`, `lastFrameAt`, `status (PENDING|ACTIVE|CLOSING|CLOSED)`
- [ ] **2.2** `Tunnel::sendToServer(Frame): void` — encode + write to server WS,
  call `RelaySessionManager::recordBytesOut()`
- [ ] **2.3** `Tunnel::broadcastToClients(Frame): void` — encode once, write to
  all connected client WS, call `recordBytesIn()` per client
- [ ] **2.4** `Tunnel::onServerMessage(string): void` — after HELLO exchange:
  decode binary frame via `FrameDecoder`; handle DATA→broadcast,
  HEARTBEAT→touch `lastFrameAt`; other types→log+close
- [ ] **2.5** `Tunnel::onServerClose(): void` — mark CLOSED, call
  `RelaySessionManager::closeSession()`, close all client WS with
  `TYPE_DISCONNECTED`
- [ ] **2.6** `TunnelManager::acceptServer(string $serverId, WorkermanConnection $ws): void`
  — register tunnel in map, call `RelaySessionManager::registerServer()`,
  start heartbeat timer for this tunnel
- [ ] **2.7** `TunnelManager::getTunnelForServer(string $serverId): ?Tunnel`
- [ ] **2.8** `TunnelManager::closeTunnel(string $serverId, string $reason): void`
  — mark closed, send `TYPE_DISCONNECTED` to all clients, close server WS,
  call `RelaySessionManager::closeSession()`, remove from map
- [ ] **2.9** `RelaySessionManager::recordBytesIn(string $sessionId, int $bytes): void`
  — mirrors `recordBytesOut()`; update `bytes_in` + `last_frame_at`
- [ ] **2.10** `RelaySessionManager::touchLastFrame(string $sessionId): void`
  — update `last_frame_at` without byte delta (for HEARTBEAT frames)

**Deliverable:** `src/Relay/Tunnel.php`, `src/Relay/TunnelManager.php`,
updated `src/Hub/RelaySessionManager.php`, `tests/Unit/Relay/TunnelTest.php`

**⚠️ Server-side coordination required:** `phlix-server/src/Hub/RelayMessageFramer.php`
currently implements the C.6 binary format. The server must be updated in
parallel to use the new frame types (0x01–0x08) in Phase 2. Specifically:
`RelayMessageFramer` constants need new values (or a new `RelayMultiplexer`
class), and `RelayConsumer` needs to send the JSON HELLO before entering
binary mode and handle `TYPE_CLIENT_CONNECT` / `TYPE_CLIENT_DISCONNECT`
frames.

---

## Phase 3: Client-side inbound mount [PENDING]

Accept remote client WS connections and route them to the correct `Tunnel`.

- [ ] **3.1** `ClientConnection` class — `clientWs`, `serverId`, `clientId`,
  `lastFrameAt`
- [ ] **3.2** `ClientConnection::send(Frame): void` — encode + write to client WS
- [ ] **3.3** `ClientConnection::onMessage(string $data): void` — decode binary
  frame via `FrameDecoder`; only `TYPE_DATA` frames are forwarded to the
  server; other types are logged and discarded
- [ ] **3.4** `ClientConnection::onClose(): void` — notify `Tunnel` to send
  `TYPE_CLIENT_DISCONNECTED` upstream
- [ ] **3.5** `Tunnel::registerClient(ClientConnection): void` — add to
  `clientConnections`, send `TYPE_CLIENT_CONNECT` to server
- [ ] **3.6** `Tunnel::removeClient(ClientConnection): void` — remove from
  `clientConnections`, send `TYPE_CLIENT_DISCONNECT` to server
- [ ] **3.7** `TunnelManager::acceptClient(string $serverId, WorkermanConnection $ws): void`
  — look up tunnel by `serverId`; if not found, close client WS with 503 body
  `{"error":"SERVER_OFFLINE","code":"relay.server_not_connected"}`
- [ ] **3.8** `ClientMountController` — `GET /client/{server_id}` route.
  Validate same enrollment JWT as `RelayController`; call
  `TunnelManager::acceptClient()` on WS upgrade
- [ ] **3.9** Router — add `GET /client/{server_id}` alongside the existing
  `POST /relay/{id}` route

**Deliverable:** `src/Relay/ClientConnection.php`,
`src/Http/Controllers/ClientMountController.php`,
updated router, `tests/Unit/Relay/ClientConnectionTest.php`

---

## Phase 4: Heartbeat + idle reaper [PENDING]

Keep tunnels alive and reap stale ones.

- [ ] **4.1** `Tunnel::sendHeartbeat(): void` — encode + write `TYPE_HEARTBEAT`
  frame to server; touch `lastFrameAt` via `touchLastFrame()`
- [ ] **4.2** `TunnelManager::startHeartbeatTimer(): void` — register
  `Timer::add(30, ...)` in `HubServicesProvider::boot()` that iterates all
  tunnels and calls `sendHeartbeat()` on each
- [ ] **4.3** `Tunnel::onHeartbeatOrData(): void` — any valid binary frame
  from server touches `lastFrameAt` (already handled in `onServerMessage`)
- [ ] **4.4** `IdleReaper` class — `Timer::add(60, ...)` in
  `HubServicesProvider::boot()`; iterates all tunnels; closes any where
  `now - tunnel.lastFrameAt > 90` with reason `"timeout"`
- [ ] **4.5** `TunnelManager::allTunnels(): \Generator<string, Tunnel>`
  — yields `[serverId => Tunnel]` for all `ACTIVE` tunnels; used by both
  heartbeat timer and idle reaper to avoid iterating a snapshot
- [ ] **4.6** `TunnelManager::closeTunnel(string $serverId, string $reason): void`
  — already defined in Phase 2; wire it to idle reaper

**Deliverable:** updated `TunnelManager`, new `src/Relay/IdleReaper.php`,
`HubServicesProvider` update, `tests/Unit/Relay/IdleReaperTest.php`

---

## Phase 5: Observability + byte accounting [PENDING]

Wiring `bytes_in`, `bytes_out`, `last_frame_at` throughout the pipeline.

- [ ] **5.1** `Tunnel::sendToServer()` → `recordBytesOut(sessionId, strlen(frame))`
- [ ] **5.2** `Tunnel::broadcastToClients()` → `recordBytesIn(sessionId, strlen(frame))`
  per recipient
- [ ] **5.3** Every `TYPE_DATA` frame decoded in `Tunnel::onServerMessage()`
  → `touchLastFrame()`
- [ ] **5.4** Structured log events:
  - `INFO`: tunnel open, tunnel close, client connect, client disconnect,
    idle reaped, heartbeat sent
  - `WARNING`: protocol error, unexpected frame type
  - `ERROR`: frame decode error, DB write failure in session manager
- [ ] **5.5** Confirm `LogChannels::RELAY` exists in `LogChannels.php`.
  If not, add it and update `LoggerFactory` initialization. Route all relay
  events through this channel.
- [ ] **5.6** `relay_url` in `accessInfo` — already implemented via
  `servers.subdomain` + `public_domain`; no change needed

---

## Phase 6: Backwards compatibility + error handling [PENDING]

Ensure error responses and restart behavior are correct.

- [ ] **6.1** `RelayController::handle()` after auth: on WS upgrade, read
  the initial JSON HELLO, validate JWT, call `TunnelManager::acceptServer()`.
  If `TunnelManager` throws (DB error, JWT validation failure), return
  HTTP 500 with `{"error":"INTERNAL_ERROR","code":"relay.tunnel_init_failed"}`
  and close the WS.
- [ ] **6.2** If client connects to `/client/{id}` but no active tunnel,
  close with HTTP 503 body `{"error":"SERVER_OFFLINE",
  "code":"relay.server_not_connected"}` and `Retry-After: 30` header
  (RFC 9110 §15.7.4).
- [ ] **6.3** On hub restart: all `relay_sessions.closed_at IS NULL` rows
  are orphaned. Idle reaper handles within 90 s. Servers reconnect
  automatically via HubClient retry loop.
- [ ] **6.4** Server disconnect + reconnect: if a `HELLO` arrives for a
  `server_id` that already has an active `Tunnel`, close the old tunnel
  first (`closeTunnel(oldId, 'server_replaced')`) before accepting the new one.
  A `server_id` must not have two simultaneous tunnels.
- [ ] **6.5** `RelayRouter::routeBySubdomain()` — unchanged. It still
  looks up `relay_sessions` by `server_id`. No impact from multiplex design.
- [ ] **6.6** The 501 response body shape from the old `RelayController` was:
  `{"error":"NOT_IMPLEMENTED","code":"relay.ws_not_implemented",...}`.
  This is fully replaced; no backwards compat needed for the old JSON body.

---

## Phase 7: Integration + tests [PENDING]

- [ ] **7.1** `FrameTest` — round-trip encode/decode all 8 frame types,
  boundary sizes (0, 1, 255, 256, 65534, 65535 payload bytes)
- [ ] **7.2** `FrameDecoderTest` — empty chunk, partial header, partial
  payload, multiple frames in one chunk, corrupted data
- [ ] **7.3** `TunnelTest` — mock Workerman WS; verify `broadcastToClients()`
  encodes once and writes to two clients; verify `sendToServer()` calls
  `recordBytesOut`
- [ ] **7.4** `TunnelManagerTest` — verify `acceptServer()` registers tunnel,
  `acceptClient()` 503s when no tunnel, `getTunnelForServer()` returns correct
  tunnel, same `server_id` reconnect closes old tunnel
- [ ] **7.5** `ClientConnectionTest` — non-DATA frame is discarded;
  client close triggers upstream notification
- [ ] **7.6** `IdleReaperTest` — two tunnels, one stale (>90 s), verify
  only the stale one is reaped
- [ ] **7.7** `RelaySessionManager` — add `recordBytesIn()` test,
  add `touchLastFrame()` test
- [ ] **7.8** All PHPStan level 9 and Psalm errorLevel 1 green on new code
- [ ] **7.9** `phpcs --standard=PSR12` clean on all new files

---

## Phase 8: Staggered rollout plan [PENDING]

Each phase is a separate PR, merged and pulled before the next starts.
phlix-shared and phlix-server changes for Phase 0 land first.

| PR | Content | Dependencies |
|----|---------|-------------|
| **9.0** | Phase 0 — Shared types: `Phlix\Shared\Relay\*` in phlix-shared | none |
| **9.0s** | Phase 0 server-side: updated `RelayMessageFramer` + `RelayConsumer` in phlix-server | 9.0 |
| **9.1** | Phase 1 — Hub frame layer: `Frame`, `FrameDecoder` using shared codec | 9.0, 9.0s |
| **9.2** | Phase 2 — Server-side hub: `Tunnel`, `TunnelManager`, `RelaySessionManager` additions, `RelayController` WS upgrade | 9.1 |
| **9.3** | Phase 3 — Client-side: `ClientConnection`, `ClientMountController`, routing | 9.2 |
| **9.4** | Phase 4 — Heartbeat + reaper: `IdleReaper`, timers, `closeTunnel` | 9.3 |
| **9.5** | Phase 5 — Observability: byte accounting wiring, structured logs, `LogChannels::RELAY` | 9.4 |
| **9.6** | Phase 6 — Error handling: all error responses, restart behavior, final tests | 9.5 |

> **Cross-repo dependency:** Phase 1 requires Phase 0 (shared types) to
> be merged in phlix-shared. The server-side Phase 0s must also be merged
> in phlix-server before Phase 1 can be complete. All three repos
> (phlix-shared, phlix-server, phlix-hub) must have their Phase 0/0s
> changes before any Phase 1 work can start on the hub.

---

## Failure Modes Summary

| Failure | Detection | Response |
|---------|-----------|----------|
| Server WS drops | `Tunnel::onServerClose()` | Mark CLOSED; send `TYPE_DISCONNECTED` to all clients; close clients; call `closeSession()` |
| Client WS drops | `ClientConnection::onClose()` | Remove from `clientConnections`; send `TYPE_CLIENT_DISCONNECT` to server |
| Idle tunnel (no frames 90 s) | `IdleReaper` every 60 s | Close tunnel with `timeout`; notify clients + server |
| Hub restart | All WS connections drop | Orphaned `relay_sessions` rows; idle reaper cleans within 90 s; servers reconnect |
| Server sends malformed binary frame | `FrameDecoder` throws | Log ERROR; close tunnel with RFC 6455 `1011`; call `closeSession('protocol-error')` |
| Client sends non-DATA frame | `ClientConnection::onMessage()` | Log WARNING; send `TYPE_ERROR` to client; do not forward to server |
| Client connects to offline server | `TunnelManager::acceptClient()` | HTTP 503 + `Retry-After: 30` |
| Server sends HELLO for already-connected server | `TunnelManager::acceptServer()` | Close old tunnel first; accept new |

---

## Related Files

### phlix-shared (new — Phase 0)

| File | Role |
|------|------|
| `src/Relay/RelayFrameType.php` | PHP 8.3 backed enum: 8 frame type constants (0x01–0x08) |
| `src/Relay/RelayWireCodec.php` | Interface for encode/decode operations |
| `src/Relay/RelayFrame.php` | Immutable value object: type, seq, payload |
| `composer.json` | Add `Phlix\Shared\Relay\` to PSR-4 autoload |

### Hub (this repo)

| File | Role |
|------|------|
| `src/Http/Controllers/RelayController.php` | Current 501 stub — replace with WS upgrade + delegate to `TunnelManager` |
| `src/Hub/RelaySessionManager.php` | DB access — extend with `recordBytesIn`, `touchLastFrame` |
| `src/Hub/RelayRouter.php` | Subdomain-based routing — unchanged |
| `src/Common/Container/HubServicesProvider.php` | DI — register `TunnelManager`, `IdleReaper`, start timers in `boot()` |
| `src/Common/Logger/LogChannels.php` | Must declare `const RELAY = 'relay'` |
| `src/Relay/Frame.php` | Hub-side frame value object wrapping shared `RelayFrame` |
| `src/Relay/FrameDecoder.php` | Hub codec — implements `RelayWireCodec`; streaming parser |

### Server (detain/phlix-server — Phase 0s)

| File | Change needed |
|------|--------------|
| `src/Hub/RelayMessageFramer.php` | Implement `RelayWireCodec`; use shared `RelayFrameType` constants; remove old C.6 type constants |
| `src/Hub/RelayConsumer.php` | Send JSON HELLO immediately after WS upgrade; enter binary mode; handle `CLIENT_CONNECT` / `CLIENT_DISCONNECT` frames |
| `composer.json` | Add `detain/phlix-shared:^0.3` constraint |

### Docs

| File | Note |
|------|------|
| `docs/dev/relay-protocol.md` (phlix-docs) | Documents C.6 HTTP-proxy design; add a clearly-labeled section for the multiplexed design |
| `docs/hub-admin/relay-tuning.md` | Stub — populate with heartbeat interval, idle timeout, bandwidth limit settings |
| `docs/hub/remote-access.md` | Update to reference both relay designs |

---

## Notes

- 2026-05-24: Plan authored per Section 9 of `phlix_update.md`.
  Primary references:
  - `docs/dev/architecture-hub.md` ("Relay tunnel design") — target design
  - `phlix-docs/docs/dev/relay-protocol.md` — C.6 HTTP-proxy design (current ship)
  - `phlix-server/src/Hub/RelayMessageFramer.php` — existing binary framing
  - `phlix-server/src/Hub/RelayConsumer.php` — existing server-side relay client
  - `src/Hub/RelaySessionManager.php` — hub-side session tracking
- Workerman 5 WebSocket: the `onMessage` callback receives raw data after
  WS handshake. Binary frames are raw bytes. The WS layer does NOT
  parse our application-level binary framing — we handle it ourselves
  via `FrameDecoder`.
- The `Relay` namespace (`src/Relay/`) does not exist yet — create it
  and register PSR-4 in `composer.json` under `Phlix\\Hub\\Relay\\`.
- Both `IdleReaper` timer and heartbeat timer must be registered in
  `HubServicesProvider::boot()` so they survive hub worker restarts.
- `worker_node` in `relay_sessions` must be populated on tunnel open
  (use `posix_gethostname()` or a configured node name). This matters
  for multi-node deployments where worker process identity is needed.
- Frame decoder must handle the case where the WS connection closes
  mid-frame — treat incomplete frame as a protocol error.
