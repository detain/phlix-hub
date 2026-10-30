# Phlix WebSocket surfaces

Companion to [`openapi.yaml`](../openapi.yaml), which describes the hub's HTTP
surface on `:8800`. This document covers everything that is **not** HTTP: five
WebSocket surfaces across two repositories.

| Port | Surface | Repository | Worker | Encoding |
| --- | --- | --- | --- | --- |
| `8802` | Server relay tunnel | `phlix-hub` | `Phlix\Hub\Relay\RelayWorker` | JSON handshake, then binary `RelayFrame` |
| `8803` | Client mount ⚠ declared-unavailable (see below) | `phlix-hub` | `Phlix\Hub\Relay\ClientRelayWorker` | binary `RelayFrame` |
| `8804` | SyncPlay relay | `phlix-hub` | `Phlix\Hub\SyncPlay\SyncPlayRelayWorker` | JSON text |
| `8805` | Hub federation | `phlix-hub` | `Phlix\Hub\Relay\FederationWorker` | signed JSON handshake, then binary `DATA` envelopes |
| `8097` | Media server events + SyncPlay | `phlix-server` | `Phlix\Server\WebSocket\WebSocketServer` | JSON text |

The four hub surfaces are also declared under the `x-phlix-websockets` extension
in `openapi.yaml`. They are not OpenAPI `paths`: the `:8802` tunnel mounts at the
bare root, and that path key (`/`) already belongs to the `:8800` HTTP redirect
into the SPA. `tests/Unit/Http/RouteRegistration/OpenApiSpecMatchesRouterTest.php`
pins each hub surface's declared port against the DEFAULT that defines it — the
`DEFAULT_PORT` class constants and the `relay_port` literal fallback scraped out
of `src/Application.php` — so a default that moves in code and not in the
document fails the build. An operator env-moved port (`HUB_RELAY_PORT` et al.)
is outside the pin by design: the document declares defaults, and it says so in
each surface's `portSource`.

> `:8097` belongs to `phlix-server` and this repository's CI clones one checkout,
> so nothing here asserts against it. It is documentation, not a contract test.

### Keepalive at a glance

What each socket does about idleness is part of its contract — the consumers
differ in what their APIs can even send (browser-class JS cannot emit WS-level
pings), so the story is stated per surface, traced from code:

| Surface | Server-originated ping | Idle reap | Client duty |
| --- | --- | --- | --- |
| `:8802` | yes — hub pings every active tunnel every 30 s (`HubServicesProvider::startInMemoryReapers()`) | yes — `IdleReaper` closes a tunnel with no inbound frame for >90 s, scanning every 60 s | the server's own 30 s heartbeat (and ping echoes) is what keeps it alive |
| `:8803` | none | none — and none can exist | every mount is refused with close `1011` in the deployed topology (M2 banner below); the socket never lives long enough to idle |
| `:8804` | yes — every live socket is WS-pinged every 30 s (`SyncPlayRelayWorker::LIVENESS_PING_INTERVAL_SECONDS`, M-HUB-18) | yes — silence for ≥90 s is reaped with close `1013` (`LIVENESS_REAP_AFTER_SILENCE_SECONDS`, the same HB-0.1 3× window math as `IdleReaper`); a reaped host runs the normal leave path so the party elects a live host | none for liveness — any inbound frame (data, ping, pong) refreshes the budget, and browser-class stacks answer the server ping inside the socket layer; `syncplay_time_ping` remains the app-level clock keepalive |
| `:8805` | the dialing (leaf) role sends `HEARTBEAT` every 15 s once verified; the master's listener consumes them (see the federation table below) | liveness-reaped at the session row: a heartbeat landing on an already-reaped session closes the zombie link with `DISCONNECTED` / `session_expired`; a heartbeat landing while a `hub_hello_auth` proof has been pending for over 30 s closes it with `DISCONNECTED` / `hello_auth_timeout` | none beyond the handshake |
| `:8097` | `phlix-server`'s socket — its liveness story is that repository's code, not this table | — | — |

Window math, close-path details and the honest gaps are in each port's own
section below.

---

## The binary frame format (`:8802`, `:8803`, `:8805`)

All three tunnel surfaces share one wire codec, `Phlix\Shared\Relay\RelayFrame`
(encoded by `src/Relay/FrameEncoder.php`, decoded by `FrameDecoder.php`). All
integers are big-endian:

```
[4-byte channel / request id (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
```

Maximum payload per frame: **65535 bytes**. Larger bodies are split across
several frames.

The leading 4-byte field is **not** a sequence or ack counter. The tunnel is a
single reliable WS/TCP stream, so the field is used for multiplexing instead:

* For client-scoped frames it is a **channel id**, one per concurrent client, so
  many clients share one tunnel.
* For `HTTP_REQUEST` / `HTTP_RESPONSE` / `HTTP_CANCEL` it is a **request id**
  allocated by the hub. These never collide with channel ids because they live on
  different frame types.
* Tunnel-scoped frames (handshake, heartbeat, errors) use channel **0**.

### Frame type catalog

| Code | Name | Direction | Channel | Payload |
| --- | --- | --- | --- | --- |
| `0x01` | `HELLO` | server → hub | 0 | JSON text: enrollment JWT + server id |
| `0x02` | `HELLO_ACK` | hub → server | 0 | JSON text: relay session id + tunnel id |
| `0x03` | `CLIENT_CONNECT` | hub → server | new client's channel | `{"client_id","session_id"}` — observability only |
| `0x04` | `CLIENT_DISCONNECT` | hub → server | that client's channel | `{"client_id"}` — observability only |
| `0x05` | `DATA` | server ↔ hub ↔ client | owning client | raw bytes, forwarded verbatim |
| `0x06` | `HEARTBEAT` | either → either | 0 | keep-alive probe / ack |
| `0x07` | `DISCONNECTED` | hub → client | 0 | server tunnel closed; client should reconnect |
| `0x08` | `ERROR` | hub ↔ any | 0 | error condition |
| `0x09` | `HUB_HELLO` | — | 0 | **RETIRED** — reserved in the vendored `RelayFrameType` enum, never emitted; the federation handshake rides JSON **text** frames (`hub_hello`) instead |
| `0x0A` | `HUB_HELLO_ACK` | — | 0 | **RETIRED** — never emitted; signed ack rides a JSON text frame (`hub_hello_ack`) |
| `0x0B` | `HUB_HEARTBEAT` | — | 0 | **RETIRED** — never emitted; federation liveness rides the generic `HEARTBEAT` (`0x06`) |
| `0x0C` | `LIBRARY_SHARE_UPDATE` | — | 0 | **RETIRED** — never emitted; share payloads ride `DATA` (`0x05`) envelopes with a JSON discriminator |
| `0x0D` | `LIBRARY_SHARE_REVOKED` | — | 0 | **RETIRED** — never emitted; revocation payloads ride `DATA` (`0x05`) envelopes |
| `0x0E` | `ADMIN_DELEGATION` | — | 0 | **RETIRED** — never emitted; delegation payloads ride `DATA` (`0x05`) envelopes |
| `0x0F` | `HUB_DISCONNECTED` | — | 0 | **RETIRED** — never emitted; federation goodbye rides the generic `DISCONNECTED` (`0x07`) |
| `0x10` | `HTTP_REQUEST` | hub → server | request id | `RelayHttpRequest` JSON |
| `0x11` | `HTTP_RESPONSE` | server → hub | request id | tagged `HEAD` / `BODY` / `END` chunk (`RelayHttpResponseCodec`) |
| `0x12` | `HTTP_CANCEL` | hub → server | request id | empty — the client abandoned the request |

`HTTP_REQUEST` / `HTTP_RESPONSE` are what back the `/api/v1/servers/{id}/proxy/{path}`
operations in `openapi.yaml`: the hub turns an authenticated HTTP call into a
frame, the server answers with a stream of tagged chunks, and the hub streams
those back to the caller through `ConnectionResponseSink` (which also applies the
per-user `TokenBucket` throttle).

**Federation envelope law.** The `0x09`–`0x0F` codes are retired wire types: they
remain reserved in the vendored `Phlix\Shared\Relay\RelayFrameType` enum, but no
hub implementation ever emits them, and receivers ignore frames carrying them.
All federation traffic rides either JSON **text** WebSocket frames (the signed
handshake) or generic `DATA` (`0x05`) envelopes carrying a JSON payload with a
discriminator key (`shares` / `share_id` / `user_id` + `action`), plus the
generic `HEARTBEAT` (`0x06`) and `DISCONNECTED` (`0x07`) frames. See
`:8805 — hub federation` below.

---

## `:8802` — server relay tunnel

```
ws://hub.phlix.tv:8802
```

Mounted at the bare root; there is no path to parse. The media server dials
**out**, which is the whole point — it works from behind NAT with no inbound port.

`config/server.php` can enable TLS on this listener (`relay_tls`,
`relay_tls_cert`, `relay_tls_key`), in which case it is `wss://`. Unlike `:8803`,
this listener is **not** fronted by HAProxy, so `TrustedProxyResolver` sees the
real peer address directly — and nothing else fronts it either: with `relay_tls`
unset the socket comes up **plaintext `ws://` by default**, which means the HELLO
(enrollment JWT) and every relayed byte travel unencrypted. At boot the relay
worker therefore logs a loud `SECURITY` advisory on the relay channel once per
start when it binds cleartext, and `HUB_RELAY_REQUIRE_TLS=true`
(`relay_require_tls`) turns that into a **fail-closed refusal**: the hub will not
stand up a credential-bearing cleartext listener when the operator has mandated
TLS. This is the listen-side mirror of the server's `PHLIX_RELAY_REQUIRE_TLS`
dial-side knob (phlix-server `config/relay.php`); the warn-only default is
deliberate estate posture — flip both sides in one wave, never one alone.

### Handshake

1. Client (the media server) opens the socket and immediately sends a **JSON text**
   `HELLO` carrying its Ed25519 enrollment JWT and its server id.
2. The hub verifies the JWT against its own published JWKS
   (`GET /.well-known/jwks.json`), registers a `Tunnel` in `TunnelManager`, and
   answers **JSON text** `HELLO_ACK` with a relay session id and a tunnel id.
3. Everything after that is binary `RelayFrame`.

### Rate limiting and close codes

A per-IP connect limiter (`rate_limiter.relay_connect`, 10 per 60s) runs at the
WS-connect hook, *before* `HELLO` is read, closing the H-H1 tunnel-displacement
DoS surface. The worker is `count=1`, so the per-worker limit is a true global
limit.

| Code | Meaning |
| --- | --- |
| `1013` (`RelayWorker::CLOSE_TRY_AGAIN_LATER`) | Connect rate limit exceeded. Transient — back off and reconnect. **Not** an auth failure. |

An authentication failure is a `HELLO`-time `ERROR` frame with code
`unauthorized`, not a close code.

`POST /api/v1/servers/{id}/relay` on the HTTP surface is a **signpost only**: it
always answers `501` with an `X-WS-Endpoint` header pointing at this socket.
The steer is built from `HUB_WS_HOST` (falling back to `HUB_PUBLIC_DOMAIN`,
then the `your-hub-host` placeholder) with a **fixed** `ws://` scheme and the
**fixed default port** `8802` — neither tracks a TLS-terminating proxy nor a
`HUB_RELAY_PORT` that has been env-moved. Behind either of those deployment
shapes, read the header as a host hint only and set the server's
`config/relay.php` `hub_wss_url` explicitly; it is that URL `phlix-server`
actually dials.

### Keepalive and idle reap

This is the one client-facing lane with a complete, deliberate liveness loop:

- **Hub → server:** every 30 seconds
  `HubServicesProvider::startInMemoryReapers()` sends a `HEARTBEAT` (`0x06`,
  ch 0) on every ACTIVE tunnel (`Tunnel::sendHeartbeat()`). It is a pure
  probe — it deliberately does NOT refresh the sender's own liveness stamp.
- **Server → hub:** the server dials on its own schedule too
  (`PHLIX_RELAY_PING_INTERVAL`, server default 30 s) and echoes the hub's
  pings, so every live tunnel lands an inbound frame at least every 30 s.
- **The liveness marker:** only INBOUND frames refresh a tunnel's
  `lastFrameAt` (`Tunnel::onHeartbeat()`). Any traffic counts — data frames
  refresh it the same way — so the heartbeats exist purely for the
  quiet-but-healthy tunnel, never as a tax on a busy one.
- **The reap:** `IdleReaper` scans the live tunnel registry every
  `DEFAULT_INTERVAL_SECONDS` (60 s) and closes any tunnel whose last inbound
  frame is older than `DEFAULT_STALE_THRESHOLD_SECONDS` (90 s), reason
  `timeout`.
- **The coupling:** the reap window MUST stay strictly greater than the ping
  interval (HB-0.1, pinned in the `IdleReaper` class docblock). 90 s against
  a 30 s heartbeat gives ~3 probes of headroom: a healthy-but-quiet tunnel
  is never false-reaped, and a half-open one is gone within a couple of
  minutes.

---

## `:8803` — client mount

> ⚠ **DECLARED UNAVAILABLE** in the deployed multi-process topology
> (review `hub__asphp` M2). A mount can only bind to a Tunnel living in the
> **same** process (per-client queues, throttle buckets, and drain timers are
> keyed on same-process object identity), and tunnels register exclusively on
> the `:8802` relay worker (`TunnelManager::acceptServer` ← `RelayWorker`,
> count=1). This separate `:8803` process resolves its own per-process
> `TunnelManager`, whose registry can never hold an entry. Every mount is now
> refused **loudly**: after the auth ladder completes (limiter → relay token →
> ownership re-confirmation), the controller closes the socket with a real WS
> close frame **1011** (do-not-retry — deliberately not 1013, which invites
> endless reconnect loops against a structurally dead surface) and logs
> `client_mount_bridge_unavailable` on the relay channel. Live
> client-to-server traffic rides the HTTP relay proxy
> (`/api/v1/servers/{id}/proxy/*`). The original cure options (fold the client
> WS onto `:8802`, or bridge per-mount byte streams over the channel broker —
> whose current protocols are request/response shaped) are owner-level
> architecture decisions; the catalog below describes the intended wire if
> either lands.

```
ws://hub.phlix.tv:8803/client/{server_id}
```

Parsed by `ClientRelayWorker::parseServerId()` with the anchored pattern
`~^/client/([^/?#]+)/?(?:[?#].*)?$~`.

> The same path is registered on the `:8800` HTTP surface as
> `GET /client/{server_id}` — **deliberately without the `/api/v1` prefix** every
> other server-facing hub route carries. Adding a prefix there would make the HTTP
> mirror disagree with the regex above. See the S204 notes in
> `src/Application.php`.

### Authentication

The client first calls `POST /api/v1/me/servers/{id}/relay-token` on the HTTP
surface to mint a short-lived, per-user, server-scoped token, then presents it on
the upgrade request in one of two ways, in priority order:

1. `Authorization: Bearer <token>`
2. `Sec-WebSocket-Protocol: bearer, <token>` — browser WebSocket APIs cannot set
   arbitrary headers but can send subprotocols.

The legacy `?token=<…>` query form was **removed in step S2b**: query strings land
in access logs, proxy logs and browser history.

The worker then checks three things, and all three must hold:

1. the token validates (`ClientRelayTokenService::validate()`);
2. the token is scoped to the `server_id` taken from the path;
3. the resolved user still **owns** that server (`ServerInfoHandler`).

A per-IP client-mount limiter (`rate_limiter.client_mount`) runs at the connect
hook before any of that, so a mount flood never reaches token validation. This
listener *is* fronted by HAProxy over loopback with `option forwardfor`, so the
real client IP comes from `TrustedProxyResolver` and not from the loopback peer.

### Messages

If a mount ever binds (see the M2 banner above — in the deployed topology
none ever does), the socket carries `DATA` (`0x05`) in both directions on this
client's channel id, plus `DISCONNECTED` (`0x07`) when the underlying server
tunnel goes away and `ERROR` (`0x08`).

### Keepalive

Moot by construction in the deployed topology: the mount is refused inside
the connect hook (close `1011`), so this surface never holds a long-lived
socket — nothing to ping, nothing to reap, no idle story. If the surface is
rebuilt (the owner-level architecture decision named in the banner above),
the `:8802` heartbeat/reap window is the pattern this repository has already
paid for, and it should cross to the client mount with it.

---

## `:8804` — SyncPlay relay

```
ws://hub.phlix.tv:8804/syncplay/{server_id}
```

Parsed by `SyncPlayRelayWorker::parseServerId()` with
`~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~`.

**Getting there.** The managed installer routes `GET /syncplay/…` on the shared HAProxy
frontend (80/443) straight to this listener — but no shipped browser client dials that
route yet. The env block the managed install writes carries no `HUB_WS_URL`/`HUB_WS_PATH`,
and the shipped dials default to the direct form: `@phlix/ui`'s legacy hub-relay law and
the hub portal's own `buildRelayUrl` both build `ws(s)://<page-host>:8804/…` with the port
fixed by `SyncPlayRelayWorker::DEFAULT_PORT`, never the origin's port (the portal bundle
does not consult the seam). So on a managed install the browser path needs the operator to
`ufw allow 8804/tcp` as well — which serves the plain `ws://<host>:8804/…` form from
HTTP-served pages; `8804` is a plaintext listener, so an HTTPS-served page cannot complete
a direct dial at all, and its only viable path (the same-origin
`wss://<hub-domain>/syncplay/…` route) waits on the disclosed rider that wires shipped
clients to dial it. On `--no-proxy` installs the installer opens `8804/tcp` in ufw (when
ufw is active), which is what makes the plain `ws://<host>:8804/…` form above reachable.
Behind any hand-rolled proxy, replicate the `/syncplay/` pass-through with HTTP/1.1
upgrade headers and an hour-scale read timeout — the hub's managed HAProxy defaults carry
`timeout tunnel 1h`.

JSON text frames throughout — this surface does **not** use the binary frame
format. Room state is held in the worker and messages are broadcast to every
client in the room. The room key is scoped to the authenticated
`(server_id, owner)` pair, so two different servers that pick the same friendly
room name resolve to different internal rooms and can never control each other's
playback.

### Authentication

Identical to `:8803` — the same relay token, minted the same way, and since
**S237** carried the same way: `Authorization: Bearer <token>` or
`Sec-WebSocket-Protocol: bearer, <token>`, extracted by the shared
`ClientRelayWorker::extractClientToken()`. One credential class, one carrier.
The token is then validated by `ClientRelayTokenService::validate()`, scoped to
the `server_id` in the path, and re-checked against server ownership.

A token in the **query string is not accepted** and never was safe to send:
query strings land in access logs, proxy logs and `Referer` headers, and outlive
the token's own expiry. This is why S2b dropped the form from `:8803`.

> ⚠ Before S237 this surface read `$_GET['token']`. That was broken twice over:
> the documented client URL put a live credential in a query string, **and**
> Workerman 5 never populates `$_GET` at all — so the read was always `null` and
> **every** `:8804` connect was rejected as unauthorized. Any client still
> appending `?token=…` here was never authenticating; it must move the token to
> a header or the `bearer` subprotocol.

### Rejection close codes

Since **M-HUB-17** the sentence above — "Identical to `:8803`" — is true for the
failure signal too, not just the credential carrier. Refusals ride the same
`WebSocketCloseFrame::reject()` discipline the relay lanes shipped for L-4: the
`101` completes and a REAL WS close frame follows, so the close event carries a
code instead of collapsing into a bare transport-level `1006`.

| Code | Emitted by | Meaning — the device's remedy |
| --- | --- | --- |
| `4401` (`SyncPlayRelayWorker::CLOSE_UNAUTHORIZED`) | `onWebSocketConnect` auth gate + both join-time guards | No token / bad / expired token, token bound to a foreign `server_id`, revoked owner — all bundled into ONE code by design (an enumeration oracle stays closed, same law the claim-code surface obeys). Remedy: re-login / re-mint via `POST /api/v1/me/servers/{id}/relay-token`. |
| `1011` (`SyncPlayRelayWorker::CLOSE_DO_NOT_RETRY`) | path with no `server_id` segment | Malformed dial URL. Do **not** reconnect-loop: 1013 "Try Again Later" is deliberately not used here, for the reason `ClientMountController` records at its own 1011 site — waiting never cures a typo. |
| `1013` (`SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT`) | liveness reap: silence ≥ 90 s past the last inbound frame (M-HUB-18) | The hub gave up on a socket that stopped answering. Transient by nature (half-open path, dropped NAT mapping, sleeping device) — reconnecting IS the remedy, which is exactly why this is 1013 and not 1011. |

There is still no connect-rate-limiter close on this surface: the
per-connection inbound byte budget DROPS over-budget frames rather than closing
the socket, so the only throttle-shaped signal is the drop itself. `1013` here
belongs solely to the liveness reap.

**Carrier caveat (true of `:8803`'s table as well).** A client that dials with
the `bearer` subprotocol and is refused still sees `1006` in the browser's close
event: the S355 no-echo law means the refused `101` selects no subprotocol, and
WHATWG treats a no-selection answer as a fatal handshake error before the close
frame can surface. Header-carrier clients observe the codes above verbatim.

### Two vocabularies, one socket (owner decision #14)

This surface speaks **two message vocabularies** and never translates between them:

- the **legacy BARE room dialect** (`group_join`, `room_state`, `playback_*`, …) —
  the original relay vocabulary, which has **no live client consumer in the estate**
  (zero-runtime-hit evidence in `SyncPlayRelayWorker`'s class docblock). Its
  handlers stay for wire compatibility.
- the **CANONICAL `syncplay_*` catalog** — the vocabulary of the media server's
  `:8097` socket and every syncplay client, defined by
  [`phlix-syncplay/SPEC.md`](../../phlix-syncplay/SPEC.md) §3 (19 closed-set types).
  This is the **live room lane**: relay-mode syncplay clients (mobile is the first)
  create, join, and steer rooms entirely in this catalog.

**Per-connection dialect latch.** A connection is `bare` by default and latches to
`canonical` the instant it sends its first `syncplay_*`-prefixed frame; the latch
is permanent for the life of the socket. The latch governs both directions.
Outbound: every reply and every room fan-out to that socket is encoded in its
latched dialect — the fan-outs filter by dialect (`broadcastToRoom()` skips
canonical members; `broadcastCanonical()` skips bare ones). Inbound: the bare
room family (`group_join`, `playback_*`, `time_sync`) arriving on a
canonical-latched socket is refused with the closed canonical floor's own answer
— `syncplay_error` `UNKNOWN_MESSAGE`, naming the refused frame — and mutates
nothing, so no bare-handler reply is ever produced to leak; bare `group_leave`
instead routes by the latch into the canonical teardown. Net law: a canonical
client never sees a bare room frame and a bare client never sees a canonical one
— even if (hypothetically) the two shared a room. The one lane outside the claim
is by design: `pending_command` is addressed to the user's sockets, not to a
room, and reaches every matching socket in either dialect (see below).

**Rooms are shared bookkeeping, dialects are not.** Both vocabularies address the
same `(server_id, owner)`-scoped rooms and the same playback anchor store; the
canonical `group_id`/`group_name` simply rides as the friendly name inside the
existing scoped key. An owner-scoped room therefore stays owned by its token
holder regardless of which dialect created it. (A canonical client that joins a
room a bare client created finds it, but the bare creator has no `host` concept —
see the deferrals note under the canonical table.)

### Message catalog — legacy BARE dialect

Client → hub:

| `type` | Meaning |
| --- | --- |
| `group_join` | Join a room. Answered with `room_state`; the *other* members get `client_joined` — never the joiner. |
| `group_leave` | Leave the current room. Remaining members get `client_left`. |
| `playback_play` | Relay "play" to the room. |
| `playback_pause` | Relay "pause" to the room. |
| `playback_seek` | Relay a seek position to the room. |
| `time_sync` | Clock probe. Answered with `time_sync_reply`. |

Hub → client:

| `type` | Meaning |
| --- | --- |
| `room_state` | Sent to the joiner: `clients` (member map) **and** `playback` — the room's last relayed playback anchor (`{type, from_client_id, timestamp, position?, media_id?}`) or `null` in a room that has never played. A client joining mid-session gets the position anchor from this frame alone. |
| `client_joined` | Another client joined the room. Deliberately never echoed to the joiner — that sentence is the contract. |
| `client_left` | Another client left the room (explicitly or by disconnect). |
| `time_sync_reply` | Answer to a `time_sync` probe. `server_time` is stamped by the hub in **unix milliseconds**; `client_time` is echoed back untouched, whatever scale the client probed with. |

### Message catalog — CANONICAL `syncplay_*` dialect

Every frame carries the SPEC §2 envelope the hub's factory owns: `type`,
`protocol_version: 1`, and a hub-stamped `timestamp` in **unix milliseconds** —
client-supplied copies of all three are stripped, so a forged envelope cannot
survive the relay. `member_id` on every outbound command frame is the hub's
authoritative client id for the acting connection (SPEC §9), never the sender's
claim.

Client → hub (inbound; the catalog is a **closed set** — an unknown `syncplay_*`
name gets `syncplay_error` `UNKNOWN_MESSAGE`, matching `:8097`'s strictness):

| `type` | Meaning |
| --- | --- |
| `syncplay_group_create` | Open (or re-enter) the owner-scoped shadow room named by `group_name` (default `New Group`). First member is host. Answered with `syncplay_group_state` (`{group, your_id}`); other members get `syncplay_info`. |
| `syncplay_group_join` | Same as create but keyed by `group_id` (must be a non-empty string, else `syncplay.group_not_found`). Join implies leave — moving rooms is one operation. `password_hash` is accepted and **ignored** (see the auth note above). |
| `syncplay_group_leave` | Leave the current group. The leaver gets an `syncplay_info` ack; remaining members get a fresh `syncplay_group_state` (and `syncplay_host_elect` first, if the host left). Roomless → `syncplay_error` `syncplay.leave_failed`. |
| `syncplay_playback_play` / `_pause` / `_seek` | **Host-only** control (non-host → `syncplay_error` `NOT_HOST`). The host's `play` is acked to it and relayed to the others; `pause`/`seek` go to the others only — the `:8097` law, deliberately opposite the bare sender-included echo above. Outbound frames carry no `group_id` (server shape). |
| `syncplay_playback_sync` | State report relayed to **every** member including the reporter, carrying the room truth: current anchor `position`, `current_media_id`, `is_playing`, `member_id` = the host, and the hub's `server_time` in **unix ms** (documented deviation: `:8097` answers this in seconds — SPEC §2's ms law governs here). |
| `syncplay_playback_queue` | **Host-only** queue replacement, normalized all-or-nothing (string `media_id` required; `media_info` keeps only string keys) and capped at 1000. Over-cap → `syncplay_error` `syncplay.queue_limit_exceeded` with the stored queue **unchanged**; accepted → `syncplay_group_state`-independent `syncplay_playback_queue` broadcast to all. |
| `syncplay_chat` | Relayed to every member including the sender, stamped with authoritative `member_id`/`member_name`; a blank message is silently dropped; roomless → `NOT_IN_GROUP`. |
| `syncplay_typing` | Relayed to the **other** members only. Roomless is silently ignored (server's shape). |
| `syncplay_host_transfer` | The server's guard ladder verbatim: `NOT_IN_GROUP` → `NOT_HOST` → `INVALID_NEW_HOST` → `MEMBER_NOT_FOUND` → `SAME_HOST`; success broadcasts `syncplay_group_state` to all. |
| `syncplay_group_list` | Direct reply `{groups: [{id, name, member_count, has_password: false, current_media, is_playing}], count}` listing only the connection's own `(server_id, owner)` shadow rooms. |
| `syncplay_time_ping` | Roomless OK. Answered directly with `syncplay_time_pong` (`client_time` echoed, `server_time` unix ms). |
| `syncplay_time_sync` | **Refused** with `syncplay_error` `hub.protocol_unsupported` — the relay holds no media clock authority; clients use `syncplay_time_ping`. |

Hub → client (outbound, only to canonical-latched sockets):

| `type` | Meaning |
| --- | --- |
| `syncplay_group_state` | `{group, your_id}` to a joiner (your_id = hub-minted client id); broadcast to **all** members on host change or a member leaving (broadcasts carry no `your_id`). `group` follows the server's `GroupState::getState()` shape: `group_id`/`group_name`, `members` as a DICT keyed by client id (`{id, name, is_host, joined_at}`), `host_id`, `current_media_id`, `playback_position` (ms), `playback_state`, `queue`, `created_at`/`last_activity_at` — and, like the server, `joined_at`/`created_at`/`last_activity_at` are **unix seconds** while `playback_position` is ms. |
| `syncplay_info` | SPEC §6 membership announcements: `"{name} joined the group"` to the other members (top-level `member_id`/`member_name`, never echoed to the joiner) and `"{name} left the group"` as the leave ack to the leaver. |
| `syncplay_host_elect` | `{elected_id, elected_by}` broadcast to all when the host leaves a non-empty room, immediately followed by the fresh `syncplay_group_state`. Election is oldest-membership (join order), ties broken by roster order. |
| `syncplay_playback_play` / `_pause` / `_seek` / `_sync` / `_queue` / `_chat` / `_typing` | Canonical relay fan-outs — see each inbound row above for its audience law. |
| `syncplay_time_pong` | Answer to `syncplay_time_ping`; `server_time` unix ms. |
| `syncplay_error` | `{error_code, message}` for every refused canonical op. The canonical floor answers **loudly** — unlike the bare verbatim relay, no canonical refusal is silent unless the server's own shape is silent (blank chat, roomless typing). |

> **Deferrals, stated honestly.** The relay does not implement the server's
> `:8097`-only `time_sync` *status* answer or the S446 out-of-sync **nudge**, and
> there is no cross-dialect translation. A canonical client in a room first seeded
> by a bare `group_join` finds no host (bare members have no `is_host`), so its
> host-gated ops fail loud with `NOT_HOST` until a canonical member exists; with
> zero live bare consumers this boundary is documented rather than papered over.

**Timestamps: milliseconds, and only where the hub stamps them.** `playback_*`
frames relayed by the hub carry `timestamp` in **unix milliseconds**, and
`time_sync_reply.server_time` is likewise ms — this matches the syncplay
transport clock contract (`phlix-syncplay/SPEC.md` §2: "timestamp … in
milliseconds") so client NTP/position math never mixes a 1000x-scaled value.
The one deliberate seconds-scaled field on this surface is the S93
`pending_command.issued_at`, pinned to unix seconds by `openapi.yaml` **and**
by its only consumer (`@phlix/ui` `src/api/hubRelay.ts`, S298); it is
delivery metadata, not a transport timestamp.

**Bare playback relays reach every member, sender included; canonical ones follow
`:8097`.** The legacy `:8804` direction contract is "broadcast to every client in
the same room", so a bare sender's own `playback_*` frame comes back with the hub's
authoritative `from_client_id` and `timestamp` stamped on it — the echo is the hub's
acceptance signal. The canonical dialect instead mirrors the media server exactly:
play is acked to the host and relayed to the others, pause/seek exclude the sender,
and only `syncplay_playback_sync` (a state report, not a command) reaches everyone.
Separate dialects, deliberately separate echo semantics — see SPEC §9.1.

**Each dialect has its own floor.** The legacy catalog above is a *floor, not a
closed set*: `SyncPlayRelayWorker::onMessage()` relays any unrecognised but
*properly-named bare* `type` verbatim to the rest of the room, so bare clients can
agree on extensions without the hub knowing about them. The canonical catalog is a
*closed set* of 19 (SPEC §3): any unknown `syncplay_*` name is refused with
`syncplay_error` `UNKNOWN_MESSAGE` — a mistyped room op must never fan out through
a relay as if it were an extension. In either dialect, a frame that is not a JSON
object, or carries a missing or non-string `type`, is **malformed and dropped** —
never relayed. Junk must not become fan-out.

**Every connection carries an inbound byte budget** (`TokenBucket`, sized per
process from `SyncPlayRelayWorker::INBOUND_RATE_BYTES_PER_SECOND` /
`INBOUND_BURST_BYTES` — 128 KiB/s sustained, 256 KiB burst by default). The
bucket starts full, so a connection's first frames are never throttled; past
the budget, inbound frames are dropped (logged once per connection, silently
thereafter) rather than the socket being killed — an over-eager client
recovers as the bucket refills. This bounds what one socket can force the
worker — and, through the verbatim relay, its whole room — to process.

### Keepalive, idle sockets and client duties

Since **M-HUB-18** this surface runs a server-originated keepalive with a
matching idle reap — the same 30 s / 90 s window math as `:8802`, deliberately
imported so one law governs both sockets:

- **We ping you.** A liveness timer armed in `onWorkerStart()` sends every
  registered socket a bare WebSocket *ping* (opcode 0x9, empty payload) every
  `SyncPlayRelayWorker::LIVENESS_PING_INTERVAL_SECONDS` (30 s). Browser-class
  consumers need no code for it: every real client stack on this surface (the
  ui bundle on Chromium/WebKit, RN's SocketRocket/OkHttp, Roku
  `Device.webSocket`) answers a WS ping inside the socket layer, so a healthy
  app can stay silent at the app level and still be counted alive.
- **Silence does evict — at a bounded point.** ANY inbound frame — data, ping,
  pong — refreshes the client's `lastSeenAt`. Silence at or past
  `LIVENESS_REAP_AFTER_SILENCE_SECONDS` (90 s) closes the socket with a real
  `1013` close frame. The window is strictly greater than the ping cadence
  (the `IdleReaper` HB-0.1 coupling law, same 3× margin): a client always gets
  its answer counted before eviction is even considered. A throttled frame is
  still traffic — the budget drop happens AFTER the stamp, so a loud client is
  never evicted for silence it demonstrably did not produce.
- **Ghost members are now bounded — and a ghost HOST is the whole point.**
  Before M-HUB-18 a half-open host (dead device, expired NAT mapping) sat in
  the room's host book forever: pure TCP liveness never notices, the 60-second
  sweep only drops EMPTY rooms, and every member's host-gated command died
  behind a sender-only `NOT_HOST` — the party frozen indefinitely. A reaped
  ghost's close runs the SAME `onClose()` canonical leave a graceful
  disconnect takes, so the oldest remaining member is elected and the room
  hears `syncplay_host_elect` within roughly one reap window of the host dying.
- **What traffic updates.** The liveness counter is per-connection and
  refreshed by EVERY inbound frame — deliberately broader than the ROOM-level
  `last_activity_at` carried in `syncplay_group_state`, which canonical
  join/leave, `syncplay_playback_*` and `syncplay_host_transfer` frames refresh
  (chat, typing and time-pings do not). That stamp stays wire truth mirroring
  the server's group-state shape; nothing on this surface reaps on it. The two
  counters answer different questions and are never conflated.
- **Client duties.** Liveness itself: none — answering the server ping is
  socket-layer work every supported consumer already does. The rest of the
  de-facto contract is unchanged: treat the close event as the authoritative
  disconnect signal; to rejoin after any drop (a 1013 reap included — that
  code means "reconnect"), dial again with the same relay token while it is
  valid (re-mint via `POST /api/v1/me/servers/{id}/relay-token` once it is
  not) and send `syncplay_group_join` — join implies leave, and the returned
  `syncplay_group_state` re-anchors everything. `syncplay_time_ping` on a
  comfortable cadence remains the app-level clock sync and NAT warmth — now a
  convenience, no longer the only keepalive the vocabulary offers.

---

## `:8805` — hub federation

```
ws://master-hub.example:8805/relay/federation/{hub_id}
```

Parsed by `FederationWorker::parseHubId()` with
`~^/relay/federation/([^/?#]+)/?(?:[?#].*)?$~`. Leaf hubs dial the master hub;
this worker is the master-side listener.

**Dial-side schemes (leaf → master).** The configured peer URL may be
`https://` / `wss://` (a master behind a TLS-terminating proxy — the common
production shape), `http://` / `ws://` (plaintext), or a bare host. A bare
host is defined by what it is NOT: any scheme outside the plaintext `http`/`ws`
pair — and no scheme at all — selects TLS (the legacy secure-by-default
fallback), so `hub.example.com` dials exactly like `https://hub.example.com`.
`FederationPeerManager::buildMasterDialPlan()` maps those TLS peers onto the
workerman WSS-client idiom: the URI is always rebuilt as
`ws://<host>[:<port>]/relay/federation/<leaf_hub_id>` from the parsed host and
port (a bare host without a port carries none, so the dial lands on the
transport default — 443 for TLS, not 8805), with `transport = 'ssl'` and a
strict stream context (`verify_peer`, `verify_peer_name`, `SNI_enabled`,
`peer_name` from the configured host). Verification is strict by design: no
`verify_peer = false` escape hatch ships. For a master with a private-CA
certificate, set `PHLIX_FEDERATION_CA_BUNDLE` to an absolute PEM bundle path
(unset = system trust store; set-but-unreadable = the dial is refused loudly).
TLS dials additionally require the openssl PHP extension at runtime — without
it the dial is refused with a log line naming the extension. This listener
itself stays plaintext unless the master enables native TLS (see below); when
the master advertises an `https://` / `wss://` URL, termination happens at its
upstream proxy — a shape nothing in the repo can verify from the listen side,
which is why the plaintext bind now announces itself.

**Listener-side TLS posture (master accept end, M-HUB-22).** The `:8805`
worker mirrors the `:8802` law (c32bb524): `federation_tls`
(`HUB_FEDERATION_TLS`, with `HUB_FEDERATION_TLS_CERT` / `HUB_FEDERATION_TLS_KEY`)
serves the listener as native `wss://` with the same ECDHE+AEAD server context
as the relay trio. With TLS unset the bind stays plaintext `ws://` — correct
for the managed install, where HAProxy fronts this listener and terminates TLS
upstream — but every boot then logs one loud `SECURITY` advisory on the relay
channel: the hub-to-hub link carries the H-4 ceremony and all federation
payloads (share offers' library metadata), the ceremony is signature-based yet
nothing in its canonical strings binds the TLS channel, so an active on-path
adversary on a raw-exposed `:8805` (a `--no-proxy` install is exactly that)
relays both ceremony legs untouched, gets both hubs to mark VERIFIED, and
reads/mutates everything after. `HUB_FEDERATION_REQUIRE_TLS=true`
(`federation_require_tls`) turns the advisory into a **fail-closed refusal**:
`FederationWorker::start()` throws before constructing the listener, so a
mandated-secure hub never binds a cleartext federation socket (the skip is
loud — the refusal text names the consequence and the exits). The warn-only
default is deliberate estate posture: a mandate flip moves both ends of the
link in one wave, never one alone, and the proxy-terminated topology must not
be punished by default.

**Getting there.** The managed installer routes `GET /relay/federation/…` on the shared
HAProxy frontend (80/443) to this listener, so a master installed with `scripts/install.sh`
already satisfies the upstream-TLS shape above through its existing 443 entry — no extra
firewall port. On `--no-proxy` installs the installer opens `8805/tcp` in ufw (when ufw is
active), which is what makes the plain `ws://<host>:8805/…` form reachable; a master that
advertises `wss://` instead needs the path routed through its TLS frontend with the same
HTTP/1.1-upgrade + hour-scale-timeout treatment as `:8804` (the managed HAProxy defaults
carry `timeout tunnel 1h`). Federation ingress is only required on hubs that act as
masters; a leaf-only hub may keep the port closed.

The worker is constructed in `HubServicesProvider::boot()` — that is, in the
**master process, before `Worker::runAll()` forks**, because a Workerman `Worker`
must exist before `runAll()`. It is not started from `Application::run()` like the
other three.

### Lifecycle

1. WS upgrade — parse `hub_id` from the path. A path that does not match is closed
   with reason `invalid_path`.
2. Per-IP connect rate limit (see below) — over-budget upgrades are refused with a
   real WS close frame `1013` BEFORE any `federation_peers` DB read.
3. The `hub_id` must already be a known peer in `federation_peers`; otherwise the
   connection is closed with reason `Unknown hub`. There is no self-service
   enrollment on this socket — a peer is created through
   `POST /api/v1/me/federation/peers` first.
4. On success the connection is handed to `FederationRelayController::onConnect()`;
   later frames pass the per-peer frame budget (see below) and go to `onMessage()`;
   `onClose()` runs on link end.

**Close-reason conventions.** The close **code** is the machine contract (the
table below pins the only coded refusal, `1013`); the **reason text** riding
the frame is a diagnostic, and it follows two registers. Snake_case tokens —
`invalid_path`, `internal_error` — are stable identifiers: safe to log-match
and grep, they are never branch-on contracts for peers. Capitalized free prose
— `Unknown hub` — is a human-facing sentence, not a stable identifier, and may
be reworded without notice. Clients and tooling must key behavior on the code
alone and treat every reason string as advisory.

### Rate limiting and close codes (M-HUB-14)

Two limiter surfaces, both worker-local in-memory counters on the count=1
federation worker — per-worker is global here, the same posture as `:8802`'s
connect guard above. Neither limiter introduces HTTP semantics or new wire
codes: WS≠HTTP, refusals are real close frames, and on the leaf leg a plain
link drop.

| Profile | Key | Default | Refusal |
| --- | --- | --- | --- |
| `rate_limiter.federation_connect` | `federation_connect:<trusted client ip>` | 10 per 60 s | close frame `1013`, before any DB read |
| `rate_limiter.federation_frame` | `federation_frame:<remote peer hub id>` | 120 per 60 s | master side: close frame `1013`, before controller dispatch; leaf side: link drop → capped reconnect ladder re-dials |

The client IP is the proxy-resolved address (the managed install fronts
`:8805` through HAProxy, so the raw TCP peer is loopback — keying on it would
collapse every leaf into one bucket). The frame bucket is per REMOTE peer hub
id and ONE limiter instance serves both roles in the :8805 process (the
worker installs it into `FederationPeerManager` at boot): a hub's traffic is
budgeted identically whichever end of the pair counts it, and a reconnecting
peer cannot reset its window inside it.

Sizing law (pinned by `RateLimitProfilesTest`): a legitimate leaf dials at
most once per minute (its backoff ladder caps at 60 s) and sustainably moves
~4 heartbeat frames per minute per link, so both budgets sit an order of
magnitude above legit traffic. The guard's purpose is to make refusal
CHEAP — the connect limiter runs before the `federation_peers` SELECT it
protects, and it is deliberately NOT DB-backed for that reason.

When the container cannot provide a limiter, both guards fail OPEN
(unlimited) with one named error line each — a limiter misconfiguration must
never deny federation to paired hubs (identical to `:8802`/`:8803`).

| Code | Meaning |
| --- | --- |
| `1013` (`FederationWorker::CLOSE_TRY_AGAIN_LATER`) | Connect or frame budget exceeded. Transient — the capped reconnect ladder re-dials after the window. **Not** an auth failure. |

### Signed handshake (JSON text frames)

The federation channel is authenticated by a **mutual Ed25519 proof-of-key
ceremony** (H-4). Knowledge of the `hub_id` is only a routing hint — it proves
nothing. All three handshake messages ride JSON **text** WebSocket frames (never
binary `0x09`/`0x0A`, which are retired wire types):

1. **`hub_hello`** — leaf → master:
   `{"type":"hub_hello","protocol_version":1,"hub_id":...,"hub_name":...,"public_key":...,"role":"leaf","capabilities":[...]}`.
   The master resolves `hub_id` to a peer row and replies; the leaf's
   self-declared `public_key` is never trusted as proof.
   `protocol_version` is the negotiated wire version (minors campaign M-HUB-11):
   an ABSENT field means a legacy build speaking version 1 and is accepted; a
   present value other than `1` is refused with `protocol_version_mismatch`
   BEFORE the key lookup or any state/audit write (logger warning only —
   pre-auth frames must not write audit rows).
2. **`hub_hello_ack`** — master → leaf:
   `{"type":"hub_hello_ack","protocol_version":1,"session_id":...,"master_hub_id":...,"role":"master","capabilities":[...],"nonce":...,"signature":...}`.
   `nonce` is fresh per session (base64url, 32 random bytes). `signature` is
   base64 Ed25519 over the canonical string
   `phlix-federation/hello-ack/v1\n{session_id}\n{master_hub_id}\n{nonce}`,
   signed with the **master hub's** keypair (`Ed25519KeyManager`). The leaf
   verifies against the master's `public_key` it holds for that peer and
   cross-checks the identity bindings; verification failure closes the link and
   arms the reconnect backoff. The leaf applies the same version law to the
   ack — a mismatched `protocol_version` is refused (`protocol_version_mismatch`,
   loud close + backoff) before the proof fields are read; the ACK signature
   covers session/id/nonce only, not the version field.
3. **`hub_hello_auth`** — leaf → master:
   `{"type":"hub_hello_auth","leaf_hub_id":...,"session_id":...,"signature":...}`.
   `signature` is base64 Ed25519 over
   `phlix-federation/hello-auth/v1\n{session_id}\n{nonce}\n{leaf_hub_id}` — the
   exact `nonce` from the ack, proving the leaf saw the genuine (signed) ack —
   signed with the **leaf hub's** keypair whose public half is registered on the
   master's peer row. The `nonce` is single-use: a replayed proof is refused.
   The proof is also bound to its **carrier socket**: a late AUTH delivered
   over a connection the master already superseded is refused as
   `handshake_stale_connection` — before the nonce burn — and can never verify
   the replacement link. A pending ceremony is additionally time-bounded
   (minors campaign M-HUB-11): after 30 s an unconsumed handshake is dead on
   arrival (`consumeHandshake()` reports it absent, so a very late proof is
   refused like a replay), and the master's heartbeat arm closes the half-open
   link with `DISCONNECTED` `{reason: hello_auth_timeout}` so the leaf
   re-hellos instead of squatting unverified forever.

Both ends derive the canonical strings from the one shared helper,
`Phlix\Hub\Federation\FederationHandshake`, so the two sides cannot drift.

### Verified-channel gate

The connection is marked **verified** only after both proofs succeed. Until
then, every sync payload — offers, revocations, admin delegations — is **dropped
and audited** on both roles (`logFailedAuth('federation_data_frame_before_verification')`).
Master-side pushes (`FederationMasterPusher::pushOffer` / `pushRevocation`)
refuse to send on an unverified channel. The only unverified-path exemption is
teardown (`closePeerConnection`, which sends `DISCONNECTED` and closes).

### Sync payloads (binary `DATA` envelopes, JSON discriminator)

Post-verification traffic uses the shared binary frame format on the **generic**
frame types — there are no federation-specific binary types in use:

| Envelope | Direction | JSON payload | Meaning |
| --- | --- | --- | --- |
| `DATA` (`0x05`) ch 0 | both → both | `{"shares":[{"id","peer_id","library_id","library_name","permission","status"}]}` | Federated library offers created/changed. **Emitted and accepted in BOTH directions**: the master pushes its offers down (`FederationMasterPusher::pushOffer`, plus the post-hello replay) and the leaf pushes its own up (`FederationPeerManager::pushLibraryShare`); each receiver surfaces them at `GET /api/v1/me/federation/library-shares/incoming`. |
| `DATA` (`0x05`) ch 0 | both → both | `{"share_id":...}` | An offer was withdrawn — emitted by either side (`pushRevocation` on the master side, `pushLibraryShareRevoked` on the leaf side), accepted by both handlers. |
| `DATA` (`0x05`) ch 0 | reserved: master → leaf | `{"user_id":...,"peer_id":...,"action":"grant"|"revoke"}` | An admin delegation grant/revoke. **Receive-only forward-compat path**: the leaf consumer is implemented and verified-gated (`FederationPeerManager::handleAdminDelegation`), and since R17-07 validates before writing — the frame `peer_id` must equal the channel's ceremony-bound hub uuid (cross-check only; writes stamp the LOCAL peer row id) and `user_id` must resolve in this hub's `users` table, else the payload is refused with a snake_case audit reason and zero persistence. The master-side producer is NOT implemented — `createAdminDelegation` writes only the DB row and audit — so this envelope is never emitted today. |
| `HEARTBEAT` (`0x06`) ch 0 | both → both | empty | keep-alive every 15 s once verified |
| `DISCONNECTED` (`0x07`) ch 0 | either → either | `{"reason":...}` | clean close (e.g. `peer_deleted`) |

The dedicated `0x09`–`0x0F` codes are **retired wire types**: reserved in the
vendored `RelayFrameType` enum for compatibility, never emitted, ignored on
receipt.

---

## `:8097` — media server events and SyncPlay (`phlix-server`)

```
ws://media-server:8097/
```

Not this repository's socket. It is documented here because a hub client
reaches it either directly (when the network allows) or through the hub's
HTTP relay proxy (`/api/v1/servers/{id}/proxy/*`) — the `:8803` WS mount that
once promised to carry it is declared unavailable (M2 banner above) — and its
SyncPlay vocabulary is mirrored on `:8804`, so the two catalogs are read
together. Source of truth: `phlix-server/src/Server/WebSocket/WebSocketEvents.php`
and `WebSocketServer.php`.

Behind HAProxy over loopback in production, so the real client IP is resolved from
`X-Forwarded-For`.

### Envelope

Inbound (client → server) messages are read as:

```json
{ "type": "<event>", "data": { } }
```

Outbound (server → client) messages are:

```json
{ "type": "<event>", "data": { }, "timestamp": 1750000000 }
```

…except SyncPlay control messages, which use a **flat** form
(`Connection::sendFlat()`): `{"type": "<event>", ...payload, "timestamp": <unix>}`.

### Authentication

The socket accepts an unauthenticated connection and immediately sends
`connected`. Only four types may be sent before authenticating —
`ping`, `pong`, `auth_request`, `connected`. Everything in the privileged list
below, plus **every** type beginning `syncplay_`, requires an authenticated
connection. A per-surface connect limiter (30 per 60s, in-memory; the worker is
`count=1` so that is global) guards the upgrade.

### Event catalog

| Group | Events |
| --- | --- |
| Connection | `connected`, `disconnected`, `client_disconnected` |
| Authentication | `auth_request`, `auth_success`, `auth_failure` |
| Session | `session_start`, `session_end`, `session_join`, `session_leave` |
| Playback | `playback_start`, `playback_pause`, `playback_stop`, `playback_progress`, `playback_seek` |
| SyncPlay | `syncplay_group_create`, `syncplay_group_join`, `syncplay_group_leave`, `syncplay_playback_sync`, `syncplay_time_ping` / `syncplay_time_pong` |
| Dashboard | `subscribe_dashboard`, `dashboard_now_playing` |
| Misc | `library_updated`, `notification`, `error`, `ping`, `pong` |

Public (pre-auth) events: `ping`, `pong`, `auth_request`, `connected`.
Privileged events: the whole Session, Playback and Dashboard groups, plus every
`syncplay_*` type.

> The SyncPlay names above are the **canonical** wire strings of
> `phlix-syncplay/SPEC.md` §3 — that spec is the single source of truth for
> this vocabulary (see §1: underscore-prefixed `syncplay_group_create`, never
> the pre-SPEC spellings `syncplay_create_group` / `syncplay_join_group` /
> `syncplay_leave_group` / `syncplay_sync_state` / `syncplay_sync_request`,
> which this document listed until audit L-8 corrected it).
>
> The two SyncPlay vocabularies are **distinct frame families that now share one
> socket**. The hub's `:8804` relay historically spoke only the bare vocabulary
> (`group_join` / `playback_play` / `time_sync`); since owner decision #14 it also
> speaks the media server's canonical `syncplay_*` catalog (`syncplay_group_join` /
> `syncplay_playback_sync` / `syncplay_time_ping`) as its live room lane, latching
> per connection and never translating across the two. `:8097` speaks only the
> canonical catalog. The two transports remain separate wire contracts — where
> they share a type name, payload shapes and echo laws still differ (the hub's
> canonical dialect stamps ms where `:8097` answers `syncplay_playback_sync` in
> seconds, and the hub has no clock authority or S446 nudge).
