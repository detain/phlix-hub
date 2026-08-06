# Phlix WebSocket surfaces

Companion to [`openapi.yaml`](../openapi.yaml), which describes the hub's HTTP
surface on `:8800`. This document covers everything that is **not** HTTP: five
WebSocket surfaces across two repositories.

| Port | Surface | Repository | Worker | Encoding |
| --- | --- | --- | --- | --- |
| `8802` | Server relay tunnel | `phlix-hub` | `Phlix\Hub\Relay\RelayWorker` | JSON handshake, then binary `RelayFrame` |
| `8803` | Client mount | `phlix-hub` | `Phlix\Hub\Relay\ClientRelayWorker` | binary `RelayFrame` |
| `8804` | SyncPlay relay | `phlix-hub` | `Phlix\Hub\SyncPlay\SyncPlayRelayWorker` | JSON text |
| `8805` | Hub federation | `phlix-hub` | `Phlix\Hub\Relay\FederationWorker` | JSON handshake, then binary `RelayFrame` |
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
| `0x09` | `HUB_HELLO` | leaf → master | 0 | JSON text, federation handshake |
| `0x0A` | `HUB_HELLO_ACK` | master → leaf | 0 | JSON text |
| `0x0B` | `HUB_HEARTBEAT` | both → both | 0 | keep-alive |
| `0x0C` | `LIBRARY_SHARE_UPDATE` | master → leaf | 0 | JSON |
| `0x0D` | `LIBRARY_SHARE_REVOKED` | master → leaf | 0 | JSON |
| `0x0E` | `ADMIN_DELEGATION` | master → leaf | 0 | JSON |
| `0x0F` | `HUB_DISCONNECTED` | both → both | 0 | clean close |
| `0x10` | `HTTP_REQUEST` | hub → server | request id | `RelayHttpRequest` JSON |
| `0x11` | `HTTP_RESPONSE` | server → hub | request id | tagged `HEAD` / `BODY` / `END` chunk (`RelayHttpResponseCodec`) |
| `0x12` | `HTTP_CANCEL` | hub → server | request id | empty — the client abandoned the request |

`HTTP_REQUEST` / `HTTP_RESPONSE` are what back the `/api/v1/servers/{id}/proxy/{path}`
operations in `openapi.yaml`: the hub turns an authenticated HTTP call into a
frame, the server answers with a stream of tagged chunks, and the hub streams
those back to the caller through `ConnectionResponseSink` (which also applies the
per-user `TokenBucket` throttle).

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
ws://hub.phlix.tv:8804/syncplay/{server_id}?token=<relay token>
```

Parsed by `SyncPlayRelayWorker::parseServerId()` with
`~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~`.

JSON text frames throughout — this surface does **not** use the binary frame
format. Room state is held in the worker and messages are broadcast to every
client in the room. The room key is scoped to the authenticated
`(server_id, owner)` pair, so two different servers that pick the same friendly
room name resolve to different internal rooms and can never control each other's
playback.

> ⚠ This surface still reads its relay token from the **query string**, the form
> `:8803` deliberately dropped in S2b. Same token type, same
> `ClientRelayTokenService::validate()`, different transport for the credential.

### Message catalog

Client → hub:

| `type` | Meaning |
| --- | --- |
| `group_join` | Join a room. Answered with `room_state`; other members get `client_joined`. |
| `group_leave` | Leave the current room. Other members get `client_left`. |
| `playback_play` | Broadcast "play" to the room. |
| `playback_pause` | Broadcast "pause" to the room. |
| `playback_seek` | Broadcast a seek position to the room. |
| `time_sync` | Clock probe. Answered with `time_sync_reply`. |

Hub → client:

| `type` | Meaning |
| --- | --- |
| `room_state` | The room's members and current playback state, sent on join. |
| `client_joined` | Another client joined the room. |
| `client_left` | Another client left the room (explicitly or by disconnect). |
| `time_sync_reply` | Answer to a `time_sync` probe. |

**The catalog above is a floor, not a closed set.** `SyncPlayRelayWorker::onMessage()`
relays any *unrecognised* `type` verbatim to the rest of the room, so clients can
agree on extra message types without the hub knowing about them.

Empty rooms are swept by a 60-second timer in `onWorkerStart()`.

---

## `:8805` — hub federation

```
ws://master-hub.example:8805/relay/federation/{hub_id}
```

Parsed by `FederationWorker::parseHubId()` with
`~^/relay/federation/([^/?#]+)/?(?:[?#].*)?$~`. Leaf hubs dial the master hub;
this worker is the master-side listener.

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

### Message catalog

Handshake and control use the shared binary frame format, on the hub-specific
frame types: `HUB_HELLO` (`0x09`), `HUB_HELLO_ACK` (`0x0A`), `HUB_HEARTBEAT`
(`0x0B`), `HUB_DISCONNECTED` (`0x0F`).

Payload frames, all master → leaf, all JSON on channel 0:

| Code | Name | Meaning |
| --- | --- | --- |
| `0x0C` | `LIBRARY_SHARE_UPDATE` | A federated library offer was created or changed. Surfaces at `GET /api/v1/me/federation/library-shares/incoming`. |
| `0x0D` | `LIBRARY_SHARE_REVOKED` | An offer was withdrawn. |
| `0x0E` | `ADMIN_DELEGATION` | An admin delegation was granted or revoked. |

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
| SyncPlay | `syncplay_create_group`, `syncplay_join_group`, `syncplay_leave_group`, `syncplay_sync_state`, `syncplay_sync_request` |
| Dashboard | `subscribe_dashboard`, `dashboard_now_playing` |
| Misc | `library_updated`, `notification`, `error`, `ping`, `pong` |

Public (pre-auth) events: `ping`, `pong`, `auth_request`, `connected`.
Privileged events: the whole Session, Playback and Dashboard groups, plus every
`syncplay_*` type.

> The two SyncPlay vocabularies are **different**. The hub's `:8804` relay speaks
> `group_join` / `playback_play` / `time_sync`; the media server's `:8097` socket
> speaks `syncplay_join_group` / `syncplay_sync_state`. They are separate
> transports for the same feature, not two names for one protocol.
