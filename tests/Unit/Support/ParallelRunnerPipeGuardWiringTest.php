<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function token_get_all;
use function trim;

/**
 * S461 — the detectCores() pipe guards must keep their shape.
 *
 * ## What is pinned and why a source assertion is the honest venue
 *
 * scripts/parallel/ParallelTestRunner.php::detectCores() probes `nproc` through
 * proc_open. Two shapes are pinned here:
 *
 *  1. the read `$probe = is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';`
 *  2. the conditional close `if (is_resource($pipes[1])) { fclose($pipes[1]); }`
 *  3. the bucket-done line feeding `(int) $id` (not a bare key) to `sprintf("%d")`.
 *
 * A behavioural regression test for the guarded path is NOT honestly constructible:
 * detectCores() is private static, hardcodes `['nproc']`, and the only way
 * `$pipes[1]` stops being a resource is proc_open returning a resource while
 * failing to open the descriptor — unreachable in-process, and Reflection cannot
 * substitute a broken pipe into the function's own local. Fabricating a fixture
 * that "injects" a null pipe would test the fixture, not the runner (S345 rule 2).
 * So this test pins the SHIPPED SHAPE against the production file's tokenized
 * (comment-stripped) source — the same venue the estate already uses for scripts
 * (RawHeaderIndexGateTest, CrossRepoPathAssertCiWiringTest) — and every mutation
 * below reverts one shipped shape to its pre-S461 form and requires the scanner
 * to report it, so the pin cannot pass vacuously (S345 rule 3): the scanner's
 * "target not found" rows make deleting or renaming detectCores() red too.
 *
 * Comment stripping is load-bearing: this class's own prose recreates the guarded
 * lines verbatim, and a comment-only copy must never satisfy the pin.
 *
 * The happy path itself is exercised for real by the runner's CI invocation
 * (`run-parallel-tests.php -- …`), which fails loudly when detectCores() returns
 * a garbage core count; this test defends the failure path the CI run cannot reach.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class ParallelRunnerPipeGuardWiringTest extends TestCase
{
    /** Lane survival token (S461 hub). Code-resident by design; never prose. */
    public const string SURVIVAL_TOKEN = 'S461PIPEGUARDX9K2';

    private const RUNNER = __DIR__ . '/../../../scripts/parallel/ParallelTestRunner.php';

    public function testTheScannerActuallyReadsTheProductionRunnerFile(): void
    {
        $raw = file_get_contents(self::RUNNER);
        self::assertNotFalse($raw, 'the parallel runner must exist at the pinned path');
        self::assertGreaterThan(20_000, strlen($raw), 'runner read looks truncated — wrong path?');
        self::assertStringContainsString('function detectCores', $raw);
        // The token names the lane; referencing it here keeps it code-resident.
        self::assertStringNotContainsString(self::SURVIVAL_TOKEN, $raw, 'the runner is not the token host');
    }

    public function testTheShippedRunnerCarriesNoPipeGuardViolations(): void
    {
        $violations = self::violations(self::stripComments(self::runnerSource()));

        self::assertSame([], $violations, "guarded pipe shape drifted:\n" . implode("\n", $violations));
    }

    public function testRevertingTheStreamGuardToTheUnguardedReadIsDetected(): void
    {
        $mutated = self::replaceExactlyOnce(
            self::runnerSource(),
            "\$probe = is_resource(\$pipes[1]) ? (string) stream_get_contents(\$pipes[1]) : '';",
            "\$probe = (string) stream_get_contents(\$pipes[1]);",
        );

        $violations = self::violations(self::stripComments($mutated));
        self::assertNotEmpty($violations, 'unguarded read must be reported');
        self::assertStringContainsString('unguarded read', $violations[0]);
    }

    public function testRevertingTheConditionalFcloseToAnUnguardedCloseIsDetected(): void
    {
        $mutated = self::replaceExactlyOnce(
            self::runnerSource(),
            "        if (is_resource(\$pipes[1])) {\n            fclose(\$pipes[1]);\n        }\n",
            "        fclose(\$pipes[1]);\n",
        );

        $violations = self::violations(self::stripComments($mutated));
        self::assertNotEmpty($violations, 'unguarded fclose must be reported');
        self::assertStringContainsString('unguarded close', $violations[0]);
    }

    public function testRevertingTheBucketDoneIntIdCastIsDetected(): void
    {
        $mutated = self::replaceExactlyOnce(
            self::runnerSource(),
            '", (int) $id, $t));',
            '", $id, $t));',
        );

        $violations = self::violations(self::stripComments($mutated));
        self::assertNotEmpty($violations, 'bare $id in the bucket-done sprintf must be reported');
        self::assertStringContainsString('(int) $id', $violations[0]);
    }

    public function testALostDetectCoresTargetIsReportedNotSilentlyPassed(): void
    {
        $renamed = self::replaceExactlyOnce(
            self::runnerSource(),
            'function detectCores(): int',
            'function detectCoresRenamed(): int',
        );

        $violations = self::violations(self::stripComments($renamed));
        self::assertNotEmpty($violations, 'a scanner that lost its target must fail, not pass');
        self::assertStringContainsString('detectCores() not found', $violations[0]);
    }

    public function testALostBucketDoneLineIsReportedNotSilentlyPassed(): void
    {
        $deleted = self::replaceExactlyOnce(
            self::runnerSource(),
            'fwrite(STDOUT, sprintf("  bucket #%d done @ %.1fs\\n", (int) $id, $t));',
            '/* bucket-done line removed by mutation */',
        );

        $violations = self::violations(self::stripComments($deleted));
        self::assertNotEmpty($violations, 'a missing bucket-done line must fail, not pass');
        self::assertStringContainsString('bucket-done line not found', $violations[0]);
    }

    // ── scanner ──────────────────────────────────────────────────────────────

    /**
     * Every violation of the pinned shapes; empty means the file is guarded.
     * Operates on comment-stripped source only.
     *
     * @return list<string>
     */
    private static function violations(string $strippedSource): array
    {
        $violations = [];

        $body = self::detectCoresBody($strippedSource);
        if ($body === null) {
            $violations[] = 'detectCores() not found in the guarded file — pin lost its target';

            return $violations + self::castViolations($strippedSource);
        }

        $reads = self::countLiteral($body, 'stream_get_contents($pipes[1])');
        if ($reads === 0) {
            $violations[] = 'detectCores() no longer reads pipes[1] — pin lost its target';
        }
        $guardedReads = self::matchCount(
            '/is_resource\(\s*\$pipes\[1\]\s*\)\s*\?\s*\(string\)\s*stream_get_contents\(\s*\$pipes\[1\]\s*\)/',
            $body,
        );
        if ($guardedReads !== $reads) {
            $violations[] = sprintf(
                'unguarded read of pipes[1] in detectCores(): %d read(s), %d inside the is_resource ternary',
                $reads,
                $guardedReads,
            );
        }

        $closes = self::countLiteral($body, 'fclose($pipes[1])');
        if ($closes === 0) {
            $violations[] = 'detectCores() no longer closes pipes[1] — pin lost its target';
        }
        $guardedCloses = self::matchCount(
            '/if\s*\(\s*is_resource\(\s*\$pipes\[1\]\s*\)\s*\)\s*\{\s*fclose\(\s*\$pipes\[1\]\s*\)\s*;/',
            $body,
        );
        if ($guardedCloses !== $closes) {
            $violations[] = sprintf(
                'unguarded close of pipes[1] in detectCores(): %d fclose(s), %d inside the is_resource guard',
                $closes,
                $guardedCloses,
            );
        }

        return $violations + self::castViolations($strippedSource);
    }

    /**
     * @return list<string>
     */
    private static function castViolations(string $strippedSource): array
    {
        $needle = 'bucket #%d done';
        $found = 0;
        foreach (explode("\n", $strippedSource) as $line) {
            if (!str_contains($line, $needle)) {
                continue;
            }
            $found++;
            if (preg_match('/,\s*\(int\)\s+\$id\s*,/', $line) !== 1) {
                return ['bucket-done sprintf must pass (int) $id for its %d — got: ' . trim($line)];
            }
        }

        return $found === 1
            ? []
            : [sprintf('bucket-done line not found exactly once (found %d) — pin lost its target', $found)];
    }

    /**
     * The detectCores() body braces are balanced and its strings contain no
     * braces (checked against the shipped file; a mutation that changes that
     * changes the verdict — which is also a finding), so a plain depth count
     * over comment-stripped source extracts it exactly.
     */
    private static function detectCoresBody(string $strippedSource): ?string
    {
        $start = strpos($strippedSource, 'function detectCores(): int');
        if ($start === false) {
            return null;
        }
        $open = strpos($strippedSource, '{', $start);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($strippedSource);
        for ($i = $open; $i < $length; $i++) {
            if ($strippedSource[$i] === '{') {
                $depth++;
            } elseif ($strippedSource[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($strippedSource, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    private static function countLiteral(string $haystack, string $needle): int
    {
        return self::matchCount('/' . preg_quote($needle, '/') . '/', $haystack);
    }

    /**
     * preg_match_all whose failure mode is a loud exception, never a silently
     * skipped check: a scanner that swallowed its own PCRE error would report
     * "no violations" exactly when it stopped seeing anything (S345 rule 3).
     */
    private static function matchCount(string $pattern, string $subject): int
    {
        $count = preg_match_all($pattern, $subject);
        if ($count === false) {
            throw new RuntimeException("preg_match_all failed for pattern: {$pattern}");
        }

        return $count;
    }

    /**
     * Remove comments, keep one newline per removed comment so line-oriented
     * checks above still see the original line structure.
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * Fixture builder guard: a mutation that would apply zero or several times is
     * a broken fixture, not a passing test (the assert keeps S345 rule 3 honest).
     */
    private static function replaceExactlyOnce(string $source, string $search, string $replace): string
    {
        $count = 0;
        $mutated = str_replace($search, $replace, $source, $count);
        if ($count !== 1) {
            TestCase::fail(sprintf('mutation fixture applied %d times, expected exactly 1: %s', $count, $search));
        }

        return $mutated;
    }

    private static function runnerSource(): string
    {
        $raw = file_get_contents(self::RUNNER);
        self::assertNotFalse($raw);

        return $raw;
    }
}
