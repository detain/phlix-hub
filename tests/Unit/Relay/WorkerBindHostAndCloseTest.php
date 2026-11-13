<?php

/**
 * Phlix hub component: Relay tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayWorker;
use ReflectionClass;

/**
 * HUB-S32: :8802/:8805 listen-host knobs (default preserved) and no raw
 * close(string) arms left in FederationWorker's connect path.
 */
final class WorkerBindHostAndCloseTest extends TestCase
{
    public function testDefaultBindHostIsHistoricalLiteral(): void
    {
        foreach ([FederationWorker::class, RelayWorker::class] as $cls) {
            $param = (new ReflectionClass($cls))->getConstructor()?->getParameters();
            $found = null;
            foreach ($param ?? [] as $p) {
                if ($p->getName() === 'bindHost') {
                    $found = $p->getDefaultValue();
                }
            }
            self::assertSame('0.0.0.0', $found, $cls);
        }
    }

    public function testFederationConnectPathHasNoRawStringClose(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../src/Relay/FederationWorker.php');
        self::assertSame(0, preg_match("/->close\\('(invalid_path|unknown_hub|internal_error)'\\)/", $src));
        self::assertStringContainsString('WebSocketCloseFrame::reject($connection, self::CLOSE_POLICY)', $src);
    }
}
