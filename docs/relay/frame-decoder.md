# FrameDecoder / FrameEncoder

**Applies to:** Hub (phlix-hub)

Low-level binary framing for all Hub relay messages. Implements a streaming decoder and static helper encoder using a simple, fixed-format wire protocol.

## Wire Format

```
[4-byte sequence][1-byte type][2-byte length][payload]
```

| Field | Size | Description |
|---|---|---|
| `sequence` | 4 bytes | Big-endian unsigned integer. Monotonically increasing per connection. |
| `type` | 1 byte | Frame type identifier (see below). |
| `length` | 2 bytes | Big-endian unsigned short. Payload byte length (0–65535). |
| `payload` | `length` bytes | Frame-specific data. |

Total overhead per frame: **7 bytes** + payload.

## Frame Types

| Byte | Name | Direction | Description |
|---|---|---|---|
| `0x01` | `HELLO` | Server → Hub | Initial handshake. Payload: `{version: string, server_id: string}`. |
| `0x02` | `HELLO_ACK` | Hub → Server | Acceptance. Payload: `{relay_token: string, heartbeat_interval: int}`. |
| `0x03` | `CLIENT_CONNECT` | Server ↔ Hub | A client connection is being routed through the relay. Payload: `{client_id: string, server_id: string}`. |
| `0x04` | `CLIENT_DISCONNECT` | Server ↔ Hub | Client disconnected. Payload: `{client_id: string, reason?: string}`. |
| `0x05` | `DATA` | Server ↔ Hub | Raw tunneled payload forwarded between client and server. Payload: raw bytes (no framing). |
| `0x06` | `HEARTBEAT` | Server ↔ Hub | Keep-alive ping. No payload (length = 0). |
| `0x07` | `DISCONNECTED` | Server → Hub | Server is shutting down. Payload: `{reason?: string}`. |
| `0x08` | `ERROR` | Hub → Server | Protocol error. Payload: `{code: int, message: string}`. |

## FrameDecoder

`Phlix\Hub\Relay\FrameDecoder`

Streams bytes through a ring buffer and emits complete frames incrementally. Call `append()` as data arrives, then repeatedly call `decode()` until it returns `null`.

```php
use Phlix\Hub\Relay\FrameDecoder;

$decoder = new FrameDecoder();

$decoder->append($someBytes);  // e.g. from a TCP socket

while (($frame = $decoder->decode()) !== null) {
    // $frame is a Frame object: {sequence: int, type: int, payload: string}
    process($frame);
}
```

### Reset on new connection

```php
$decoder->reset();
```

Call `reset()` when the underlying transport is closed or a new connection begins.

### Invalid type handling

Per RFC 6455 §7.4.1, invalid frame types (any byte not defined in the table above) throw `InvalidFrameTypeException` with code **1011**. The decoder is in an undefined state after this exception — discard it and create a new instance.

## FrameEncoder

`Phlix\Hub\Relay\FrameEncoder`

Static factory methods produce correctly-formatted binary frames.

```php
use Phlix\Hub\Relay\FrameEncoder;

$seq = 1;

// Client CONNECT frame
$bytes = FrameEncoder::clientConnect($seq, 'client-abc', 'server-xyz');
// → 7 bytes header + payload

// DATA frame
$bytes = FrameEncoder::data($seq, "\x00\x01\x02\x03");

// HEARTBEAT (no payload)
$bytes = FrameEncoder::heartbeat($seq);
```

### Available factories

| Method | Type byte | Payload |
|---|---|---|
| `FrameEncoder::hello(int $seq, string $version, string $serverId)` | `0x01` | JSON `{version, server_id}` |
| `FrameEncoder::helloAck(int $seq, string $relayToken, int $heartbeatInterval)` | `0x02` | JSON `{relay_token, heartbeat_interval}` |
| `FrameEncoder::clientConnect(int $seq, string $clientId, string $serverId)` | `0x03` | JSON `{client_id, server_id}` |
| `FrameEncoder::clientDisconnect(int $seq, string $clientId, ?string $reason)` | `0x04` | JSON `{client_id, reason}` |
| `FrameEncoder::data(int $seq, string $payload)` | `0x05` | Raw bytes |
| `FrameEncoder::heartbeat(int $seq)` | `0x06` | Empty |
| `FrameEncoder::disconnected(int $seq, ?string $reason)` | `0x07` | JSON `{reason}` |
| `FrameEncoder::error(int $seq, int $code, string $message)` | `0x08` | JSON `{code, message}` |

All methods accept `$seq` as the first argument. Sequence numbers are caller-managed — pass a monotonically increasing integer per connection.

## Complete example

```php
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;

$seq = 0;
$decoder = new FrameDecoder();

// Receive bytes from socket...
$decoder->append($bytes);

// Decode frames
while (($frame = $decoder->decode()) !== null) {
    match ($frame->type) {
        FrameEncoder::TYPE_HELLO => handleHello($frame),
        FrameEncoder::TYPE_DATA => handleData($frame),
        FrameEncoder::TYPE_HEARTBEAT => send(FrameEncoder::heartbeat($seq++)),
        default => send(FrameEncoder::error($seq++, 1011, 'Unknown frame type')),
    };
}

// Send a DATA frame
$out = FrameEncoder::data($seq++, "\x00\x01\x02\x03");
```
