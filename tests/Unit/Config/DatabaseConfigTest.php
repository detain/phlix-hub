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

    /**
     * M37 policy legs. The in-process `require` above can never exercise the
     * refusal path (the config's PHPUnit-harness early-return is deliberately
     * always true here), so each leg loads the REAL file in a clean child PHP
     * — same subprocess-evidence discipline the repo uses for gate guards.
     *
     * @param array<string, string> $env Extra environment for the child.
     * @return array{0:int,1:string} [exit code, combined output]
     */
    private function loadConfigInChildProcess(array $env): array
    {
        $assignments = '';
        foreach ($env as $key => $value) {
            $assignments .= escapeshellarg($key . '=' . $value) . ' ';
        }

        $configPath = escapeshellarg(__DIR__ . '/../../../config/database.php');
        $inline = 'is_array(require ' . $configPath . ') ? print "LOADED" : print "NOTARRAY";';

        $cmd = 'env -i ' . $assignments . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($inline) . ' 2>&1';

        exec($cmd, $out, $exit);

        return [$exit, implode("\n", $out)];
    }

    public function testUnsetPasswordRefusesOutsideDevelopment(): void
    {
        [$exit, $output] = $this->loadConfigInChildProcess([]);

        $this->assertNotSame(0, $exit, 'config/database.php must throw when HUB_DB_PASSWORD '
            . 'is unset and no dev context is declared');
        $this->assertStringContainsString('No database password configured', $output);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function admittedContexts(): array
    {
        return [
            'APP_ENV=development' => [['APP_ENV' => 'development']],
            'APP_ENV=dev' => [['APP_ENV' => 'dev']],
            'HUB_ENV=local' => [['HUB_ENV' => 'local']],
            'explicit opt-in flag' => [['PHLIX_HUB_ALLOW_DEFAULT_DB_PASSWORD' => '1']],
            'password supplied' => [['HUB_DB_PASSWORD' => 'a-real-one']],
        ];
    }

    /**
     * @param array<string, string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('admittedContexts')]
    public function testFallbackOrExplicitPasswordIsAdmittedInDeclaredContexts(array $env): void
    {
        [$exit, $output] = $this->loadConfigInChildProcess($env);

        $this->assertSame(0, $exit, "child must load the config for {$output}");
        $this->assertStringContainsString('LOADED', $output);
    }
}
