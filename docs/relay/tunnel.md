# Tunnel / TunnelManager / ClientConnection

**Applies to:** Hub (phlix-hub)

High-level relay management for bidirectional WebSocket tunnels between the hub and servers, and the client connections multiplexed through them.

## Overview

```
Server (WSS) ←→ [Tunnel] ←→ [TunnelManager] ←→ [ClientConnection] ←→ Client (WSS)
                    ↑
              RelaySessionManager
                    ↑
               Database
```

- **Tunnel** — represents a single server-to-hub WebSocket connection with state machine, framing, and multiplexed clients
- **TunnelManager** — registry of all active tunnels, accepts server/client connections, runs heartbeat/reaper timers
- **ClientConnection** — represents a single client-to-hub WebSocket connection attached to a tunnel

## Tunnel State Machine

```
PENDING → ACTIVE → CLOSING → CLOSED
```

| State | Description |
|---|---|
| `PENDING` | Awaiting HELLO handshake from server |
| `ACTIVE` | Fully established, frames can be exchanged |
| `CLOSING` | Clean shutdown in progress |
| `CLOSED` | All resources released |

### Transitions

| From | To | Trigger |
|---|---|---|
| `PENDING` | `ACTIVE` | Valid HELLO frame received |
| `PENDING` | `CLOSED` | Malformed HELLO, timeout, or protocol error |
| `ACTIVE` | `CLOSING` | Server disconnect, explicit `close()`, or protocol error |
| `ACTIVE` | `CLOSING` | `isStale()` returns true (reaper) |
| `CLOSING` | `CLOSED` | Cleanup completes |

## Tunnel

`Phlix\Hub\Relay\Tunnel`

Represents a bidirectional WebSocket tunnel between the hub and a server. Manages the server-side connection, all client connections multiplexed through this tunnel, frame sequencing, and session lifecycle.

### Construction

```php
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayWireCodecInterface;

$tunnel = new Tunnel(
    serverId: 'server-uuid',
    serverWs: $tcpConnection,          // Workerman TcpConnection to server
    sessionManager: $sessionManager,     // For byte accounting
    codec: $codec,                      // FrameEncoder/Decoder codec
    logger: $logger,                    // StructuredLogger
    tunnelId: null,                      // Optional UUID (generated if null)
);
```

### Properties

| Property | Type | Description |
|---|---|---|
| `tunnelId` | `string` | Unique tunnel UUID. |
| `serverId` | `string` | Server UUID. |
| `serverWs` | `TcpConnection` | Workerman connection to the server. |
| `status` | `string` | Current state (`PENDING`, `ACTIVE`, `CLOSING`, `CLOSED`). |
| `clientConnections` | `SplObjectStorage` | All client connections attached to this tunnel. |
| `openedAt` | `int` | Unix timestamp when tunnel was opened. |
| `lastFrameAt` | `int` | Unix timestamp of last frame received from server. |
| `seq` | `int` | Next sequence number for frames sent to server. |
| `relaySessionId` | `string\|null` | Relay session ID (set after HELLO handshake completes). |

### Lifecycle Methods

#### `onServerMessage(string $data): void`

Handle an incoming message from the server. During `PENDING` state, expects a JSON HELLO frame. During `ACTIVE` state, decodes binary frames via `FrameDecoder` and handles:
- `DATA` → broadcast to all clients
- `HEARTBEAT` → touch `lastFrameAt`
- Other types → log warning and close

#### `onServerClose(): void`

Handle server WebSocket close event. Notifies all clients with `TYPE_DISCONNECTED`, closes the session in the database, transitions to `CLOSED`.

#### `close(string $reason = 'normal'): void`

Initiate clean tunnel shutdown. Sends `TYPE_DISCONNECTED` to all clients, closes the server connection, closes the session in DB.

### Client Multiplexing

#### `registerClient(ClientConnection $client): void`

Register a new client connection with this tunnel. Sends `CLIENT_CONNECT` notification to the server.

```php
$tunnel->registerClient($clientConnection);
```

#### `removeClient(ClientConnection $client): void`

Remove a client connection from this tunnel. Sends `CLIENT_DISCONNECT` notification to the server if the tunnel is still active.

```php
$tunnel->removeClient($clientConnection);
```

### Frame Exchange

#### `sendToServer(RelayFrame $frame): void`

Send a frame to the server. Only valid when tunnel is `ACTIVE`. Records bytes sent to the session manager.

```php
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;

$frame = new RelayFrame(RelayFrameType::DATA, $tunnel->seq++, 'payload bytes');
$tunnel->sendToServer($frame);
```

#### `broadcastToClients(RelayFrame $frame): void`

Broadcast a DATA frame to all connected clients. The frame is encoded once and written to each client connection.

```php
$tunnel->broadcastToClients($frame);
```

#### `sendHeartbeat(): void`

Send a heartbeat frame to the server. Increments sequence number and updates `lastFrameAt`.

```php
$tunnel->sendHeartbeat();
```

### Health Checks

#### `isStale(int $staleThresholdSeconds = 90): bool`

Check if the tunnel is stale (no frames received within the threshold). Used by the reaper to detect dead tunnels.

```php
if ($tunnel->isStale(90)) {
    // Close stale tunnel
    $tunnel->close('stale');
}
```

## TunnelManager

`Phlix\Hub\Relay\TunnelManager`

Manages all active relay tunnels between the hub and servers. Provides registration of new server tunnels, lookup by server ID, client connection routing, and tunnel lifecycle management.

### Construction

```php
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Relay\RelayWireCodecInterface;

$manager = new TunnelManager(
    sessionManager: $sessionManager,
    codec: $codec,
    logger: $logger,
);
```

### Server Connections

#### `acceptServer(string $serverId, TcpConnection $serverWs): Tunnel`

Accept a new server connection and create a tunnel. If a tunnel already exists for this `server_id`, it is closed first (server reconnect scenario) before a new one is created.

Returns a `Tunnel` in `PENDING` state (transitions to `ACTIVE` after HELLO is received).

```php
$serverId = 'server-uuid';
$tunnel = $manager->acceptServer($serverId, $serverWs);

// $tunnel is now in PENDING state, waiting for HELLO
```

#### `getTunnelForServer(string $serverId): ?Tunnel`

Get the tunnel for a given server ID.

```php
$tunnel = $manager->getTunnelForServer('server-uuid');
if ($tunnel !== null && $tunnel->status === Tunnel::STATUS_ACTIVE) {
    // Use the active tunnel
}
```

#### `hasTunnel(string $serverId): bool`

Check if an active tunnel exists for the given server ID.

```php
if ($manager->hasTunnel('server-uuid')) {
    // Server is connected and active
}
```

### Client Connections

#### `acceptClient(string $serverId, TcpConnection $clientWs, string $clientId, string $sessionId = ''): ?ClientConnection`

Accept a new client connection and attach it to the appropriate tunnel. Returns `null` if the tunnel is not found or not active.

```php
$client = $manager->acceptClient(
    serverId: 'server-uuid',
    clientWs: $clientTcpConnection,
    clientId: 'client-uuid',
    sessionId: 'relay-session-id',
);

if ($client === null) {
    // Server not connected or tunnel not active
}
```

### Tunnel Lifecycle

#### `closeTunnel(string $serverId, string $reason): void`

Close a tunnel by server ID. Marks the tunnel as closed, sends `TYPE_DISCONNECTED` to all clients, closes the server connection, and removes the tunnel from the map.

```php
$manager->closeTunnel('server-uuid', 'server_disconnected');
```

#### `removeTunnel(string $serverId): void`

Remove a tunnel from the manager (called after cleanup).

```php
$manager->removeTunnel('server-uuid');
```

### Iteration

#### `allTunnels(): Generator<string, Tunnel>`

Get all active tunnels as a generator. Yields `[serverId => Tunnel]` for all tunnels in `ACTIVE` status. Used by heartbeat timer and idle reaper.

```php
foreach ($manager->allTunnels() as $serverId => $tunnel) {
    // Check tunnel health
    if ($tunnel->isStale()) {
        $tunnel->close('stale');
    }
}
```

#### `getActiveTunnelCount(): int`

Get the count of active tunnels.

```php
$count = $manager->getActiveTunnelCount();
```

## ClientConnection

`Phlix\Hub\Relay\ClientConnection`

Represents a single client WebSocket connection multiplexed through a tunnel. Each remote client connects to the hub via WSS and is tracked as a `ClientConnection` attached to a specific server `Tunnel`.

### Construction

```php
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Common\Logger\StructuredLogger;

$client = new ClientConnection(
    clientWs: $clientTcpConnection,
    serverId: 'server-uuid',
    clientId: 'client-uuid',
    logger: $logger,
    sessionId: 'relay-session-id',  // Optional
);
```

### Properties

| Property | Type | Description |
|---|---|---|
| `clientId` | `string` | Client UUID (assigned by the hub). |
| `serverId` | `string` | Server UUID this client is connected through. |
| `sessionId` | `string` | Optional relay session ID for this client. |
| `clientWs` | `TcpConnection` | Workerman connection to the client. |
| `tunnel` | `Tunnel\|null` | Tunnel this client is attached to. |
| `lastFrameAt` | `int` | Unix timestamp of last frame received from client. |

### Message Handling

#### `onMessage(string $data, FrameDecoder $decoder): void`

Handle an incoming message from the client. Only `TYPE_DATA` frames are forwarded to the server. Other frame types log a warning and send `TYPE_ERROR` back to the client.

```php
$decoder = new FrameDecoder();
$client->onMessage($bytesFromClient, $decoder);
```

### Lifecycle

#### `onClose(): void`

Handle client WebSocket close event. Notifies the tunnel to send `CLIENT_DISCONNECT` upstream.

```php
$client->onClose();
```

#### `close(): void`

Close the client connection.

```php
$client->close();
```

### Sending

#### `sendRaw(string $encodedFrame): void`

Send an already-encoded binary frame to the client.

```php
$client->sendRaw($encodedBytes);
```

#### `send(RelayFrame $frame, FrameEncoder $encoder): void`

Encode and send a frame to the client.

```php
$encoder = new FrameEncoder();
$client->send($frame, $encoder);
```

## Error Handling

### Tunnel Errors

| Reason | Description |
|---|---|
| `malformed_hello` | JSON parse error in HELLO frame |
| `invalid_hello` | Missing or invalid `type` field in HELLO |
| `invalid_hello_payload` | Missing `enrollment_jwt` or `server_id` in HELLO |
| `protocol_error` | Unexpected frame type received |
| `server_closed` | Server disconnected |
| `server_replaced` | Server reconnected (old tunnel closed) |
| `stale` | Tunnel idle beyond threshold |

When a tunnel closes with an error, all client connections receive `TYPE_DISCONNECTED` with the reason.

### Client Connection Errors

When a client sends a non-DATA frame, a `TYPE_ERROR` frame is sent back:

```php
// Error payload sent to client
['error' => 'Unexpected frame type']
```

The connection is not automatically closed — the client may recover.

### FrameDecoder Errors

Per RFC 6455 §7.4.1, invalid frame types throw `InvalidFrameTypeException` with code **1011**. The decoder is in an undefined state after this exception — discard it and create a new instance.

## Example Usage

### Server Connection Handling

```php
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\FrameDecoder;

$manager = new TunnelManager($sessionManager, $codec, $logger);
$decoder = new FrameDecoder();

$worker->onConnect = function ($connection) use ($manager) {
    // Server connecting — create tunnel in PENDING state
    $serverId = extractServerId($connection);
    $tunnel = $manager->acceptServer($serverId, $connection);
};

$worker->onMessage = function ($connection, $data) use ($manager, $decoder) {
    $serverId = extractServerId($connection);
    $tunnel = $manager->getTunnelForServer($serverId);

    if ($tunnel === null) {
        return;
    }

    $tunnel->onServerMessage($data);
};

$worker->onClose = function ($connection) use ($manager) {
    $serverId = extractServerId($connection);
    $manager->closeTunnel($serverId, 'server_closed');
};
```

### Client Connection Handling

```php
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;

$decoder = new FrameDecoder();

$worker->onConnect = function ($connection) use ($manager) {
    $clientId = generateClientId();
    $serverId = extractRequestedServerId($connection);

    $client = $manager->acceptClient($serverId, $connection, $clientId);

    if ($client === null) {
        // Reject connection
        $connection->close();
    }
};

$worker->onMessage = function ($connection, $data) use ($manager, $decoder) {
    $clientId = extractClientId($connection);
    $client = findClientByConnection($clientId);

    if ($client === null) {
        return;
    }

    $client->onMessage($data, $decoder);
};

$worker->onClose = function ($connection) use ($manager) {
    $clientId = extractClientId($connection);
    $client = findClientByConnection($clientId);

    if ($client !== null) {
        $client->onClose();
    }
};
```

### Heartbeat and Reaper

```php
use React\EventLoop\Loop;

$heartbeatInterval = 30; // seconds
$staleThreshold = 90;   // seconds

// Heartbeat timer
Loop::addPeriodicTimer($heartbeatInterval, function () use ($manager) {
    foreach ($manager->allTunnels() as $serverId => $tunnel) {
        $tunnel->sendHeartbeat();
    }
});

// Stale reaper
Loop::addPeriodicTimer($staleThreshold, function () use ($manager) {
    foreach ($manager->allTunnels() as $serverId => $tunnel) {
        if ($tunnel->isStale($staleThreshold)) {
            $tunnel->close('stale');
        }
    }
});
```
