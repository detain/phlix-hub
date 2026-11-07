<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Http\Controllers\LogController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the hub admin log viewer: listing, tailing, line clamping,
 * the merged tail-all stream, and — most importantly — that the `file`
 * parameter cannot escape the log dir (traversal / absolute path / non-.log).
 */
final class LogControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix_hub_logtest_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/app.log', "l1\nl2\nl3\nl4\nl5\n");
        file_put_contents($this->dir . '/error.log', "e1\ne2\n");
        // A non-log file + a secret outside the dir that traversal must not reach.
        file_put_contents($this->dir . '/notes.txt', "secret\n");
        file_put_contents(dirname($this->dir) . '/phlix_outside_secret', "TOPSECRET\n");
    }

    protected function tearDown(): void
    {
        foreach (['app.log', 'error.log', 'notes.txt', 'a.log', 'b.log'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @unlink(dirname($this->dir) . '/phlix_outside_secret');
        @rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function req(array $query): Request
    {
        $r = new Request();
        $r->userId = 'admin-1';
        $r->query = $query;
        return $r;
    }

    public function testIndexListsOnlyLogFiles(): void
    {
        $controller = new LogController($this->dir);
        $body = self::arrayNode(json_decode($controller->index($this->req([]))->body, true));

        $files = self::arrayNode($body['files']);
        $names = array_map(static fn (mixed $f): string => self::stringNode(self::arrayNode($f)['name']), $files);
        sort($names);
        $this->assertSame(['app.log', 'error.log'], $names); // notes.txt excluded
        $this->assertArrayHasKey('size', self::arrayNode($files[0]));
        $this->assertArrayHasKey('modified_at', self::arrayNode($files[0]));
    }

    public function testTailReturnsLastNLines(): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => 'app.log', 'lines' => '2']));

        $this->assertSame(200, $resp->statusCode);
        $body = self::arrayNode(json_decode($resp->body, true));
        $this->assertSame(['l4', 'l5'], $body['lines']);
        $this->assertSame('app.log', $body['file']);
    }

    public function testTailDefaultsAndReturnsAllWhenFewerLines(): void
    {
        $controller = new LogController($this->dir);
        $body = self::arrayNode(json_decode($controller->tail($this->req(['file' => 'error.log']))->body, true));
        $this->assertSame(['e1', 'e2'], $body['lines']);
    }

    /**
     * @dataProvider maliciousFileNames
     */
    public function testTailRejectsTraversalAndNonLogNames(string $file, int $expectedStatus): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => $file]));
        $this->assertSame($expectedStatus, $resp->statusCode);
        // Never leaks the out-of-jail secret.
        $this->assertStringNotContainsString('TOPSECRET', $resp->body);
        $this->assertStringNotContainsString('secret', $resp->body);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function maliciousFileNames(): array
    {
        return [
            'parent traversal'      => ['../phlix_outside_secret', 400],
            'absolute path'         => ['/etc/passwd', 400],
            'non-log extension'     => ['notes.txt', 400],
            'nested traversal .log' => ['../../etc/shadow.log', 404],
            'empty'                 => ['', 400],
        ];
    }

    public function testMissingFileReturns404(): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => 'nope.log']));
        $this->assertSame(404, $resp->statusCode);
    }

    public function testTailRefusalFramesSpeakTheHouseEnvelope(): void
    {
        // Review hub__copywriter: these frames were the corpus's prose-only
        // holdouts. Each now dual-places a REGISTERED stable code and an
        // actionable message beside its untouched legacy `error` prose; the
        // literals' registry membership is ErrorCodesContractTest's job —
        // this pin guards the SHAPE and the actionable next moves.
        $reachable = new LogController($this->dir);

        $bad = json_decode($reachable->tail($this->req(['file' => 'notes.txt']))->body, true);
        $this->assertIsArray($bad);
        $this->assertSame('Invalid log file name', $bad['error']);
        $this->assertSame('invalid_request', $bad['code']);
        $this->assertIsString($bad['message']);
        $this->assertStringContainsString('bare *.log file name', $bad['message']);

        $absentDir = new LogController('/nonexistent/phlix-hub/logs');
        $gone = json_decode($absentDir->tail($this->req(['file' => 'app.log']))->body, true);
        $this->assertIsArray($gone);
        $this->assertSame('Log directory not available', $gone['error']);
        $this->assertSame('storage_unavailable', $gone['code']);
        $this->assertStringContainsString('.logs/', $gone['message']);

        $missing = json_decode($reachable->tail($this->req(['file' => 'nope.log']))->body, true);
        $this->assertIsArray($missing);
        $this->assertSame('Log file not found', $missing['error']);
        $this->assertSame('not_found', $missing['code']);
        // The actionable half of the finding: the answer names where to look.
        $this->assertStringContainsString('GET /api/v1/me/logs', $missing['message']);
    }

    public function testTailAllMergesEveryLogFileTaggedBySource(): void
    {
        $controller = new LogController($this->dir);
        $body = self::arrayNode(json_decode($controller->tailAll($this->req([]))->body, true));

        // Lists every .log file that was merged (notes.txt excluded).
        $mergedFiles = self::arrayNode($body['files']);
        sort($mergedFiles);
        $this->assertSame(['app.log', 'error.log'], $mergedFiles);

        // Every source line appears, each prefixed with its file name.
        $lines = self::arrayNode($body['lines']);
        $joined = implode("\n", array_map(static fn (mixed $l): string => self::stringNode($l), $lines));
        $this->assertStringContainsString('app.log', $joined);
        $this->assertStringContainsString('error.log', $joined);
        foreach (['l1', 'l5', 'e1', 'e2'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
        // app.log (5) + error.log (2) = 7 merged lines.
        $this->assertCount(7, $lines);
        $this->assertFalse($body['truncated']);
        // Never leaks the non-.log file.
        $this->assertStringNotContainsString('secret', $joined);
    }

    public function testTailAllOrdersByLeadingTimestampAcrossFiles(): void
    {
        // Two files whose timestamped lines interleave chronologically.
        file_put_contents(
            $this->dir . '/a.log',
            "[2026-05-15T10:00:00.000000-04:00] a.INFO: first\n"
            . "[2026-05-15T10:00:30.000000-04:00] a.INFO: third\n",
        );
        file_put_contents(
            $this->dir . '/b.log',
            "[2026-05-15T10:00:15.000000-04:00] b.INFO: second\n"
            . "[2026-05-15T10:00:45.000000-04:00] b.INFO: fourth\n",
        );

        $controller = new LogController($this->dir);
        $body = self::arrayNode(json_decode($controller->tailAll($this->req([]))->body, true));
        $rawLines = self::arrayNode($body['lines']);
        $joined = implode("\n", array_map(static fn (mixed $l): string => self::stringNode($l), $rawLines));

        $this->assertLessThan(strpos($joined, 'second'), strpos($joined, 'first'));
        $this->assertLessThan(strpos($joined, 'third'), strpos($joined, 'second'));
        $this->assertLessThan(strpos($joined, 'fourth'), strpos($joined, 'third'));
    }

    public function testTailAllRespectsLineCap(): void
    {
        $controller = new LogController($this->dir);
        // app.log has 5, error.log 2 → 7 total; cap at 3.
        $body = self::arrayNode(json_decode($controller->tailAll($this->req(['lines' => '3']))->body, true));

        $this->assertCount(3, self::arrayNode($body['lines']));
        $this->assertTrue($body['truncated']);
    }

    public function testMissingLogDirYieldsEmptyListing(): void
    {
        $controller = new LogController('/nonexistent/phlix-hub/logs');
        $body = self::arrayNode(json_decode($controller->index($this->req([]))->body, true));
        $this->assertSame([], $body['files']);
    }

    /**
     * w09L5 MINOR fold (review hub__copywriter era chain): the tail() 500-catch
     * arm — added so read failures stop vanishing unlogged — ships UNPINNED
     * without this case. The filesystem cannot drive it from a unit context
     * (is_file() gates dirs/fifos away and root bypasses chmod, so no real
     * file reliably fails fopen), hence the constructor's optional
     * $tailReader seam. This pins both halves of the arm's law: the wire
     * frame is the registered byte-identical 500 envelope whose REASON never
     * rides the response, and the reason DOES reach the hub HTTP log channel.
     * Mutation check: strip the catch and the injected throw escapes this
     * test red — nothing else covers the arm.
     */
    public function testTailReadFailureIs500EnvelopeWithReasonOnTheLogChannelOnly(): void
    {
        $logFile = $this->dir . '/channel.log';
        file_put_contents(
            $this->dir . '/logger.php',
            "<?php return ['default' => 'file', 'handlers' => ['file' => "
            . "['type' => 'stream', 'path' => '" . $logFile . "', 'level' => 'debug']]];",
        );
        \Phlix\Hub\Common\Logger\LoggerFactory::reset();
        \Phlix\Hub\Common\Logger\LoggerFactory::init($this->dir . '/logger.php');
        try {
            $controller = new LogController($this->dir, static function (string $path, int $maxBytes): string {
                throw new \RuntimeException('injected-open-failure');
            });

            $resp = $controller->tail($this->req(['file' => 'app.log']));

            $this->assertSame(500, $resp->statusCode);
            $body = self::arrayNode(json_decode($resp->body, true));
            $this->assertSame('Failed to read log file', $body['error']);
            $this->assertSame('hub.internal_error', $body['code']);
            $this->assertStringContainsString('HTTP log', self::stringNode($body['message']));
            // The reason never reaches the wire — it is operator-channel only.
            $this->assertStringNotContainsString('injected-open-failure', $resp->body);

            $written = is_file($logFile) ? (string) file_get_contents($logFile) : '';
            $this->assertStringContainsString('injected-open-failure', $written);
            $this->assertStringContainsString('app.log', $written);
        } finally {
            \Phlix\Hub\Common\Logger\LoggerFactory::reset();
            @unlink($logFile);
            @unlink($this->dir . '/logger.php');
        }
    }
}
