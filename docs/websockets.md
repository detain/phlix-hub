# Phlix WebSocket surfaces

Companion to [`openapi.yaml`](../openapi.yaml), which describes the hub's HTTP
surface on `:8800`. This document covers everything that is **not** HTTP: five
WebSocket surfaces across two repositories.

| Port | Surface | Repository | Worker | Encoding |
| --- | --- | --- | --- | --- |
| `8802` | Server relay tunnel | `phlix-hub` | `Phlix\Hub\Relay\RelayWorker` | JSON handshake, then binary `RelayFrame` |
| `8803` | Client mount | `phlix-hub` | `Phlix\Hub\Relay\ClientRelayWorker` | binary `RelayFrame` |
| `8804` | SyncPlay relay | `phlix-hub` | `Phlix\Hub\SyncPlay\SyncPlayRelayWorker` | JSON text |
| `8805` | Hub federation | `phlix-hub` | `Phlix\Hub\Relay\FederationWorker` | signed JSON handshake, then binary `DATA` envelopes |
| `8097` | Media server events + SyncPlay | `phlix-server` | `Phlix\Server\WebSocket\WebSocketServer` | JSON text |

The four hub surfaces are also declared under the `x-phlix-websockets` extension
in `openapi.yaml`. They are not OpenAPI `paths`: the `:8802` tunnel mounts at the
bare root, and that path key (`/`) already belongs to the `:8800` HTTP redirect
into the SPA. `tests/Unit/Http/RouteRegistration/OpenApiSpecMatchesRouterTest.php`
pins each hub surface's declared port against the PHP that defines it, so a port
that moves in code and not in the document fails the build.

> `:8097` belongs to `phlix-server` and this repository's CI clones one checkout,
> so nothing here asserts against it. It is documentation, not a contract test.

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
real peer address directly.

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
always answers `501` with an `X-WS-Endpoint` header naming this socket. It is the
path `phlix-server` derives from `config/relay.php`'s `hub_wss_url`.

---

## `:8803` — client mount

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

Once bound, the socket carries `DATA` (`0x05`) in both directions on this
client's channel id, plus `DISCONNECTED` (`0x07`) when the underlying server
tunnel goes away and `ERROR` (`0x08`).

---

## `:8804` — SyncPlay relay

```
ws://hub.phlix.tv:8804/syncplay/{server_id}
```

Parsed by `SyncPlayRelayWorker::parseServerId()` with
`~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~`.

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

Empty rooms are swept by a 60-second timer in `onWorkerStart()`.

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
production shape), `http://` / `ws://` (plaintext), or a bare host.
`FederationPeerManager::buildMasterDialPlan()` maps TLS peers onto the
workerman WSS-client idiom — a `ws://` URI with `transport = 'ssl'` and a
strict stream context (`verify_peer`, `verify_peer_name`, `SNI_enabled`,
`peer_name` from the configured host). Verification is strict by design: no
`verify_peer = false` escape hatch ships. For a master with a private-CA
certificate, set `PHLIX_FEDERATION_CA_BUNDLE` to an absolute PEM bundle path
(unset = system trust store; set-but-unreadable = the dial is refused loudly).
TLS dials additionally require the openssl PHP extension at runtime — without
it the dial is refused with a log line naming the extension. This listener
itself stays plaintext; TLS on the master side terminates upstream (nginx /
Caddy) when the master advertises an `https://` / `wss://` URL.

The worker is constructed in `HubServicesProvider::boot()` — that is, in the
**master process, before `Worker::runAll()` forks**, because a Workerman `Worker`
must exist before `runAll()`. It is not started from `Application::run()` like the
other three.

### Lifecycle

1. WS upgrade — parse `hub_id` from the path. A path that does not match is closed
   with reason `invalid_path`.
2. The `hub_id` must already be a known peer in `federation_peers`; otherwise the
   connection is closed with reason `Unknown hub`. There is no self-service
   enrollment on this socket — a peer is created through
   `POST /api/v1/me/federation/peers` first.
3. On success the connection is handed to `FederationRelayController::onConnect()`;
   later frames go to `onMessage()` and `onClose()`.

### Signed handshake (JSON text frames)

The federation channel is authenticated by a **mutual Ed25519 proof-of-key
ceremony** (H-4). Knowledge of the `hub_id` is only a routing hint — it proves
nothing. All three handshake messages ride JSON **text** WebSocket frames (never
binary `0x09`/`0x0A`, which are retired wire types):

1. **`hub_hello`** — leaf → master:
   `{"type":"hub_hello","hub_id":...,"hub_name":...,"public_key":...,"role":"leaf","capabilities":[...]}`.
   The master resolves `hub_id` to a peer row and replies; the leaf's
   self-declared `public_key` is never trusted as proof.
2. **`hub_hello_ack`** — master → leaf:
   `{"type":"hub_hello_ack","session_id":...,"master_hub_id":...,"role":"master","capabilities":[...],"nonce":...,"signature":...}`.
   `nonce` is fresh per session (base64url, 32 random bytes). `signature` is
   base64 Ed25519 over the canonical string
   `phlix-federation/hello-ack/v1\n{session_id}\n{master_hub_id}\n{nonce}`,
   signed with the **master hub's** keypair (`Ed25519KeyManager`). The leaf
   verifies against the master's `public_key` it holds for that peer and
   cross-checks the identity bindings; verification failure closes the link and
   arms the reconnect backoff.
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
   the replacement link.

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
| `DATA` (`0x05`) ch 0 | both → both | `{"share_id":...}` | An offer was withdrawn — emitted by either side (`pushRevocation` downstream, `pushLibraryShareRevoked` upstream), accepted by both handlers. |
| `DATA` (`0x05`) ch 0 | reserved: master → leaf | `{"user_id":...,"peer_id":...,"action":"grant"|"revoke"}` | An admin delegation grant/revoke. **Receive-only forward-compat path**: the leaf consumer is implemented and verified-gated (`FederationPeerManager::handleAdminDelegation`), but the master-side producer is NOT implemented — `createAdminDelegation` writes only the DB row and audit — so this envelope is never emitted today. |
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
routinely reaches it *through* the `:8803` mount, so the two catalogs are read
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
