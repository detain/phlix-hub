<?php

/**
 * REAL-TLS loopback E2E for the hub's :8802 relay listener (INBOUND accept).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Tests\Support\Federation\FederationTlsMaterial;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The M21 posture laws (RelayWorkerTlsPostureTest) pin what the listener is
 * DECLARED as; this file is the sibling proof that the declaration is REAL on
 * a socket: a strict stream client (cafile pinned to the throwaway test CA,
 * verify_peer + verify_peer_name against the cert's 127.0.0.1 IP SAN) completes
 * a genuine TLS handshake with the genuine child (tests/Support/Relay/
 * tls_relay_child_server.php — a real Workerman worker produced by
 * RelayWorker::start() itself, transport=ssl, hardened relayTlsContext), the
 * WebSocket Upgrade rides the encrypted channel to a 101, a masked TEXT frame
 * of `{"type":"hello"}` is deframed by the production Websocket protocol into
 * RelayWorker::handleHello(), and the verdict frame that comes back over TLS
 * carries the real code's 'missing_server_id' reason.
 *
 * Dials use the LITERAL 127.0.0.1, never 'localhost' — estate venue law
 * (e5f5dd9 class): CI/Docker map localhost ::1-first and an IPv4-wildcard
 * listener never sees that SYN.
 *
 * The negative leg proves the verification is not decorative: the identical
 * dial anchored to an UNRELATED CA must die inside the handshake before any
 * application byte, and the listener must survive it whole (a second strict
 * dial still gets its verdict).
 *
 * Process-level and blocking on purpose — unlike the federation sibling this
 * parent needs no workerman event loop (raw client streams only), so no
 * Worker/Timer statics are touched and nothing cross-talks with the suite.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class RelayWorkerTlsHandshakeE2ETest extends TestCase
{
    private const WS_GUIDE = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private string $dir = '';

    /** @var array{ca: string, server: string, other_ca: string} */
    private array $certs;

    /** @var resource|null */
    private $childProcess = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/phlix-hub-relay-tls-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);

        $this->certs = FederationTlsMaterial::generate($this->dir);
    }

    protected function tearDown(): void
    {
        $this->stopChild();
        self::removeDir($this->dir);

        parent::tearDown();
    }

    public function testStrictWssClientCompletesTlsUpgradeAndReceivesTheRealHelloVerdict(): void
    {
        $port = FederationTlsMaterial::reservePort();
        $this->spawnChild($port);

        $socket = $this->strictDial($port, $this->certs['ca']);

        // Proof 1 — the WebSocket Upgrade completes over the encrypted channel.
        $key = base64_encode(random_bytes(16));        $this->writeOrThrow(
            $socket,
            "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n",
        );

        $headers = $this->readHttpHeaders($socket);
        self::assertStringContainsString('101', $headers, 'the hub must answer the upgrade: ' . $this->diagnostics());
        $expectedAccept = base64_encode(sha1($key . self::WS_GUIDE, true));
        self::assertMatchesRegularExpression(
            '/sec-websocket-accept:\s*' . preg_quote($expectedAccept, '/') . '/i',
            $headers,
            'RFC 6455 accept hash of the client key — the real handshake, not an echo',
        );

        // Proof 2 — a deframed TEXT payload reaches production handleHello and
        // its verdict rides back DOWN the TLS channel. Workerman delivers
        // TcpConnection::close($reason) as a final unmasked frame carrying the
        // reason (opcode TEXT in the current vendor, CLOSE is the RFC shape —
        // both are accepted here; either way the bytes exist only if real
        // hub code answered over a real encrypted socket).
        $this->writeOrThrow($socket, $this->maskedTextFrame('{"type":"hello"}'));
        $verdict = $this->readVerdictFrame($socket);
        self::assertStringContainsString(
            'missing_server_id',
            $verdict,
            'handleHello must answer the server_id-less HELLO over the encrypted socket: ' . $this->diagnostics(),
        );

        // Proof 0 — this really was TLS through and through: the handshake
        // completed only because OpenSSL verified the presented chain against
        // the pinned test CA and the 127.0.0.1 name against the leaf's IP SAN
        // (the sibling test's other-CA refusal is the behavioral proof of
        // that verification biting — PHP cannot introspect the peer cert on
        // TLS1.3, so the pair legs, not metadata, carry the identity law).
        $crypto = stream_get_meta_data($socket)['crypto'] ?? [];
        self::assertNotEmpty($crypto, 'a crypto section in the stream metadata is the TLS proof');
        self::assertMatchesRegularExpression(
            '/TLSv1\.[23]/',
            $crypto['protocol'],
            'the upgrade+verdict must have ridden TLS1.2/1.3, never plaintext: ' . $this->diagnostics(),
        );

        fclose($socket);
    }

    public function testUntrustedCaDialDiesAtTheHandshakeAndTheListenerSurvivesWhole(): void
    {
        $port = FederationTlsMaterial::reservePort();
        $this->spawnChild($port);

        // The verification-is-real pin: same listener, same strict knobs, but
        // anchored to an UNRELATED CA — it must die at the handshake.
        $attempted = @stream_socket_client(
            "ssl://127.0.0.1:$port",
            $errno,
            $errstr,
            10.0,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'cafile' => $this->certs['other_ca'],
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => '127.0.0.1',
                    'crypto_method' =>
                        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                ],
            ]),
        );

        self::assertFalse(
            $attempted,
            'a client trusting the WRONG CA must never complete the handshake: ' . $this->diagnostics(),
        );
        // PHP reports handshake rejections through the suppressed warning, not
        // $errstr — gather both surfaces for the "died in the TLS layer" pin.
        $warning = (string) (error_get_last()['message'] ?? '');
        $evidence = $errstr !== null && $errstr !== '' ? $errstr : $warning;
        self::assertMatchesRegularExpression(
            '/ssl|certificate|handshake|alert|encrypt/i',
            $evidence,
            'the refusal must be the TLS layer, not a socket-level fluke'
            . " (errno $errno, errstr " . var_export($errstr, true) . ", warning '$warning'): "
            . $this->diagnostics(),
        );

        // The listener survived the bad handshake: a strict dial still works.
        $socket = $this->strictDial($port, $this->certs['ca']);
        $key = base64_encode(random_bytes(16));
        $this->writeOrThrow(
            $socket,
            "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n",
        );
        self::assertStringContainsString(
            '101',
            $this->readHttpHeaders($socket),
            'after the rejected handshake the relay listener must serve the next strict client: '
            . $this->diagnostics(),
        );
        fclose($socket);
    }

    // ------------------------------------------------------------------ harness

    /**
     * Open a strict-verify TLS stream to the child's literal 127.0.0.1 port.
     *
     * @return resource
     */
    private function strictDial(int $port, string $caFile)
    {
        $socket = @stream_socket_client(
            "ssl://127.0.0.1:$port",
            $errno,
            $errstr,
            10.0,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'cafile' => $caFile,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => '127.0.0.1',
                    'crypto_method' =>
                        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                ],
            ]),
        );

        if ($socket === false) {
            self::fail("strict TLS dial to 127.0.0.1:$port failed ($errno: $errstr): " . $this->diagnostics());
        }

        stream_set_timeout($socket, 10);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function writeOrThrow($socket, string $bytes): void
    {
        $written = 0;
        while ($written < strlen($bytes)) {
            $chunk = fwrite($socket, substr($bytes, $written));
            if ($chunk === false || $chunk === 0) {
                self::fail('write stalled on the TLS socket: ' . $this->diagnostics());
            }
            $written += $chunk;
        }
    }

    /**
     * Read until the HTTP header block terminates (upgrade response).
     *
     * @param resource $socket
     */
    private function readHttpHeaders($socket): string
    {
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '' || $this->timedOut($socket)) {
                self::fail('no complete upgrade response: ' . $this->diagnostics());
            }
            $response .= $chunk;
        }

        return $response;
    }

    /**
     * Read server→client frames until the HELLO verdict arrives; return its payload.
     *
     * Accepts both carrier shapes the vendor has used over time: a TEXT frame
     * holding the close reason (current workerman `close($data)` semantics) or
     * an RFC 6455 CLOSE frame. Anything else (e.g. a stray pong) is skipped;
     * EOF before a verdict is the failure.
     *
     * @param resource $socket
     */
    private function readVerdictFrame($socket): string
    {
        while (true) {
            $head = $this->readExactly($socket, 2);
            $opcode = ord($head[0]) & 0x0F;
            $len = ord($head[1]) & 0x7F;
            if ($len === 126) {
                $ext = unpack('n', $this->readExactly($socket, 2));
                $len = is_array($ext) ? (int) $ext[1] : 0;
            } elseif ($len === 127) {
                $ext = unpack('J', $this->readExactly($socket, 8));
                $len = is_array($ext) ? (int) $ext[1] : 0;
            }
            $payload = $len > 0 ? $this->readExactly($socket, $len) : '';

            if ($opcode === 0x8) {
                // May or may not carry the 2-byte status code ahead of the
                // reason depending on vendor path; the callers' substring
                // assertions tolerate both shapes.
                return $payload;
            }

            if ($opcode === 0x1) {
                return $payload;
            }
        }
    }

    /**
     * @param resource $socket
     */
    private function readExactly($socket, int $n): string
    {
        if ($n <= 0) {
            return '';
        }

        $buffer = '';
        while (strlen($buffer) < $n) {
            $chunk = fread($socket, max(1, $n - strlen($buffer)));
            if ($chunk === false || $chunk === '' || $this->timedOut($socket)) {
                self::fail('frame read truncated at the TLS socket: ' . $this->diagnostics());
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param resource $socket
     */
    private function timedOut($socket): bool
    {
        return (bool) stream_get_meta_data($socket)['timed_out'];
    }

    /**
     * RFC 6455 client frame: FIN+TEXT, MASK bit set, 4-byte xor mask.
     */
    private function maskedTextFrame(string $payload): string
    {
        $mask = random_bytes(4);
        $masked = '';
        foreach (str_split($payload) as $i => $byte) {
            $masked .= $byte ^ $mask[$i % 4];
        }

        $len = strlen($payload);
        if ($len > 125) {
            throw new RuntimeException('E2E frames stay under 126 bytes: ' . $this->diagnostics());
        }

        return "\x81" . chr(0x80 | $len) . $mask . $masked;
    }

    private function spawnChild(int $port): void
    {
        $script = dirname(__DIR__, 2) . '/Support/Relay/tls_relay_child_server.php';
        if (!is_file($script)) {
            throw new RuntimeException("TLS relay child script missing at $script");
        }

        $logFile = $this->dir . '/child.log';
        $proc = proc_open(
            [
                'setsid',
                PHP_BINARY,
                $script,
                (string) $port,
                $this->dir,
                $this->certs['server'],
                'start',
            ],
            [
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('could not spawn the TLS relay child process');
        }

        $this->childProcess = $proc;

        $deadline = microtime(true) + 15.0;
        while (!file_exists($this->dir . '/ready')) {
            if (microtime(true) >= $deadline) {
                self::fail('TLS relay child never became ready: ' . $this->diagnostics());
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                self::fail('TLS relay child died during startup: ' . $this->diagnostics());
            }

            usleep(50_000);
        }
    }

    private function stopChild(): void
    {
        if (!is_resource($this->childProcess)) {
            return;
        }

        $status = proc_get_status($this->childProcess);
        if ($status['running']) {
            // setsid: the child pid doubles as the process-group id — one
            // signal covers the workerman master AND its forked TLS worker.
            posix_kill(-(int) $status['pid'], SIGTERM);

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                clearstatcache();
                $recheck = proc_get_status($this->childProcess);
                if (!$recheck['running']) {
                    break;
                }

                usleep(50_000);
            }

            $recheck = proc_get_status($this->childProcess);
            if ($recheck['running']) {
                posix_kill(-(int) $recheck['pid'], SIGKILL);
            }
        }

        proc_close($this->childProcess);
        $this->childProcess = null;
    }

    private function diagnostics(): string
    {
        $markers = array_map('basename', glob($this->dir . '/*') ?: []);
        $read = static fn (string $p): string => is_file($p)
            ? substr((string) file_get_contents($p), -1500)
            : '(absent)';

        return sprintf(
            "\nmarkers: %s\n--- child.log ---\n%s\n--- workerman.log ---\n%s",
            json_encode($markers),
            $read($this->dir . '/child.log'),
            $read($this->dir . '/workerman.log'),
        );
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                self::removeDir($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($dir);
    }
}
