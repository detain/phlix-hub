<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use FilesystemIterator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function ksort;
use function mkdir;
use function preg_match_all;
use function rmdir;
use function sort;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function token_get_all;
use function uniqid;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * S237: no relay / SyncPlay WebSocket worker may take a CREDENTIAL out of the
 * query string.
 *
 * ## Why this guard is over a SET of files, not over one line
 *
 * S2b removed `?token=` from the `:8803` client-mount tunnel. S237 then found
 * the identical construct alive on `:8804`, because S2b's guard — and S2b's
 * reviewer — were scoped to the file S2b touched. A test that only asserted
 * "`SyncPlayRelayWorker.php` line 150 changed" would leave the next worker free
 * to reintroduce it and would repeat that history a third time. So this test
 * derives its own subject set at runtime and holds ALL of it to the rule.
 *
 * ## What the rule actually forbids, and why it is wider than `$_GET`
 *
 * ⚠ A `$_GET`-only guard would be a guard against the BROKEN spelling and not
 * against the WORKING one. Workerman 5 never populates the `$_GET` superglobal
 * (the package contains no write to it), so `$_GET['token']` reads `null` on
 * every request — that is why S237's `:8804` defect was simultaneously an
 * information-disclosure risk and a total auth outage. The spelling that
 * genuinely WORKS in Workerman is `$request->get('token')`. A future
 * reintroduction is far likelier to use the working form. Both are forbidden
 * here, along with `$_REQUEST` (which merges the query string).
 *
 * The sanctioned carrier is {@see \Phlix\Hub\Relay\ClientRelayWorker::extractClientToken()}
 * — `Authorization: Bearer <token>` or `Sec-WebSocket-Protocol: bearer, <token>`.
 *
 * ## Worker coverage (recorded deliberately — an honest partial beats an
 * overclaiming whole)
 *
 * COVERED — every `.php` file under `src/Relay/` and `src/SyncPlay/`, plus any
 * file anywhere under `src/` that installs an `onWebSocketConnect` hook. That
 * last clause is what makes the set self-extending: a brand-new WS worker added
 * outside those two directories is picked up without editing this test.
 *
 * NOT COVERED — the ordinary HTTP surface (`src/Http/`, except a controller that
 * carries a WS connect hook). `src/Http/Request.php` legitimately reads `$_GET`
 * as a PSR-style request shim and is out of scope. Also NOT covered: the
 * `:8097` SyncPlay socket in phlix-server, which is a different repository and
 * a different auth path.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class RelayWorkerQueryStringCredentialTest extends TestCase
{
    /**
     * Directories whose every `.php` file is a relay/SyncPlay worker by
     * construction, relative to the repository root.
     *
     * @var list<string>
     */
    private const WORKER_DIRS = [
        'src/Relay',
        'src/SyncPlay',
    ];

    /**
     * Files the scan MUST find. This is the anti-vacuity floor: if the discovery
     * silently stops working (a directory renamed, a glob returning `false`, a
     * path assumption broken by a move), the guard must go RED rather than pass
     * over an empty set. Deleting one of these files is a deliberate act that
     * should require updating this list.
     *
     * @var list<string>
     */
    private const REQUIRED_MEMBERS = [
        'src/Http/Controllers/ClientMountController.php',
        'src/Http/Controllers/FederationRelayController.php',
        'src/Relay/ClientRelayWorker.php',
        'src/Relay/FederationWorker.php',
        'src/Relay/RelayWorker.php',
        'src/SyncPlay/SyncPlayRelayWorker.php',
    ];

    /**
     * Forbidden query-string credential reads, as `label => PCRE`.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        // Any use of the GET/REQUEST superglobals at all. There is no legitimate
        // reason for a resident Workerman worker to touch them (Workerman never
        // fills them), so the flat ban costs nothing and cannot be tiptoed past
        // with a variable subscript like `$_GET[$name]`.
        'GET/REQUEST superglobal' => '/\$_(?:GET|REQUEST)\b/',
        // The spelling that actually works in Workerman: pulling a
        // credential-named parameter off the request query.
        'credential-named query parameter' =>
            '/->get\(\s*[\'"](?:token|access_token|auth|authorization|api_key|apikey|secret|password|jwt)[\'"]/i',
    ];

    /**
     * The guard itself: every discovered worker is clean.
     */
    public function testNoRelayOrSyncPlayWorkerReadsACredentialFromTheQueryString(): void
    {
        $files = self::discoverWorkers(self::repoRoot());

        self::assertScanIsNotVacuous($files);

        $offences = [];
        foreach ($files as $relative => $absolute) {
            $source = file_get_contents($absolute);
            self::assertIsString($source, "Could not read {$relative}");

            foreach (self::FORBIDDEN as $label => $pattern) {
                $hits = preg_match_all($pattern, self::stripComments($source));
                if ($hits > 0) {
                    $offences[] = "{$relative}: {$hits}× {$label}";
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "S237: a relay/SyncPlay WebSocket worker takes a credential from the query string.\n"
            . "Query strings are written to access logs, proxy logs and `Referer` headers and\n"
            . "outlive the credential's own expiry. Use ClientRelayWorker::extractClientToken()\n"
            . "(Authorization: Bearer, or the `bearer` WebSocket subprotocol) instead.\n"
            . 'Offending: ' . implode('; ', $offences),
        );
    }

    /**
     * ANTI-VACUITY, part 1 — the scan cannot pass over nothing.
     *
     * Points the exact same discovery + non-vacuity assertion at an EMPTY tree.
     * If `assertScanIsNotVacuous()` were ever loosened into a no-op, this test
     * goes red, so the main test above can never become a trivial pass by way of
     * finding zero files.
     */
    public function testTheGuardFailsLoudlyWhenItDiscoversNoWorkers(): void
    {
        $empty = sys_get_temp_dir() . '/phlix-hub-s237-empty-' . uniqid();
        mkdir($empty, 0700, true);

        try {
            $files = self::discoverWorkers($empty);
            self::assertSame([], $files, 'control: an empty tree must yield an empty scan');

            $this->expectException(AssertionFailedError::class);
            self::assertScanIsNotVacuous($files);
        } finally {
            if (is_dir($empty)) {
                rmdir($empty);
            }
        }
    }

    /**
     * ANTI-VACUITY, part 2 — the DETECTOR fires.
     *
     * A non-vacuous file set proves nothing if the patterns match nothing. Each
     * planted violation varies the SHAPE, not merely the count: a literal
     * subscript, a variable subscript, the `$_REQUEST` sibling, and both the
     * single- and double-quoted forms of the working `->get()` spelling. A guard
     * validated by one plant is a guard validated against one spelling.
     *
     * @dataProvider plantedViolations
     */
    public function testTheDetectorFiresOnEachPlantedShape(string $shape, string $expectedLabel): void
    {
        $matched = [];
        foreach (self::FORBIDDEN as $label => $pattern) {
            if (preg_match_all($pattern, $shape) > 0) {
                $matched[] = $label;
            }
        }

        self::assertContains(
            $expectedLabel,
            $matched,
            "The S237 detector did NOT fire on: {$shape}",
        );
    }

    /**
     * Negative control for the detector: shapes that MUST NOT match, so the
     * patterns are not so broad that they would be deleted as noise the first
     * time a container lookup trips them.
     *
     * @dataProvider innocentShapes
     */
    public function testTheDetectorStaysSilentOnInnocentShapes(string $shape): void
    {
        foreach (self::FORBIDDEN as $label => $pattern) {
            self::assertSame(
                0,
                preg_match_all($pattern, $shape),
                "S237 false positive ({$label}) on: {$shape}",
            );
        }
    }

    /**
     * The comment stripper is the one place this guard could go quietly blind:
     * strip too much and a real violation hides inside something the tokenizer
     * mislabels. So pin BOTH directions on one fixture — prose mentioning the
     * banned construct survives, executable code carrying it does not.
     */
    public function testCommentsAreExemptButTheCodeAroundThemIsNot(): void
    {
        $documented = <<<'PHP'
            <?php
            // Historical note: this worker used to read $_GET['token'].
            /** @see $_REQUEST['token'] — also banned. Never use $request->get('token'). */
            $token = ClientRelayWorker::extractClientToken($request);
            PHP;

        foreach (self::FORBIDDEN as $label => $pattern) {
            self::assertSame(
                0,
                preg_match_all($pattern, self::stripComments($documented)),
                "S237 guard fired ({$label}) on PROSE, not code — the rule would forbid "
                . 'documenting itself, and a rule that cannot be explained gets deleted.',
            );
        }

        $violating = <<<'PHP'
            <?php
            // Historical note: this worker used to read $_GET['token'].
            $token = $_GET['token'] ?? null;
            PHP;

        self::assertSame(
            1,
            preg_match_all(self::FORBIDDEN['GET/REQUEST superglobal'], self::stripComments($violating)),
            'the stripper swallowed a REAL violation that sat next to a comment mentioning it',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plantedViolations(): iterable
    {
        yield 'the original S237 line, verbatim' => [
            '$token = $_GET[\'token\'] ?? null;',
            'GET/REQUEST superglobal',
        ];
        yield 'superglobal with a VARIABLE subscript' => [
            '$token = $_GET[$name] ?? null;',
            'GET/REQUEST superglobal',
        ];
        yield 'superglobal copied wholesale' => [
            '$query = $_GET;',
            'GET/REQUEST superglobal',
        ];
        yield 'the $_REQUEST sibling' => [
            '$token = $_REQUEST[\'token\'];',
            'GET/REQUEST superglobal',
        ];
        yield 'the spelling that actually WORKS in Workerman' => [
            '$token = $request->get(\'token\');',
            'credential-named query parameter',
        ];
        yield 'double-quoted, different receiver' => [
            '$t = $req->get("access_token");',
            'credential-named query parameter',
        ];
        yield 'mixed case, whitespace inside the call' => [
            '$t = $request->get( \'Token\' );',
            'credential-named query parameter',
        ];
        yield 'a password lifted from the query' => [
            '$pw = $request->get(\'password\');',
            'credential-named query parameter',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function innocentShapes(): iterable
    {
        yield 'PSR-11 container lookup by class name' => [
            '$svc = $this->container->get(ClientRelayTokenService::class);',
        ];
        yield 'a non-credential query parameter' => [
            '$page = $request->get(\'page\');',
        ];
        yield 'the sanctioned header carrier' => [
            '$token = ClientRelayWorker::extractClientToken($request);',
        ];
        yield 'a local variable that merely mentions the word token' => [
            '$tokenService->validate($token);',
        ];
        yield 'reading the Authorization header' => [
            '$auth = $request->header(\'authorization\');',
        ];
    }

    /**
     * Discover the guarded worker set beneath `$root`.
     *
     * Two clauses, deliberately: every `.php` under the worker directories, plus
     * every file under `src/` that installs an `onWebSocketConnect` hook. The
     * second clause is what makes the set self-extending — a new WS worker
     * dropped anywhere in `src/` is guarded on the day it lands, without anyone
     * remembering to add it here.
     *
     * @param string $root Repository root to scan (parameterised so the
     *                     anti-vacuity test can aim it at an empty tree).
     *
     * @return array<string, string> Map of repo-relative path => absolute path.
     */
    private static function discoverWorkers(string $root): array
    {
        $found = [];

        foreach (self::WORKER_DIRS as $dir) {
            $matches = glob($root . '/' . $dir . '/*.php');
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $absolute) {
                $found[self::relative($root, $absolute)] = $absolute;
            }
        }

        foreach (self::phpFilesUnder($root . '/src') as $absolute) {
            $source = file_get_contents($absolute);
            if ($source !== false && str_contains($source, 'onWebSocketConnect')) {
                $found[self::relative($root, $absolute)] = $absolute;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string> Absolute paths of every `.php` file beneath `$dir`.
     */
    private static function phpFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile() && $info->getExtension() === 'php') {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The scan found a real, recognisable worker set — not zero files, and not a
     * set missing a member we know exists.
     *
     * @param array<string, string> $files
     */
    private static function assertScanIsNotVacuous(array $files): void
    {
        self::assertNotSame(
            [],
            $files,
            'S237 guard is VACUOUS: it discovered zero relay/SyncPlay workers, so it '
            . 'would pass no matter what those workers contained.',
        );

        $discovered = array_keys($files);
        foreach (self::REQUIRED_MEMBERS as $required) {
            self::assertContains(
                $required,
                $discovered,
                "S237 guard is INCOMPLETE: it did not discover {$required}, a known relay/SyncPlay "
                . 'worker. Either the discovery broke or the file moved; fix one of them rather '
                . 'than letting the guard silently shrink.',
            );
        }

        self::assertGreaterThanOrEqual(
            count(self::REQUIRED_MEMBERS),
            count($files),
            'S237 guard discovered fewer files than its own required floor.',
        );
    }

    /**
     * Strip comments and docblocks, keeping only executable PHP.
     *
     * The rule is over what the worker DOES, not over what it says. Without this
     * the guard would forbid a maintainer from writing down WHY the query-string
     * carrier is banned — including the explanation now sitting in
     * `SyncPlayRelayWorker::onWebSocketConnect()` — which is the surest way to
     * get a useful rule deleted as noise. Comment text is replaced by a newline
     * rather than removed, so nothing on either side is accidentally joined into
     * a new match.
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) ? "\n" : $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    private static function relative(string $root, string $absolute): string
    {
        return str_replace($root . '/', '', $absolute);
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
