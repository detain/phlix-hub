<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Mcp;

use function count;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function getenv;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function trim;

/**
 * S329 — the single source of truth for what the live MCP-client harness needs.
 *
 * Three consumers must agree about the same three facts — where the node
 * probe lives, what a usable node looks like, which SDK is pinned, and WHICH
 * test cases are the required live-session evidence:
 *
 *  - `tests/E2E/Mcp/McpClientSseE2ETest.php` (the PHPUnit suite that runs the
 *    probe against the running hub);
 *  - `scripts/ci-mcp-e2e-prereqs.php` (the cheap supply-side gate, S329's
 *    counterpart to S305's `ci-browser-e2e-prereqs.php`);
 *  - `scripts/assert-mcp-e2e-ran.php` (the proof-side gate that reads the
 *    JUnit report and fails when a required case is absent or skipped,
 *    S329's counterpart to S305's `assert-browser-e2e-ran.php`).
 *
 * If the workflow's idea of "installed" and the test's idea of "available"
 * live in different files, they drift — the S57 defect this shape exists to
 * prevent. They live here.
 *
 * @package Phlix\Hub\Tests\Support\Mcp
 * @since   S329 (real MCP client SSE session, CI-gated)
 */
final class McpE2EProbeEnvironment
{
    /**
     * The probe the whole harness drives: a thin wrapper over the OFFICIAL
     * `@modelcontextprotocol/sdk` client (see its own docblock).
     */
    public const string PROBE_SCRIPT = __DIR__ . '/../../E2E/Mcp/mcp-client-session.mjs';

    /**
     * The directory the pinned SDK is installed into by `npm ci` (run from
     * `tests/E2E/Mcp`, whose `package-lock.json` pins the exact version).
     */
    public const string SDK_PACKAGE_DIR = __DIR__ . '/../../E2E/Mcp/node_modules/@modelcontextprotocol/sdk';

    /**
     * The lockfile that pins the SDK version. The prereqs script reads the
     * PINNED version here and compares it with the INSTALLED version, so the
     * CI job cannot silently drift to a newer SDK than the one the probe was
     * written and reviewed against.
     */
    public const string SDK_PACKAGE_LOCK = __DIR__ . '/../../E2E/Mcp/package-lock.json';

    /**
     * Lowest node major the probe tolerates.
     *
     * The official SDK declares `engines: node >= 18`; 20 is chosen instead so
     * the harness runs on a maintained LTS line and the prereqs gate has
     * headroom over the floor.
     */
    public const int MIN_NODE_MAJOR = 20;

    /**
     * The live-session cases the JUnit gate demands, by exact class + method
     * name.
     *
     * The four S329 acceptance steps map one-to-one onto four cases
     * (initialise → tool list → one tool call → clean close); the fifth is the
     * denied-scope case; the sixth is the broken-transport negative control
     * that proves the harness goes RED when the transport is broken. The gate
     * script iterates this map whole, so a case renamed here without a rename
     * in the test file reds the build — and a case renamed in the test file
     * without a rename here silently stops being demanded, which is why
     * `tests/Unit/Support/McpE2EGateTest.php` reconciles the map against the
     * real class via reflection.
     *
     * @var array<string, list<string>>
     */
    public const array REQUIRED_CASES_BY_CLASS = [
        'Phlix\Hub\Tests\E2E\Mcp\McpClientSseE2ETest' => [
            'testRealMcpClientInitialisesAgainstTheRunningHub',
            'testRealMcpClientListsToolsAgainstTheRunningHub',
            'testRealMcpClientCallsOneToolAgainstTheRunningHub',
            'testRealMcpClientClosesCleanlyAgainstTheRunningHub',
            'testDeniedScopeIsRefusedFailClosedByTheRunningHub',
            'testTheProbeFailsAgainstABrokenTransport',
        ],
    ];

    /**
     * The total number of required cases across every class.
     */
    public static function requiredCaseCount(): int
    {
        $total = 0;
        foreach (self::REQUIRED_CASES_BY_CLASS as $methods) {
            $total += count($methods);
        }

        return $total;
    }

    /**
     * A node binary this harness can drive, or null when none is usable.
     *
     * Resolved from `PATH` (mirroring `BrowserProbeEnvironment::node()` in
     * phlix-server): the version check is against the major floor, so a
     * future runner image that ships node 22 or 24 needs no change here.
     */
    public static function node(): ?string
    {
        $candidates = [
            trim((string) (getenv('PHLIX_MCP_E2E_NODE') ?: '')),
            'node',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $resolved = self::nodeAt($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Whether `$candidate` is an executable node of an acceptable major.
     *
     * Used both by {@see node()} for the PATH candidates and by the prereqs
     * script's `--node=` override, so an override cannot smuggle a path that
     * is not a working node past the gate.
     *
     * @param string $candidate A command name or absolute path.
     */
    public static function nodeAt(string $candidate): ?string
    {
        /** @var list<string> $out */
        $out = [];
        $code = 0;
        exec(escapeshellarg($candidate) . ' --version 2>&1', $out, $code);
        if ($code !== 0 || $out === []) {
            return null;
        }
        $version = trim($out[0]);
        if (preg_match('/^v(\d+)\./', $version, $m) === 1 && (int) $m[1] >= self::MIN_NODE_MAJOR) {
            return $candidate;
        }

        return null;
    }

    /**
     * The installed SDK's main dist entry, or null when the SDK is not
     * installed where the CI job installs it.
     */
    public static function sdkDistFile(): ?string
    {
        $dist = self::SDK_PACKAGE_DIR . '/dist/esm/client/streamableHttp.js';
        if (!is_file($dist)) {
            return null;
        }

        return $dist;
    }

    /**
     * The version of the SDK currently installed under tests/E2E/Mcp, or null.
     */
    public static function installedSdkVersion(): ?string
    {
        $package = self::SDK_PACKAGE_DIR . '/package.json';
        if (!is_file($package)) {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($package), true);
        if (!is_array($decoded) || !is_string($decoded['version'] ?? null)) {
            return null;
        }

        return $decoded['version'];
    }

    /**
     * The version of the SDK pinned by tests/E2E/Mcp/package-lock.json, or
     * null when the lockfile cannot be read.
     */
    public static function pinnedSdkVersion(): ?string
    {
        if (!is_file(self::SDK_PACKAGE_LOCK)) {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents(self::SDK_PACKAGE_LOCK), true);
        if (!is_array($decoded) || !is_array($decoded['packages'] ?? null)) {
            return null;
        }
        $entry = $decoded['packages']['node_modules/@modelcontextprotocol/sdk'] ?? null;
        if (!is_array($entry) || !is_string($entry['version'] ?? null)) {
            return null;
        }

        return $entry['version'];
    }
}
