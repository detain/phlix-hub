<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S258 — the PHP extensions the hub's security path calls into must be
 * EXPLICIT and ASSERTED, not inherited by accident from a base image.
 *
 * ## The defect
 *
 * `.github/workflows/ci.yml` named `extensions: json, pcntl, posix[, swoole]`.
 * `openssl` and `curl` appeared nowhere — yet
 * {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware} (S90) is the ONLY
 * authentication the entire Alexa surface has, and without those two it cannot
 * run at all: no `openssl_verify()`, no `openssl_x509_parse()`, no chain fetch.
 * `sodium` (the Ed25519 enrollment JWT), `mbstring`, `hash`, `filter` and
 * `ctype` were in the same position. They were present only because
 * `shivammathur/setup-php`'s base build ships them.
 *
 * An extension present *by accident* is indistinguishable from one present *by
 * intent* until an upstream image change removes it — and at that moment the
 * security gate does not fail for a security reason. It errors, or skips, or
 * `openssl_verify()` becomes an undefined function inside the middleware's own
 * fail-closed `catch (\Throwable)` and every Alexa request becomes a 400 that
 * reads exactly like a rejected signature.
 *
 * ## ⚠ Why there is no `markTestSkipped()` anywhere in this file
 *
 * `markTestSkipped('openssl missing')` would REPRODUCE the defect. In this
 * estate a skipped test exits 0 and GitHub records the check as SUCCESS, there
 * is no branch protection, and a security gate whose tests quietly skip is
 * indistinguishable from one that passes. A missing extension must be RED.
 *
 * ## ⚠ How the list was derived — and why not from the environment
 *
 * From the CALL SITES. A required-extension list computed from `get_loaded_extensions()`
 * self-adjusts to whatever is installed and can therefore never fail; this
 * program already has a recorded case of a detector firing on its own
 * documentation. So {@see REQUIRED} is a literal, and every row names the
 * concrete symbol the hub calls plus the file that calls it —
 * {@see testEveryRequiredExtensionIsStillCalledByTheSource()} asserts the call
 * is still there, so an entry can only survive while the code still justifies it.
 *
 * ## What each assertion defends
 *
 * | mutation                                                     | this test |
 * | ------------------------------------------------------------ | --------- |
 * | an extension is genuinely absent at run time                   | RED       |
 * | the extension loads but its symbol is undefined (partial build) | RED      |
 * | `openssl`/`curl`/… is dropped from a job's `extensions:` list   | RED       |
 * | a PHP job stops running the gate script                         | RED       |
 * | the gate step gains `continue-on-error`                         | RED       |
 * | the script's list and this file's list drift apart              | RED       |
 * | the script stops exiting non-zero on a missing extension        | RED       |
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class RequiredPhpExtensionsTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    private const SCRIPT = self::REPO_ROOT . '/scripts/assert-required-extensions.php';

    private const WORKFLOW = self::REPO_ROOT . '/.github/workflows/ci.yml';

    /**
     * The contract, duplicated from `scripts/assert-required-extensions.php` ON
     * PURPOSE — {@see testTheScriptDeclaresExactlyThisContract()} pins the two
     * copies together, so neither can be edited alone. `extension => [symbol
     * the hub calls, file that calls it]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const REQUIRED = [
        'openssl' => ['openssl_verify', 'src/Http/Middleware/AlexaSignatureMiddleware.php'],
        'curl' => ['curl_exec', 'src/Alexa/CurlCertChainFetcher.php'],
        'sodium' => ['sodium_crypto_sign_verify_detached', 'src/Hub/EnrollmentJwtService.php'],
        'mbstring' => ['mb_substr', 'src/Http/Middleware/AlexaSignatureMiddleware.php'],
        'json' => ['json_decode', 'src/Http/Middleware/AlexaSignatureMiddleware.php'],
        'hash' => ['hash_hmac', 'src/Auth/JwtHandler.php'],
        'filter' => ['filter_var', 'src/Common/Http/TrustedProxyResolver.php'],
        'ctype' => ['ctype_digit', 'src/Common/Http/TrustedProxyResolver.php'],
        'posix' => ['posix_kill', 'src/Http/Controllers/HubRestartController.php'],
    ];

    /**
     * Every workflow job that sets PHP up and then puts hub source in front of a
     * PHP process — by execution (`phpunit`) or by reflection (`phpstan`,
     * `psalm`) or by tokenisation (`phpcs`). All four already declare an
     * `extensions:` key; the rule is that a job which configures extensions at
     * all configures every extension the code needs.
     *
     * `composer-validate`, `composer-audit` and `openapi` are excluded because
     * they never load hub source, so requiring the list there would be the noisy
     * rule that eventually gets deleted.
     *
     * @var list<string>
     */
    private const PHP_JOBS = ['phpcs', 'phpstan', 'psalm', 'phpunit'];

    // -----------------------------------------------------------------------
    // 1. The extensions are actually here. No skips, no guards.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredExtensionProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::REQUIRED) as $extension) {
            $cases[$extension] = [$extension];
        }

        return $cases;
    }

    /**
     * @dataProvider requiredExtensionProvider
     */
    public function testEveryRequiredExtensionIsLoaded(string $extension): void
    {
        [$symbol, $source] = self::REQUIRED[$extension];

        self::assertTrue(
            extension_loaded($extension),
            sprintf(
                'ext/%s is NOT loaded. It is not optional: %s calls %s(). This assertion is '
                . 'deliberately a FAILURE and not a skip — a security gate whose tests skip when '
                . 'their crypto is missing is indistinguishable from one that passes (S258). Add '
                . '"%s" to the `extensions:` list of every PHP job in .github/workflows/ci.yml, or '
                . 'install it locally.',
                $extension,
                $source,
                $symbol,
                $extension,
            ),
        );
    }

    /**
     * A loaded extension is not the same as a usable one: a patched or partial
     * build can register the module and still not export the symbol the hub
     * calls. The gate is on the symbol, so that case is red too.
     *
     * @dataProvider requiredExtensionProvider
     */
    public function testEveryRequiredSymbolIsCallableHere(string $extension): void
    {
        [$symbol, $source] = self::REQUIRED[$extension];

        self::assertTrue(
            function_exists($symbol),
            sprintf(
                '%s() is undefined, so %s cannot run. ext/%s reports itself as %s.',
                $symbol,
                $source,
                $extension,
                extension_loaded($extension) ? 'LOADED' : 'not loaded',
            ),
        );
    }

    // -----------------------------------------------------------------------
    // 2. Each requirement is justified by CODE, not by the environment.
    // -----------------------------------------------------------------------

    /**
     * The anti-self-adjustment half. Each row must still be earned by a real
     * call in the file it names, so the list cannot quietly become "whatever
     * this machine has installed" and cannot keep requiring an extension whose
     * last call site was deleted.
     *
     * @dataProvider requiredExtensionProvider
     */
    public function testEveryRequiredExtensionIsStillCalledByTheSource(string $extension): void
    {
        [$symbol, $source] = self::REQUIRED[$extension];
        $path = self::REPO_ROOT . '/' . $source;

        self::assertFileExists($path, $source . ' is cited as the reason ext/' . $extension . ' is required');

        $code = (string) file_get_contents($path);

        // Strip block comments and line comments: this class's own prose, and
        // the cited file's docblocks, must not satisfy a check for a CALL.
        $stripped = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $code);

        self::assertMatchesRegularExpression(
            '/(?<![\w\\\\])' . preg_quote($symbol, '/') . '\s*\(/',
            $stripped,
            sprintf(
                'ext/%s is declared required because %s calls %s(), but no such call remains in that '
                . 'file (comments stripped). Either the requirement is stale and must be removed from '
                . 'BOTH scripts/assert-required-extensions.php and this test, or the call moved and '
                . 'the citation must be updated. Do not "fix" this by widening the pattern.',
                $extension,
                $source,
                $symbol,
            ),
        );
    }

    // -----------------------------------------------------------------------
    // 3. The script and this test cannot drift apart.
    // -----------------------------------------------------------------------

    public function testTheScriptDeclaresExactlyThisContract(): void
    {
        self::assertFileExists(self::SCRIPT);
        $source = (string) file_get_contents(self::SCRIPT);

        foreach (self::REQUIRED as $extension => [$symbol, $file]) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($extension, '/') . "'\s*=>\s*\[/",
                $source,
                'scripts/assert-required-extensions.php must still require ext/' . $extension,
            );
            self::assertStringContainsString(
                "'symbol' => '" . $symbol . "'",
                $source,
                'the script must cite ' . $symbol . '() as the reason for ext/' . $extension,
            );
            self::assertStringContainsString(
                "'source' => '" . $file . "'",
                $source,
                'the script must cite ' . $file . ' as the caller for ext/' . $extension,
            );
        }

        // An entry ADDED to the script but not here is drift in the other
        // direction, and drift is the next version of this bug.
        preg_match_all("/^    '(\w+)' => \[$/m", $source, $matches);
        self::assertNotSame(
            [],
            $matches[1],
            'the entry parser matched NOTHING — a parser that matches nothing reads exactly like a '
            . 'pass, so it is asserted non-empty before it is compared',
        );
        self::assertSame(
            array_keys(self::REQUIRED),
            $matches[1],
            'REQUIRED_EXTENSIONS in scripts/assert-required-extensions.php and self::REQUIRED here '
            . 'must list the same extensions in the same order.',
        );
    }

    // -----------------------------------------------------------------------
    // 4. The script is capable of failing.
    // -----------------------------------------------------------------------

    /**
     * You cannot unload an extension from a running PHP, so the failure path is
     * driven with `--also-require=<name>`, which can only ever ADD a
     * requirement and therefore cannot make the gate pass anything.
     *
     * A green run of this file would otherwise be satisfiable by a script that
     * always exits 0 — the commonest false pass in this estate.
     */
    public function testTheGateScriptExitsNonZeroWhenARequiredExtensionIsAbsent(): void
    {
        $absent = 'phlix_no_such_extension_' . bin2hex(random_bytes(4));

        $command = sprintf(
            '%s %s --also-require=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::SCRIPT),
            escapeshellarg($absent),
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);
        $text = implode("\n", $output);

        self::assertSame(1, $exit, 'a missing required extension must exit 1: ' . $text);
        self::assertStringContainsString('::error::', $text, 'the failure must be annotated for GitHub');
        self::assertStringContainsString($absent, $text, 'the failure must NAME the missing extension');

        // Matched as a member of the MISSING line rather than as its whole
        // value: on a host that is ALSO genuinely missing a real requirement the
        // line reads "MISSING: curl, <injected>", and this assertion must not go
        // red for that — the injected name being listed is the whole claim.
        self::assertMatchesRegularExpression(
            '/^MISSING: .*\b' . preg_quote($absent, '/') . '\b/m',
            $text,
        );
    }

    /**
     * The positive control beside it: with no injected requirement the same
     * script exits 0 and states the corpus it inspected. A gate that inspected
     * zero extensions would read exactly like a pass, so the count is asserted
     * against this file's own list.
     */
    public function testTheGateScriptPassesHereAndStatesItsCorpus(): void
    {
        $output = [];
        $exit = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::SCRIPT)), $output, $exit);
        $text = implode("\n", $output);

        self::assertSame(0, $exit, $text);
        self::assertStringContainsString(
            sprintf('Checked %d required extension(s)', count(self::REQUIRED)),
            $text,
            'the gate must state how many extensions it inspected — a gate that inspected none is '
            . 'indistinguishable from one that passed',
        );
    }

    // -----------------------------------------------------------------------
    // 5. CI declares the same contract, in every PHP job.
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function phpJobProvider(): array
    {
        $cases = [];
        foreach (self::PHP_JOBS as $job) {
            $cases[$job] = [$job];
        }

        return $cases;
    }

    /**
     * The named job's block from ci.yml with comment-only lines removed, so a
     * commented-out `# extensions: …` cannot satisfy anything here.
     */
    private function job(string $name): string
    {
        self::assertFileExists(self::WORKFLOW);

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  ' . preg_quote($name, '/') . ':\s*$/', $line) === 1) {
                $inJob = true;
                continue;
            }
            if ($inJob && preg_match('/^  \S/', $line) === 1) {
                break;
            }
            if (!$inJob || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }
            $block[] = $line;
        }

        self::assertNotSame([], $block, 'ci.yml must still define a `' . $name . ':` job');

        return implode("\n", $block);
    }

    /**
     * @dataProvider phpJobProvider
     */
    public function testEveryPhpJobNamesEveryRequiredExtension(string $job): void
    {
        $block = $this->job($job);

        self::assertMatchesRegularExpression(
            '/^\s+extensions:\s*\S/m',
            $block,
            'the `' . $job . '` job must declare an `extensions:` list rather than inheriting '
            . 'whatever setup-php\'s base build happens to ship (S258)',
        );

        preg_match('/^\s+extensions:\s*(.+)$/m', $block, $matches);
        $declared = array_map('trim', explode(',', $matches[1] ?? ''));

        foreach (array_keys(self::REQUIRED) as $extension) {
            self::assertContains(
                $extension,
                $declared,
                sprintf(
                    'The `%s` job must name "%s" in its `extensions:` list. Declared: [%s]. Naming it '
                    . 'is what converts "present by accident of the base image" into "present because '
                    . 'we asked for it" — %s calls %s() and cannot run without it (S258).',
                    $job,
                    $extension,
                    implode(', ', $declared),
                    self::REQUIRED[$extension][1],
                    self::REQUIRED[$extension][0],
                ),
            );
        }
    }

    /**
     * Naming an extension is necessary but NOT sufficient, and this is the
     * measured reason: in the pinned setup-php
     * (`f3e473d116dcccaddc5834248c87452386958240`), `src/scripts/unix.sh` sets
     * `fail_fast="${fail_fast:-${FAIL_FAST:-false}}"` and only exits 1 when it
     * is `true`. On a default `ubuntu-latest` run an extension setup-php could
     * not install is logged as a red cross and the step still SUCCEEDS. The gate
     * script is therefore what turns an absence into a failure, and every PHP
     * job has to run it.
     *
     * @dataProvider phpJobProvider
     */
    public function testEveryPhpJobRunsTheExtensionGate(string $job): void
    {
        $block = $this->job($job);

        self::assertStringContainsString(
            'scripts/assert-required-extensions.php',
            $block,
            'the `' . $job . '` job must run scripts/assert-required-extensions.php: setup-php does '
            . 'NOT fail a step when a named extension could not be installed (fail_fast defaults to '
            . 'false), so without this step the `extensions:` list is documentation, not a gate',
        );

        $steps = preg_split('/^(?=      - name:)/m', $block) ?: [];
        $gate = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, 'scripts/assert-required-extensions.php'),
        ));

        self::assertCount(1, $gate, 'exactly one step of `' . $job . '` must run the extension gate');
        self::assertStringNotContainsString(
            'continue-on-error',
            $gate[0],
            'the extension gate must not be advisory — `continue-on-error` makes a FAILED gate report '
            . 'success, which is the exact shape of defect S258 removes',
        );
    }
}
