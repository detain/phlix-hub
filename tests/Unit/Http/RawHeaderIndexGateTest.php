<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shape gate: nothing in `src/` may index another object's `$headers` bag with
 * a string literal. Use the accessors instead —
 * {@see \Phlix\Hub\Http\Request::getHeader()} to read an inbound header and
 * {@see \Phlix\Hub\Http\Response::header()} to write an outbound one.
 *
 * WHY THIS EXISTS (S205). {@see \Phlix\Hub\Http\Request::fromWorkerman()} runs
 * every inbound header name through `strtoupper()`
 * (`collectHeadersFromWorkerman()`), and Workerman itself has already
 * lowercased them (`Workerman\Protocols\Http\Request::parseHeaders()` line
 * `$key = strtolower($parts[0]);`). The `fromGlobals()` path is uppercase too
 * (`HTTP_AUTHORIZATION` -> `AUTHORIZATION`). So the ONLY key shape that can
 * ever exist at runtime is `SCREAMING-KEBAB-CASE`, and a literal such as
 * `$request->headers['Authorization']` silently misses on every real request.
 * Because those reads were all written as `?? ''`, the miss degraded to an
 * empty string rather than an error and three bearer-token gates rejected
 * unconditionally in production while their unit tests — which hand-set the
 * mixed-case key on a bare `new Request()` — stayed green.
 *
 * The rule is deliberately NOT "the literal must be uppercase". Uppercasing the
 * literal fixes the symptom and leaves the trap armed for the next reader, who
 * has no way to know the array is normalised. The rule is that the raw bag is
 * not indexed by literal at all: `getHeader()` is case-insensitive
 * (`strcasecmp`) and therefore correct whatever the boundary does.
 *
 * `$this->headers[...]` is exempt: inside `Request`/`Response` the bag is the
 * object's own storage and those classes are where the normalisation lives.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class RawHeaderIndexGateTest extends TestCase
{
    /**
     * No `$var->headers['literal']` anywhere in `src/`.
     */
    public function testNoLiteralKeyIndexingOfAHeaderBagInSrc(): void
    {
        $violations = self::scanSrcForLiteralHeaderIndexing();

        self::assertSame(
            [],
            $violations,
            "src/ must never index a header bag with a string literal — the inbound bag is\n"
            . "case-normalised at the Workerman boundary, so a literal key is a silent miss.\n"
            . "Read with \$request->getHeader('Name'); write with \$response->header('Name', \$v).\n"
            . 'Offenders:' . "\n" . implode("\n", $violations),
        );
    }

    /**
     * CONTROL for the gate above. A zero result is not evidence until the same
     * scanner is shown to be capable of returning non-zero, so run it over a
     * fixture that contains one offender of each shape — mixed case, lowercase,
     * and (crucially) UPPERCASE, which works today but is exactly the form the
     * rule refuses to bless.
     */
    public function testScannerDetectsEveryLiteralIndexShapeIncludingUppercase(): void
    {
        $fixture = <<<'PHP'
        <?php
        $a = $request->headers['Authorization'] ?? '';
        $b = $request->headers['upgrade'] ?? '';
        $c = $request->headers['AUTHORIZATION'] ?? '';
        $d = $request?->headers['X-Real-IP'] ?? '';
        $ok1 = $this->headers['Content-Type'];
        $ok2 = $headers['X-Phlix-Relay'];
        $ok3 = $request->getHeader('Authorization');
        $ok4 = $request->headers[$name];
        foreach ($request->headers as $k => $v) {
        }
        PHP;

        $found = self::scanSourceForLiteralHeaderIndexing($fixture, 'fixture.php');

        self::assertCount(4, $found, 'scanner missed a shape: ' . implode(' | ', $found));
        self::assertStringContainsString("'Authorization'", $found[0]);
        self::assertStringContainsString("'upgrade'", $found[1]);
        self::assertStringContainsString("'AUTHORIZATION'", $found[2]);
        self::assertStringContainsString("'X-Real-IP'", $found[3]);
    }

    /**
     * CONTROL for the file walk. The scanner must actually be reading the
     * production tree — a walk over an empty/mistyped path also returns `[]`,
     * which would make the gate above pass vacuously forever.
     */
    public function testTheWalkActuallyReachesTheProductionTree(): void
    {
        $files = self::srcFiles();

        self::assertGreaterThan(100, count($files), 'src/ walk found suspiciously few files');
        self::assertContains(
            self::srcDir() . '/Http/Controllers/RelayController.php',
            $files,
        );
        self::assertContains(
            self::srcDir() . '/Http/Request.php',
            $files,
        );
    }

    /**
     * @return list<string> `path:line — literal` for each offender, in file order.
     */
    private static function scanSrcForLiteralHeaderIndexing(): array
    {
        $violations = [];
        foreach (self::srcFiles() as $path) {
            $code = file_get_contents($path);
            if ($code === false) {
                continue;
            }
            foreach (self::scanSourceForLiteralHeaderIndexing($code, $path) as $violation) {
                $violations[] = $violation;
            }
        }
        return $violations;
    }

    /**
     * Token-walk one PHP source for `$var->headers['literal']` /
     * `$var?->headers['literal']`, excluding `$this`.
     *
     * A token walk rather than a regex: a regex over `->headers\[` cannot tell
     * `$request->headers['X']` (a read of the normalised bag) from
     * `$headers['X'] = …` (building a fresh local array, which is fine) without
     * getting the receiver, and cannot exempt `$this`.
     *
     * @return list<string>
     */
    private static function scanSourceForLiteralHeaderIndexing(string $code, string $path): array
    {
        $tokens = token_get_all($code);
        /** @var list<array{0:int,1:string,2:int}|string> $significant */
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $violations = [];
        $count = count($significant);
        for ($i = 0; $i + 4 < $count; $i++) {
            $variable = $significant[$i];
            if (!is_array($variable) || $variable[0] !== T_VARIABLE || $variable[1] === '$this') {
                continue;
            }
            $arrow = $significant[$i + 1];
            if (!is_array($arrow)) {
                continue;
            }
            $isArrow = $arrow[0] === T_OBJECT_OPERATOR
                || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $arrow[0] === T_NULLSAFE_OBJECT_OPERATOR);
            if (!$isArrow) {
                continue;
            }
            $property = $significant[$i + 2];
            if (!is_array($property) || $property[0] !== T_STRING || $property[1] !== 'headers') {
                continue;
            }
            if ($significant[$i + 3] !== '[') {
                continue;
            }
            $key = $significant[$i + 4];
            if (!is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $violations[] = $path . ':' . $property[2] . ' — ' . $variable[1] . '->headers[' . $key[1] . ']';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function srcFiles(): array
    {
        $files = [];
        /** @var SplFileInfo $file */
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::srcDir(), RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file
        ) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private static function srcDir(): string
    {
        return dirname(__DIR__, 3) . '/src';
    }
}
