<?php

/**
 * A one-connection TLS server used by
 * {@see \Phlix\Hub\Tests\Unit\Alexa\CurlCertChainFetcherTimeoutTest}.
 *
 * argv: <combined-cert-and-key.pem> <mode> <stallSeconds> <portFile> [bodyFile]
 *
 * Modes:
 *  - `serve` — complete the TLS handshake, read the request line, reply 200 with
 *    the contents of `bodyFile`. This is the CONTROL: it proves the fetcher under
 *    test can actually retrieve a chain over TLS.
 *  - `stall` — complete the TLS handshake, read the request line, then send
 *    NOTHING for `stallSeconds`. This is the shape that distinguishes
 *    `CURLOPT_TIMEOUT` from `CURLOPT_CONNECTTIMEOUT`: the connection phase has
 *    already succeeded, so only a whole-transfer bound can end the call.
 *  - `raw` — write `bodyFile` to the socket verbatim as the whole HTTP
 *    response. Lets a test produce a non-200 status, or a chunked body with no
 *    `Content-Length` (which is how `CURLOPT_MAXFILESIZE` is bypassed, leaving
 *    only the fetcher's own length check standing).
 *
 * The chosen (ephemeral) port is written to `portFile` once the listener is up,
 * so the parent test never has to guess a free port or race a fixed one.
 */

declare(strict_types=1);

$certFile = (string) ($argv[1] ?? '');
$mode = (string) ($argv[2] ?? 'stall');
$stallSeconds = (int) ($argv[3] ?? 20);
$portFile = (string) ($argv[4] ?? '');
$bodyFile = (string) ($argv[5] ?? '');

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certFile,
        'verify_peer' => false,
        'allow_self_signed' => true,
    ],
]);

// `plain-raw` drops TLS entirely so a test can prove the fetcher refuses a
// plain-http URL even when a perfectly willing server is answering on it.
$transport = $mode === 'plain-raw' ? 'tcp://127.0.0.1:0' : 'tls://127.0.0.1:0';

$server = @stream_socket_server(
    $transport,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr} ({$errno})\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
if (!is_string($name) || !str_contains($name, ':')) {
    fwrite(STDERR, "could not read the bound address\n");
    exit(1);
}

$port = substr($name, (int) strrpos($name, ':') + 1);
file_put_contents($portFile, $port);

// `redirect` must survive TWO connections: curl closes the first one after the
// 302 (Connection: close) and dials again for the target. Every other mode only
// ever sees one.
$connections = $mode === 'redirect' ? 2 : 1;

for ($served = 0; $served < $connections; $served++) {
    // stream_socket_accept() on a tls:// listener performs the handshake, so a
    // connection that reaches the line after it has demonstrably finished
    // connecting — which is the entire premise of the stall mode.
    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        fwrite(STDERR, "accept timed out\n");
        exit(1);
    }

    fwrite(STDERR, "handshake-complete\n");
    @fgets($client, 8192);

    if ($mode === 'serve') {
        $body = $bodyFile !== '' ? (string) file_get_contents($bodyFile) : '';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/x-pem-file\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fflush($client);
    } elseif ($mode === 'raw' || $mode === 'plain-raw') {
        fwrite($client, $bodyFile !== '' ? (string) file_get_contents($bodyFile) : '');
        fflush($client);
    } elseif ($mode === 'redirect') {
        if ($served === 0) {
            // Same host, https, so neither CURLOPT_REDIR_PROTOCOLS_STR nor any
            // name-resolution failure can stand in for the thing under test:
            // if CURLOPT_FOLLOWLOCATION were true, this WOULD be followed.
            fwrite($client, "HTTP/1.1 302 Found\r\nLocation: https://localhost:{$port}/echo.api/final.pem\r\n"
                . "Content-Length: 0\r\nConnection: close\r\n\r\n");
        } else {
            $body = $bodyFile !== '' ? (string) file_get_contents($bodyFile) : '';
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
                . "\r\nConnection: close\r\n\r\n" . $body);
        }
        fflush($client);
    } else {
        sleep($stallSeconds);
    }

    fclose($client);
}

fclose($server);
