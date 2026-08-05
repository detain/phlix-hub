<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Updates;

use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The repository's root `VERSION` file IS the update marker every deployed hub
 * polls (S75): `config/updates.php`'s `marker_url` points at this exact file on
 * `master`.
 *
 * So it is not decoration — if it drifts BEHIND {@see Version::VERSION}, every
 * hub in the estate believes it is ahead of master and no release ever
 * announces itself; if it drifts AHEAD, every hub nags about an update that was
 * never cut. This suite pins it to the compiled constant, and pins that it is
 * parseable by the very code that will parse it in production.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Updates
 */
#[CoversNothing]
final class VersionMarkerFileTest extends TestCase
{
    private function markerPath(): string
    {
        return dirname(__DIR__, 4) . '/VERSION';
    }

    public function testTheRootVersionFileExists(): void
    {
        self::assertFileExists(
            $this->markerPath(),
            'config/updates.php advertises this file as the update marker; a hub polling '
            . 'master would get a 404 page instead of a version',
        );
    }

    public function testTheMarkerMatchesTheCompiledVersionConstant(): void
    {
        $raw = file_get_contents($this->markerPath());
        self::assertIsString($raw);

        self::assertSame(
            Version::VERSION,
            trim($raw),
            'VERSION and Phlix\Hub\Version::VERSION must be bumped together — the file is '
            . 'what other installs compare themselves against',
        );
    }

    public function testTheMarkerIsParseableByTheProductionComparator(): void
    {
        $raw = file_get_contents($this->markerPath());
        self::assertIsString($raw);

        self::assertSame(Version::VERSION, CoreUpdateCheckService::normalise($raw));
        self::assertFalse(
            CoreUpdateCheckService::isNewer($raw, Version::VERSION),
            'a hub running this exact commit must not be told an update is available',
        );
    }
}
