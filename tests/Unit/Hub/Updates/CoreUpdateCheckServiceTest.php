<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Updates;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\CoreUpdateStatus;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see CoreUpdateCheckService} — S75 / updates.md #48.
 *
 * Everything is driven over a round-tripping `hub_settings` double
 * ({@see InMemoryHubSettingsConnection}) so a "the check persisted it" claim
 * and a "the status endpoint reads it" claim are checked against the SAME
 * store rather than two independent stubs.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Updates
 */
#[CoversClass(CoreUpdateCheckService::class)]
#[CoversClass(CoreUpdateStatus::class)]
final class CoreUpdateCheckServiceTest extends TestCase
{
    private const MARKER_URL = 'https://example.invalid/VERSION';

    private const UPDATE_COMMAND = 'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y';

    private string $tmpDir = '';

    private InMemoryHubSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-updates-svc-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        // Config fixture mirroring config/updates.php's shape.
        file_put_contents(
            $this->tmpDir . '/updates.php',
            "<?php\n\nreturn ['check_enabled' => true];\n",
        );

        $this->db = new InMemoryHubSettingsConnection();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * A fetcher that answers synchronously with a fixed body/error and counts
     * its calls, so "did a fetch happen at all" is directly observable.
     */
    private function fetcher(?string $body, ?string $error = null): VersionMarkerFetcherInterface
    {
        return new class ($body, $error) implements VersionMarkerFetcherInterface {
            public int $calls = 0;

            /** @var list<string> */
            public array $urls = [];

            public function __construct(
                private readonly ?string $body,
                private readonly ?string $error,
            ) {
            }

            public function fetch(string $url, callable $onDone): void
            {
                $this->calls++;
                $this->urls[] = $url;
                $onDone($this->body, $this->error);
            }
        };
    }

    private function service(
        VersionMarkerFetcherInterface $fetcher,
        string $currentVersion = '0.5.0',
    ): CoreUpdateCheckService {
        return new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $fetcher,
            LoggerFactory::get('hub'),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
            $currentVersion,
        );
    }

    public function testStatusBeforeAnyCheckReportsNoUpdateAndNoTimestamp(): void
    {
        $status = $this->service($this->fetcher('0.9.9'))->status();

        self::assertSame('0.5.0', $status->currentVersion);
        self::assertNull($status->latestVersion);
        self::assertFalse($status->updateAvailable);
        self::assertNull($status->lastCheckedAt);
        self::assertNull($status->lastError);
        self::assertTrue($status->checkEnabled);
        self::assertSame(self::UPDATE_COMMAND, $status->updateCommand);
    }

    /**
     * THE headline acceptance criterion at service level: a seeded NEWER
     * marker must make the persisted status report an update.
     */
    public function testCheckAgainstANewerMarkerPersistsAnUpdateAvailableStatus(): void
    {
        $fetcher = $this->fetcher("0.9.9\n");
        $service = $this->service($fetcher);

        $seen = null;
        $service->check(static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen = $status;
        });

        self::assertSame(1, $fetcher->calls);
        self::assertSame([self::MARKER_URL], $fetcher->urls);

        self::assertInstanceOf(CoreUpdateStatus::class, $seen);
        self::assertTrue($seen->updateAvailable);
        self::assertSame('0.9.9', $seen->latestVersion);

        // Re-read through a FRESH service over the same store: this is the read
        // path the HTTP status endpoint takes.
        $reread = $this->service($this->fetcher(null, 'must not be fetched'))->status();
        self::assertTrue($reread->updateAvailable);
        self::assertSame('0.9.9', $reread->latestVersion);
        self::assertNull($reread->lastError);
        self::assertIsInt($reread->lastCheckedAt);
        self::assertGreaterThan(0, $reread->lastCheckedAt);
    }

    public function testCheckAgainstAnIdenticalMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher("0.5.0\n"));
        $service->check();

        $status = $service->status();
        self::assertFalse($status->updateAvailable);
        self::assertSame('0.5.0', $status->latestVersion);
    }

    public function testCheckAgainstAnOlderMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher('0.4.9'));
        $service->check();

        self::assertFalse($service->status()->updateAvailable);
    }

    /**
     * A captive-portal / error page must NOT be stored as a version, and must
     * never raise a false "update available".
     */
    public function testAnUnparseableMarkerIsRecordedAsAnErrorAndNeverAsAVersion(): void
    {
        $service = $this->service($this->fetcher('<html>404 not found</html>'));
        $service->check();

        $status = $service->status();
        self::assertFalse($status->updateAvailable);
        self::assertNull($status->latestVersion);
        self::assertIsString($status->lastError);
        self::assertStringContainsString('semver', $status->lastError);
        self::assertIsInt($status->lastCheckedAt);
    }

    public function testATransportErrorIsRecordedAndLeavesTheLastKnownVersionIntact(): void
    {
        $good = $this->service($this->fetcher('0.9.9'));
        $good->check();
        self::assertSame('0.9.9', $good->status()->latestVersion);

        $bad = $this->service($this->fetcher(null, 'connection refused'));
        $bad->check();

        $status = $bad->status();
        self::assertSame('connection refused', $status->lastError);
        // The previously-learned version survives a failed poll.
        self::assertSame('0.9.9', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
    }

    /**
     * The toggle must gate the FETCH, not just the reporting: a disabled check
     * that still hits the network every day is not disabled.
     */
    public function testADisabledCheckPerformsNoFetchAtAll(): void
    {
        $fetcher = $this->fetcher('0.9.9');
        $service = $this->service($fetcher);
        $service->setCheckEnabled(false);

        $seen = null;
        $service->check(static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen = $status;
        });

        self::assertSame(0, $fetcher->calls, 'a disabled update check must not reach the network');
        self::assertInstanceOf(CoreUpdateStatus::class, $seen);
        self::assertFalse($seen->checkEnabled);
        self::assertNull($seen->latestVersion);
    }

    public function testTheToggleRoundTripsAndDefaultsToTheConfigValue(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        self::assertTrue($service->isCheckEnabled(), 'default must be true');

        $service->setCheckEnabled(false);
        self::assertFalse($service->isCheckEnabled());

        $service->setCheckEnabled(true);
        self::assertTrue($service->isCheckEnabled());
    }

    /**
     * With no `config/updates.php` at all the check must fail OPEN — an
     * operator who never learns about a security release is the worse outcome.
     */
    public function testAnAbsentConfigDefaultFailsOpen(): void
    {
        @unlink($this->tmpDir . '/updates.php');

        self::assertTrue($this->service($this->fetcher('0.9.9'))->isCheckEnabled());
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function comparisons(): array
    {
        return [
            ['0.5.1', '0.5.0', true],
            ['1.0.0', '0.9.9', true],
            ['0.5.10', '0.5.9', true],
            ['v0.6.0', '0.5.0', true],
            ["0.6.0\n", '0.5.0', true],
            ['0.5.0', '0.5.0', false],
            ['0.4.9', '0.5.0', false],
            ['0.5.0-rc1', '0.5.0', false],
            ['0.5.0', '0.5.0-rc1', true],
            ['not-a-version', '0.5.0', false],
            ['', '0.5.0', false],
            ['0.5', '0.5.0', false],
            ['<html>0.9.9</html>', '0.5.0', false],
        ];
    }

    #[DataProvider('comparisons')]
    public function testIsNewer(string $candidate, string $current, bool $expected): void
    {
        self::assertSame($expected, CoreUpdateCheckService::isNewer($candidate, $current));
    }

    public function testNormaliseStripsDecorationAndRejectsNonVersions(): void
    {
        self::assertSame('1.2.3', CoreUpdateCheckService::normalise("  v1.2.3\n"));
        self::assertSame('1.2.3-beta.1', CoreUpdateCheckService::normalise('1.2.3-beta.1'));
        self::assertSame('1.2.3+build5', CoreUpdateCheckService::normalise('1.2.3+build5'));
        self::assertNull(CoreUpdateCheckService::normalise(''));
        self::assertNull(CoreUpdateCheckService::normalise('   '));
        self::assertNull(CoreUpdateCheckService::normalise('1.2'));
        self::assertNull(CoreUpdateCheckService::normalise('latest'));
    }

    /**
     * The status read path must not issue writes — it is called from an HTTP
     * handler on every admin page load.
     */
    public function testStatusPerformsOnlyReads(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        $service->check();

        $this->db->statements = [];
        $service->status();

        foreach ($this->db->statements as $statement) {
            self::assertStringNotContainsString('INSERT', $statement);
            self::assertStringNotContainsString('UPDATE', $statement);
        }
        self::assertNotSame([], $this->db->statements, 'status() must actually read the store');
    }

    /**
     * A completion callback that throws must not escape into the caller (in
     * production that caller is a Workerman timer tick).
     */
    public function testAThrowingCompletionCallbackIsContained(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));

        $service->check(static function (CoreUpdateStatus $status): void {
            throw new \RuntimeException('boom ' . $status->currentVersion);
        });

        self::assertSame('0.9.9', $service->status()->latestVersion);
    }
}
