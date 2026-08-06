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
use function preg_quote;
use function sort;
use function sprintf;
use function str_contains;
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
 *  1. **The corpus is asserted.** {@see test_the_corpus_is_not_empty()} fails if
 *     fewer than {@see MINIMUM_TOOLS} tool files are found, so a moved directory
 *     or a wrong glob reports as "nothing was inspected", not as a pass.
 *  2. **The detector is proven to fire.** {@see test_the_detector_actually_fires()}
 *     runs the SAME {@see forbiddenSymbolsIn()} routine over synthetic sources
 *     that each contain one banned symbol, and requires a hit for every one. A
 *     rule nobody can trigger is not a rule.
 *  3. **Comments are stripped first.** {@see strippedSource()} removes every
 *     `T_COMMENT`/`T_DOC_COMMENT` token before matching, so a docblock that
 *     NAMES `RelayProxyBridge` (as several of these files deliberately do, to
 *     explain why they must not use it) cannot make the detector fire on its own
 *     documentation. {@see test_comment_stripping_actually_removes_prose()}
 *     proves the stripping works rather than assuming it.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 */
final class McpToolIsolationTest extends TestCase
{
    /**
     * Floor on the tool corpus. Five tools ship in S62; the floor is set at the
     * shipped count so DELETING a tool is also a deliberate act.
     */
    private const int MINIMUM_TOOLS = 5;

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
    public function test_a_tool_reaches_a_server_only_through_the_context(string $file): void
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
     * The corpus must not be silently empty.
     */
    public function test_the_corpus_is_not_empty(): void
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
    public function test_the_detector_actually_fires(string $symbol): void
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
    public function test_the_detector_ignores_its_own_documentation(): void
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
    public function test_comment_stripping_actually_removes_prose(): void
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
    public function test_every_file_in_the_tools_directory_is_a_tool(string $file): void
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
