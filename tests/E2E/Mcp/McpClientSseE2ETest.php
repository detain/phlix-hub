<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\E2E\Mcp;

use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Hub\Tests\Support\Mcp\McpE2EProbeEnvironment;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function getenv;
use function implode;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * S329 acceptance — the OFFICIAL MCP SDK client, through a real SSE session,
 * against a RUNNING hub on `:8800`.
 *
 * ## Why this file exists
 *
 * The existing SSE tests cannot settle S62/S63's transport claim by
 * construction: they drive {@see \Phlix\Hub\Tests\Support\RecordingStreamTimers}
 * and PHPUnit never enters the Workerman event loop, so the timer and
 * keep-alive cases say nothing about a live connection. This suite is the
 * counterpart to S57's real-browser harness, and it has the same shape:
 *
 *  1. `scripts/ci-mcp-e2e-prereqs.php` supplies the prerequisites (node, the
 *     pinned `@modelcontextprotocol/sdk`, the extensions the hub needs to
 *     boot) and fails cheaply when one is missing;
 *  2. the CI job boots the hub against a MySQL service container, mints PATs
 *     through the REAL `McpTokenService` (`scripts/mcp-e2e-seed.php`), and
 *     runs THIS suite;
 *  3. `scripts/assert-mcp-e2e-ran.php` reads the JUnit report afterwards and
 *     fails when a required case did not execute — a skipped case reads as a
 *     pass, which is the exact defect S173/S305 exist to make impossible.
 *
 * Every case here drives `tests/E2E/Mcp/mcp-client-session.mjs`, a THIN
 * wrapper over the official SDK's `Client` +
 * `StreamableHTTPClientTransport`. All HTTP/SSE behaviour is the library's,
 * so the transport that is proven here is the transport a real MCP client
 * would use.
 *
 * ## The `markTestSkipped()` guards
 *
 * They exist so a developer box with no hub can still run the suite (it just
 * skips). They are NOT what keeps CI honest — the prereqs script and the
 * assert-ran script are. On a box without node or without the SDK, the
 * prereqs script reds before this suite even starts; if a case still skips,
 * the assert-ran script reds the build.
 *
 * @package Phlix\Hub\Tests\E2E\Mcp
 * @since   S329 (real MCP client SSE session, CI-gated)
 */
final class McpClientSseE2ETest extends TestCase
{
    use DecodedJsonAssertions;

    /** Env var naming the running hub's base URL (set by the CI job). */
    private const ENV_BASE_URL = 'HUB_MCP_E2E_BASE_URL';

    /** Env var naming the tokens JSON written by scripts/mcp-e2e-seed.php. */
    private const ENV_TOKENS_FILE = 'HUB_MCP_E2E_TOKENS_FILE';

    private string $baseUrl;
    private string $node;

    protected function setUp(): void
    {
        $baseUrl = getenv(self::ENV_BASE_URL);
        if ($baseUrl === false || $baseUrl === '') {
            $this->markTestSkipped(
                self::ENV_BASE_URL . ' is not set — this suite only runs in the CI job that boots the hub',
            );
        }
        $tokensFile = getenv(self::ENV_TOKENS_FILE);
        if ($tokensFile === false || $tokensFile === '' || !is_file($tokensFile)) {
            $this->markTestSkipped(
                self::ENV_TOKENS_FILE . ' is not set or unreadable — run scripts/mcp-e2e-seed.php first',
            );
        }

        $node = McpE2EProbeEnvironment::node();
        if ($node === null) {
            $this->markTestSkipped('node (>= ' . McpE2EProbeEnvironment::MIN_NODE_MAJOR . ') not found');
        }
        if (McpE2EProbeEnvironment::sdkDistFile() === null) {
            $this->markTestSkipped(
                '@modelcontextprotocol/sdk is not installed — run `npm ci` in tests/E2E/Mcp',
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($tokensFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            $this->markTestSkipped(self::ENV_TOKENS_FILE . ' did not decode to an object');
        }
        foreach (['full_token', 'readonly_token'] as $key) {
            if (!array_key_exists($key, $decoded) || !is_string($decoded[$key]) || $decoded[$key] === '') {
                $this->markTestSkipped(self::ENV_TOKENS_FILE . ' is missing the "' . $key . '" entry');
            }
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->node = $node;
    }

    // ------------------------------------------------------------------
    // The acceptance sequence, one named case per step
    // ------------------------------------------------------------------

    public function testRealMcpClientInitialisesAgainstTheRunningHub(): void
    {
        $result = $this->runProbe('initialize');

        $this->assertProbeOk($result, 'initialize');
        $parsed = $result['parsed'];
        $serverInfo = self::arrayNode($parsed['serverInfo'] ?? []);
        $this->assertSame('phlix-hub', $serverInfo['name'] ?? null, 'initialize must name the hub');
        $this->assertNotEmpty($serverInfo['version'] ?? '', 'initialize must carry a version');
        $this->assertSame(
            '2025-06-18',
            $parsed['protocolVersion'] ?? null,
            'the negotiated protocol revision must be the hub\'s latest',
        );
    }

    public function testRealMcpClientListsToolsAgainstTheRunningHub(): void
    {
        $result = $this->runProbe('list-tools');

        $this->assertProbeOk($result, 'list-tools');
        $tools = $result['parsed']['tools'] ?? null;
        $this->assertIsArray($tools, 'tools/list must return a tool name list');
        $this->assertContains('list_servers', $tools, 'the live catalogue must include list_servers');
        $this->assertContains(
            'playback_control',
            $tools,
            'the hub is booted with HUB_MCP_PLAYBACK_CONTROL=true, so playback_control must be registered',
        );
    }

    public function testRealMcpClientCallsOneToolAgainstTheRunningHub(): void
    {
        $result = $this->runProbe('call-tool');

        $this->assertProbeOk($result, 'call-tool');
        $callResult = self::arrayNode($result['parsed']['result'] ?? []);
        $this->assertFalse(
            $callResult['isError'] ?? true,
            'list_servers with no servers must be a normal (non-error) result',
        );
        $text = self::stringNode($callResult['text'] ?? '');
        $this->assertStringContainsString('servers', $text, 'the call result must carry the server list');
    }

    public function testRealMcpClientClosesCleanlyAgainstTheRunningHub(): void
    {
        $result = $this->runProbe('clean-close');

        $this->assertProbeOk($result, 'clean-close');
        $this->assertTrue(
            $result['parsed']['oncloseFired'] ?? false,
            'the transport must report a clean close after client.close()',
        );
    }

    public function testDeniedScopeIsRefusedFailClosedByTheRunningHub(): void
    {
        $result = $this->runProbe('denied-scope');

        $this->assertProbeOk($result, 'denied-scope');
        $deniedResult = self::arrayNode($result['parsed']['result'] ?? []);
        $this->assertTrue(
            $deniedResult['isError'] ?? false,
            'playback_control called with a token that lacks mcp:playback:control must come back isError',
        );
        $this->assertStringContainsString(
            'mcp.scope_denied',
            self::stringNode($deniedResult['text'] ?? ''),
            'the denied result must name mcp.scope_denied — this case reds if the scope gate is ever emptied',
        );
    }

    /**
     * The negative control: the probe MUST fail against a broken transport.
     *
     * Without this case, a probe that quietly stopped connecting would report
     * "nothing worked" as a pass. The endpoint used here is a port with
     * nothing listening on it (127.0.0.1:9, the discard port) — genuinely
     * broken, always, on every runner.
     */
    public function testTheProbeFailsAgainstABrokenTransport(): void
    {
        $result = $this->runProbe('initialize', 'http://127.0.0.1:9/mcp');

        $this->assertNotSame(0, $result['exit'], 'a probe that cannot reach the hub must NOT exit 0');
        $this->assertFalse(
            $result['parsed']['ok'] ?? true,
            'the probe must report ok:false on a broken transport',
        );
        $this->assertStringContainsString('"ok":false', $result['raw'], 'the raw probe output must say ok:false');
    }

    // ------------------------------------------------------------------
    // Probe plumbing
    // ------------------------------------------------------------------

    /**
     * Run the SDK probe in `$mode` (optionally against another base URL, used
     * only by the broken-transport case).
     *
     * @return array{exit: int, parsed: array<array-key, mixed>, raw: string}
     */
    private function runProbe(string $mode, ?string $baseUrlOverride = null): array
    {
        $url = $baseUrlOverride ?? $this->baseUrl;
        $command = implode(' ', [
            escapeshellarg($this->node),
            escapeshellarg(McpE2EProbeEnvironment::PROBE_SCRIPT),
            escapeshellarg($mode),
            escapeshellarg($url),
            escapeshellarg(getenv(self::ENV_TOKENS_FILE) ?: ''),
        ]);

        /** @var list<string> $out */
        $out = [];
        $exit = 0;
        exec($command . ' 2>&1', $out, $exit);
        $raw = implode("\n", $out);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['exit' => $exit, 'parsed' => [], 'raw' => $raw];
        }

        return ['exit' => $exit, 'parsed' => $decoded, 'raw' => $raw];
    }

    /**
     * Assert a probe invocation reported success, with the raw output in the
     * failure message so a red log is self-explanatory.
     *
     * @param array{exit: int, parsed: array<array-key, mixed>, raw: string} $result
     */
    private function assertProbeOk(array $result, string $mode): void
    {
        $this->assertSame(
            0,
            $result['exit'],
            sprintf("probe mode \"%s\" exited non-zero.\n%s", $mode, $result['raw']),
        );
        $this->assertTrue(
            $result['parsed']['ok'] ?? false,
            sprintf("probe mode \"%s\" reported ok:false.\n%s", $mode, $result['raw']),
        );
    }
}
