<?php

/**
 * Phlix hub test: Unit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the Major 40 operator seam on config/logger.php: verbosity and
 * destination are env-dialable, the shipped default is 'info' (NOT the old
 * hardcoded 'debug' that grew one huge file per day with no byte cap —
 * RotatingFileHandler rotates by day count), and a malformed level fails
 * loud at boot instead of silently degrading.
 *
 * @package Phlix\Hub\Tests\Unit\Config
 */
final class LoggerConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('HUB_LOG_DIR');
        putenv('HUB_LOG_LEVEL');
    }

    public function testDefaultsAreInfoUnderInstallLogs(): void
    {
        $config = require __DIR__ . '/../../../config/logger.php';

        self::assertSame('info', $config['handlers']['file']['level']);
        self::assertStringEndsWith('/.logs/app.log', $config['handlers']['file']['path']);
        // The secondary channels keep their fixed semantics.
        self::assertSame('error', $config['handlers']['error']['level']);
        self::assertSame('info', $config['handlers']['audit']['level']);
    }

    public function testLevelAndDirectoryAreOperatorDialable(): void
    {
        putenv('HUB_LOG_LEVEL=debug');
        putenv('HUB_LOG_DIR=/var/log/phlix-hub/');

        $config = require __DIR__ . '/../../../config/logger.php';

        self::assertSame('debug', $config['handlers']['file']['level']);
        // Trailing separator normalized away; every channel follows the dir.
        self::assertSame('/var/log/phlix-hub/app.log', $config['handlers']['file']['path']);
        self::assertSame('/var/log/phlix-hub/audit.log', $config['handlers']['audit']['path']);
    }

    public function testInvalidLevelFailsLoudAtBoot(): void
    {
        putenv('HUB_LOG_LEVEL=verbose');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid HUB_LOG_LEVEL');

        require __DIR__ . '/../../../config/logger.php';
    }
}
