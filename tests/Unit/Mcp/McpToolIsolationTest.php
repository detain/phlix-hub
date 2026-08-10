<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

use function array_map;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function token_get_all;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * Structural guard: an MCP tool must stay unable to reach a media server by any
 * route except {@see \Phlix\Hub\Mcp\McpToolContext} (S62).
 *
 * ## Why a structural test and not just a behavioural one
 *
 * {@see McpCrossUserIsolationTest} proves the ownership gate refuses a
 * cross-user request TODAY, for the five tools that exist today. It cannot prove
 * anything about the sixth tool somebody adds next quarter. This one can: it
 * asserts the shape that makes the gate unavoidable — a tool file mentions no
 * database connection, no relay bridge, no server-ownership handler and no HTTP
 * request — so a tool that opens its own path to a server fails the build
 * instead of quietly bypassing the check the other test pins.
 *
 * ## Anti-vacuity, three ways
 *
 * A detector that finds nothing is indistinguishable from a detector that finds
 * no problems, and this repository has been bitten by that repeatedly. So:
 *
 *  1. **The corpus is asserted.** {@see testTheCorpusIsNotEmpty()} fails if
 *     fewer than {@see MINIMUM_TOOLS} tool files are found, so a moved directory
 *     or a wrong glob reports as "nothing was inspected", not as a pass.
 *  2. **The detector is proven to fire.** {@see testTheDetectorActuallyFires()}
 *     runs the SAME {@see forbiddenSymbolsIn()} routine over synthetic sources
 *     that each contain one banned symbol, and requires a hit for every one. A
 *     rule nobody can trigger is not a rule.
 *  3. **Comments are stripped first.** {@see strippedSource()} removes every
 *     `T_COMMENT`/`T_DOC_COMMENT` token before matching, so a docblock that
 *     NAMES `RelayProxyBridge` (as several of these files deliberately do, to
 *     explain why they must not use it) cannot make the detector fire on its own
 *     documentation. {@see testCommentStrippingActuallyRemovesProse()}
 *     proves the stripping works rather than assuming it.
 *
 * ## Why there is deliberately no `@covers`
 *
 * This suite loads no production class and executes no production line: it reads
 * `src/Mcp/Tools/*.php` as TEXT (`file_get_contents` + `token_get_all`) and
 * matches patterns against it. There is therefore nothing for it to be credited
 * with, and adding an `@covers` for the tool classes it INSPECTS would be a lie
 * of exactly the kind `@covers` exists to prevent — a class showing as covered
 * because a test named it, not because a test ran it. The tool classes earn
 * their coverage in {@see McpToolRegistryTest} (descriptors) and
 * {@see McpCrossUserIsolationTest} (`call()`), which do run them.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
final class McpToolIsolationTest extends TestCase
{
    /**
     * Floor on the tool corpus. Five tools shipped in S62 and S63 added
     * `playback_control`, so the floor is six; it is set at the shipped count so
     * DELETING a tool is also a deliberate act.
     *
     * ⚠ The floor counts FILES, not registered tools. `playback_control` is
     * behind a default-off operator flag, so it is usually absent from the live
     * registry while always present on disk — and it is the file this suite
     * inspects. A floor derived from the registry would drop to five whenever
     * the flag is off, i.e. always, which is how a structural detector quietly
     * stops covering the one tool that can write.
     */
    private const int MINIMUM_TOOLS = 6;

    /**
     * The proxy's byte-streaming families
     * ({@see \Phlix\Hub\Http\Controllers\ServerProxyController::STREAMING_BODY_PREFIXES}).
     * Restated here rather than read from the controller ON PURPOSE: a list
     * derived from its subject self-adjusts with it, and would stop failing the
     * day somebody adds a family.
     *
     * @var list<string>
     */
    private const STREAMING_ROOTS = ['/hls', '/dash', '/media'];

    /**
     * Symbols a tool file must not mention in CODE.
     *
     * Each is a route to a media server, or to a user identity, that would
     * bypass {@see \Phlix\Hub\Mcp\McpToolContext} and therefore bypass the
     * ownership and browse-scope gates it exists to force every call through.
     *
     * `userId` is on the list for a different reason from the rest: a tool that
     * so much as reads a user id is deciding identity, and identity is decided
     * once, in the context, from the validated token.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'Connection',
        'RelayProxyBridge',
        'ServerProxyController',
        'ServerInfoHandler',
        'RelaySessionManager',
        'ServerListController',
        'McpTokenService',
        'RequestContext',
        'userId',
        'user_id',
        'PDO',
        'mysqli',
    ];

    /**
     * No tool file may name a forbidden symbol in code.
     *
     * @dataProvider toolFileProvider
     */
    public function testAToolReachesAServerOnlyThroughTheContext(string $file): void
    {
        $found = self::forbiddenSymbolsIn(self::strippedSource($file));

        self::assertSame(
            [],
            $found,
            sprintf(
                "%s names [%s] in code.\nAn MCP tool must reach a media server ONLY through "
                . "McpToolContext, which runs the call as the presenting token's user and hands it to "
                . "ServerProxyController's existing ownership + browse-scope gates. Any other route "
                . 're-opens exactly what those gates close. If this symbol is genuinely needed, the '
                . 'change belongs in McpToolContext, not here.',
                basename($file),
                implode(', ', $found),
            ),
        );
    }

    /**
     * No tool may target one of the proxy's BYTE-STREAMING families.
     *
     * `/hls`, `/dash` and `/media` are answered by
     * {@see \Phlix\Hub\Http\Controllers\ServerProxyController::buildStreamingResponse()},
     * which writes the body to a browser socket through a producer callback.
     * There is no socket behind an MCP tool call, so such a response would
     * arrive with an EMPTY body and read as an empty success.
     * {@see \Phlix\Hub\Mcp\McpToolContext::proxyGet()} refuses it explicitly,
     * but that guard is a backstop; THIS is the invariant that keeps it
     * unreached — no path literal in a tool opens one of those families.
     *
     * A path is often BUILT from more than one literal
     * (`'/api/v1/media/' . $id . '/playback-info'`), so this cannot demand that
     * every literal be a whole path. It bans the three streaming roots at the
     * START of a literal instead, which is the only position that can open one.
     *
     * @dataProvider toolFileProvider
     */
    public function testNoToolTargetsAByteStreamingPrefix(string $file): void
    {
        $code = self::strippedSource($file);

        $matched = preg_match_all("/'(\\/[^']*)'/", $code, $matches);
        self::assertNotFalse($matched);

        /** @var list<string> $literals */
        $literals = $matches[1];

        if ($literals === []) {
            // `list_servers` forwards nothing — it does not call proxyGet at
            // all. Assert that positively rather than passing vacuously.
            self::assertStringNotContainsString(
                'proxyGet',
                $code,
                basename($file) . ' calls proxyGet but forwards no path literal, so this check '
                . 'inspected nothing.',
            );

            return;
        }

        foreach ($literals as $literal) {
            foreach (self::STREAMING_ROOTS as $root) {
                self::assertFalse(
                    $literal === $root || str_starts_with($literal, $root . '/'),
                    sprintf(
                        '%s forwards "%s", which opens the %s byte-streaming family. Those are '
                        . 'written to a browser socket an MCP tool call does not have, so the result '
                        . 'would arrive with an empty body and read as an empty success.',
                        basename($file),
                        $literal,
                        $root,
                    ),
                );
            }
        }
    }

    /**
     * Control for the check above: the ban must actually fire on a literal that
     * DOES open a streaming family, or it is asserting nothing.
     *
     * @dataProvider streamingRootProvider
     */
    public function testTheStreamingPrefixBanActuallyFires(string $root): void
    {
        $literal = $root . '/job-1/seg-00001.ts';

        self::assertTrue(
            $literal === $root || str_starts_with($literal, $root . '/'),
            sprintf('the "%s" streaming-root rule cannot be triggered.', $root),
        );
        // ...and does NOT fire on a sibling that merely shares the prefix text.
        self::assertFalse(
            $root . 'X' === $root || str_starts_with($root . 'X', $root . '/'),
            sprintf('the "%s" rule over-matches a bare sibling like "%sX".', $root, $root),
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function streamingRootProvider(): array
    {
        return array_map(static fn (string $r): array => [$r], self::STREAMING_ROOTS);
    }

    /**
     * The corpus must not be silently empty.
     */
    public function testTheCorpusIsNotEmpty(): void
    {
        $files = self::toolFiles();

        self::assertGreaterThanOrEqual(
            self::MINIMUM_TOOLS,
            count($files),
            sprintf(
                'only %d MCP tool file(s) were found under src/Mcp/Tools, below the floor of %d. '
                . 'Either tools were deleted or this suite is looking in the wrong place; either way '
                . 'it inspected nothing meaningful.',
                count($files),
                self::MINIMUM_TOOLS,
            ),
        );
    }

    /**
     * The detector must fire on a source that DOES contain a banned symbol —
     * once per banned symbol, so no rule is quietly unreachable.
     *
     * @dataProvider forbiddenSymbolProvider
     */
    public function testTheDetectorActuallyFires(string $symbol): void
    {
        $synthetic = '<?php class Bad { public function go(' . $symbol . ' $x) { return $x; } }';

        self::assertContains(
            $symbol,
            self::forbiddenSymbolsIn(self::stripComments($synthetic)),
            sprintf('the "%s" rule cannot be triggered, so it is not enforcing anything.', $symbol),
        );
    }

    /**
     * ...and must NOT fire when the symbol appears only in a comment, which is
     * how several of these files legitimately explain the rule.
     */
    public function testTheDetectorIgnoresItsOwnDocumentation(): void
    {
        $synthetic = <<<'PHP'
        <?php
        /**
         * A tool must never take a Connection or a RelayProxyBridge; see
         * ServerProxyController for why. Nor may it read a userId.
         */
        // Also not a RelaySessionManager, and definitely not PDO or mysqli.
        class Good { public function go(): int { return 1; } }
        PHP;

        self::assertSame(
            [],
            self::forbiddenSymbolsIn(self::stripComments($synthetic)),
            'the detector fired on prose. It would then be impossible to DOCUMENT the rule in the '
            . 'files the rule applies to — which is how a rule gets deleted for being noisy.',
        );
    }

    /**
     * Comment stripping must actually remove prose. Without this, the previous
     * test could pass because the matcher is broken rather than because
     * stripping works.
     */
    public function testCommentStrippingActuallyRemovesProse(): void
    {
        $synthetic = "<?php\n/** unmistakable-marker-in-a-docblock */\n// unmistakable-marker-in-a-line\n\$x = 1;";
        $stripped = self::stripComments($synthetic);

        self::assertStringNotContainsString('unmistakable-marker-in-a-docblock', $stripped);
        self::assertStringNotContainsString('unmistakable-marker-in-a-line', $stripped);
        self::assertStringContainsString('$x = 1;', $stripped, 'stripping removed the CODE too.');
    }

    /**
     * Every tool file must implement the interface — otherwise a file could sit
     * in `src/Mcp/Tools/` passing this suite while never being a tool at all.
     *
     * @dataProvider toolFileProvider
     */
    public function testEveryFileInTheToolsDirectoryIsATool(string $file): void
    {
        self::assertStringContainsString(
            'implements McpToolInterface',
            self::strippedSource($file),
            basename($file) . ' lives in src/Mcp/Tools but does not implement McpToolInterface.',
        );
    }

    // ------------------------------------------------------------------
    // Machinery
    // ------------------------------------------------------------------

    /**
     * @return list<array{0: string}>
     */
    public static function toolFileProvider(): array
    {
        return array_map(static fn (string $f): array => [$f], self::toolFiles());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function forbiddenSymbolProvider(): array
    {
        return array_map(static fn (string $s): array => [$s], self::FORBIDDEN);
    }

    /**
     * @return list<string>
     */
    private static function toolFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Mcp/Tools/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Which forbidden symbols appear in `$code` (already comment-stripped).
     *
     * Matched on a word boundary so `Connection` does not fire on a variable
     * named `$connectionless`, and so `userId` does not fire on `$userIdent`.
     *
     * @return list<string>
     */
    private static function forbiddenSymbolsIn(string $code): array
    {
        $found = [];
        foreach (self::FORBIDDEN as $symbol) {
            if (preg_match('/\b' . preg_quote($symbol, '/') . '\b/', $code) === 1) {
                $found[] = $symbol;
            }
        }

        return $found;
    }

    private static function strippedSource(string $file): string
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, 'could not read ' . $file);
        self::assertNotSame('', $contents, $file . ' is empty, so nothing was inspected.');

        return self::stripComments($contents);
    }

    /**
     * Remove every comment / docblock token, leaving the code.
     */
    private static function stripComments(string $source): string
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $out[] = $token;
                continue;
            }
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $token[1];
        }

        $code = implode('', $out);
        self::assertTrue(
            str_contains($code, '<?php'),
            'comment stripping produced something that is not PHP source; the tokeniser path is broken.',
        );

        return $code;
    }
}
