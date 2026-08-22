#!/usr/bin/env node
/**
 * S329 — drive the official MCP TypeScript SDK client against a RUNNING hub.
 *
 * This is the client half of the live-session proof. It is deliberately a
 * THIN wrapper over `@modelcontextprotocol/sdk` — the transport work (SSE
 * GET open, POST JSON-RPC, protocol-version header, session handling) is the
 * library's, not this file's. If this file ever grows its own HTTP/SSE code,
 * it has stopped being a "real MCP client" and the acceptance criterion it
 * exists to prove is void.
 *
 * Modes:
 *
 *   initialize     connect → initialize; print the negotiated serverInfo.
 *   list-tools     + tools/list; print the tool names.
 *   call-tool      + tools/call list_servers; print the CallToolResult.
 *   denied-scope   + tools/call playback_control with a token that must NOT
 *                  hold mcp:playback:control; the call must come back as an
 *                  isError result carrying mcp.scope_denied (fail closed).
 *   clean-close    initialize, then close(); prove the transport's onclose
 *                  fired without an error.
 *
 * Every mode ends the session cleanly (client.close()) and prints ONE JSON
 * line on stdout:
 *
 *   {"ok":true,"mode":"…",…}
 *   {"ok":false,"mode":"…","error":"…","detail":…}
 *
 * Exit code 0 / 1. A hard wall-clock ceiling (HARD_TIMEOUT_MS) turns a hung
 * hub into a non-zero exit instead of a PHPUnit suite that never returns.
 *
 * Usage:
 *   node mcp-client-session.mjs <mode> <base-url> <tokens-file>
 *
 * @package Phlix\Hub\Tests\E2E\Mcp
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const HARD_TIMEOUT_MS = 20000;
const TOOL_TIMEOUT_MS = 10000;

const [mode, baseUrl, tokensFile] = process.argv.slice(2);

/** Every failure path prints one JSON line and exits 1. */
function fail(error, detail = undefined) {
  process.stdout.write(JSON.stringify({ ok: false, mode, error: String(error), detail }));
  process.stdout.write('\n');
  process.exit(1);
}

const guard = setTimeout(() => {
  fail('hard timeout exceeded');
}, HARD_TIMEOUT_MS);
guard.unref?.();

if (!mode || !baseUrl || !tokensFile) {
  fail('usage: node mcp-client-session.mjs <mode> <base-url> <tokens-file>');
}

/** @type {Record<string, string>} */
let tokens;
try {
  tokens = JSON.parse(await import('node:fs').then((fs) => fs.readFileSync(tokensFile, 'utf8')));
} catch (error) {
  fail(`tokens file unreadable: ${error instanceof Error ? error.message : String(error)}`);
}

const tokenKey = mode === 'denied-scope' ? 'readonly_token' : 'full_token';
const token = tokens[tokenKey];
if (typeof token !== 'string' || token === '') {
  fail(`tokens file has no "${tokenKey}"`);
}

const transport = new StreamableHTTPClientTransport(new URL(baseUrl), {
  requestInit: { headers: { Authorization: `Bearer ${token}` } },
  reconnectionOptions: {
    maxReconnectionDelay: 1000,
    initialReconnectionDelay: 200,
    reconnectionDelayGrowFactor: 1,
    maxRetries: 0,
  },
});

let oncloseFired = false;
transport.onclose = () => {
  oncloseFired = true;
};

const client = new Client(
  { name: 'phlix-hub-s329-e2e-probe', version: '1.0.0' },
  { capabilities: {} },
);

/** Connect + initialize, then close cleanly on any exit. */
async function withSession(fn) {
  try {
    await client.connect(transport);
    return await fn();
  } finally {
    try {
      await client.close();
    } catch {
      // Closing a session that already failed is not the verdict; the
      // operation's own result above is.
    }
  }
}

const withTimeout = (promise, label) =>
  Promise.race([
    promise,
    new Promise((_, reject) => {
      const t = setTimeout(() => reject(new Error(`${label} timed out`)), TOOL_TIMEOUT_MS);
      t.unref?.();
    }),
  ]);

const run = async () => {
  switch (mode) {
    case 'initialize': {
      await withSession(async () => {
        const serverInfo = client.getServerVersion();
        if (!serverInfo || serverInfo.name !== 'phlix-hub') {
          fail('unexpected serverInfo', serverInfo);
        }
        process.stdout.write(
          JSON.stringify({
            ok: true,
            mode,
            serverInfo,
            protocolVersion: transport.protocolVersion,
            capabilities: client.getServerCapabilities(),
          }) + '\n',
        );
      });
      return;
    }

    case 'list-tools': {
      await withSession(async () => {
        const listed = await withTimeout(client.listTools(), 'tools/list');
        const names = (listed.tools ?? []).map((tool) => tool.name);
        if (!names.includes('list_servers')) {
          fail('tools/list did not include list_servers', { names });
        }
        process.stdout.write(JSON.stringify({ ok: true, mode, tools: names }) + '\n');
      });
      return;
    }

    case 'call-tool': {
      await withSession(async () => {
        const result = await withTimeout(
          client.callTool({ name: 'list_servers', arguments: {} }),
          'tools/call list_servers',
        );
        if (result.isError) {
          fail('list_servers came back isError', { result });
        }
        const text = Array.isArray(result.content)
          ? result.content.map((c) => c.text ?? '').join('\n')
          : '';
        if (text === '') {
          fail('list_servers returned no text content', { result });
        }
        process.stdout.write(
          JSON.stringify({
            ok: true,
            mode,
            tool: 'list_servers',
            result: {
              isError: result.isError,
              structuredContent: result.structuredContent ?? null,
              text,
            },
          }) + '\n',
        );
      });
      return;
    }

    case 'denied-scope': {
      await withSession(async () => {
        const result = await withTimeout(
          client.callTool({ name: 'playback_control', arguments: {} }),
          'tools/call playback_control',
        );
        // Fail-closed means the hub must ANSWER with a denied result — never
        // run the tool. The SDK surfaces it as a normal CallToolResult with
        // isError: true, exactly as the hub intends (a denied call is not a
        // transport failure).
        const text = Array.isArray(result.content)
          ? result.content.map((c) => c.text ?? '').join('\n')
          : '';
        if (!result.isError) {
          fail('playback_control was NOT denied — the scope gate is fail-open', { result });
        }
        if (!text.includes('mcp.scope_denied')) {
          fail('denied result does not name mcp.scope_denied', { result, text });
        }
        process.stdout.write(
          JSON.stringify({
            ok: true,
            mode,
            tool: 'playback_control',
            result: { isError: result.isError, structuredContent: result.structuredContent ?? null, text },
          }) + '\n',
        );
      });
      return;
    }

    case 'clean-close': {
      // Close HERE, inside the session, so the assertion runs AFTER close():
      // `withSession`'s finally would otherwise close after we had already
      // checked the flag.
      await client.connect(transport);
      const serverInfo = client.getServerVersion();
      if (!serverInfo || serverInfo.name !== 'phlix-hub') {
        fail('unexpected serverInfo', serverInfo);
      }
      await withTimeout(client.close(), 'close');
      if (!oncloseFired) {
        fail('transport.onclose did not fire after close()', { oncloseFired });
      }
      process.stdout.write(JSON.stringify({ ok: true, mode, oncloseFired }) + '\n');
      return;
    }

    default:
      fail(`unknown mode "${mode}"`);
  }
};

run().catch((error) => {
  fail(error instanceof Error ? error.message : String(error));
});
