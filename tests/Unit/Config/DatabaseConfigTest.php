<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Guards the named connections config/database.php must expose.
 *
 * The DI factories that resolve them would fatal at boot if a name went missing:
 * the metrics flush uses 'metrics' (isolated from request traffic) and the
 * transaction-running heartbeat/claim handlers use 'txn' (isolated from the
 * cid<0 maintenance reapers that otherwise trip 2014 / "already active
 * transaction" on the shared 'mysql' socket).
 */
final class DatabaseConfigTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function config(): array
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = require __DIR__ . '/../../../config/database.php';

        return $config;
    }

    public function testDefinesTheNamedConnections(): void
    {
        $config = $this->config();

        foreach (['mysql', 'metrics', 'txn'] as $name) {
            $this->assertArrayHasKey($name, $config, "config/database.php must define the '{$name}' connection");
            foreach (['host', 'port', 'user', 'password', 'database'] as $key) {
                $this->assertArrayHasKey($key, $config[$name], "'{$name}' connection is missing '{$key}'");
            }
        }
    }

    public function testIsolatedConnectionsMirrorTheMysqlCredentials(): void
    {
        $config = $this->config();

        // The isolation is at the PDO-socket level, NOT a different database —
        // 'txn' and 'metrics' carry the SAME credentials as 'mysql'.
        $this->assertSame($config['mysql'], $config['txn']);
        $this->assertSame($config['mysql'], $config['metrics']);
    }
}
