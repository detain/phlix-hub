<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\Database\ConnectionPool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use ReflectionProperty;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

/**
 * Point the process-global {@see ConnectionPool} at the `HUB_TEST_DB_*` schema
 * for the duration of ONE test, and put it back afterwards.
 *
 * ## Why this is needed at all
 *
 * {@see \Phlix\Hub\Tests\Support\Container\FixedConnectionProvider} substitutes
 * the container's `Workerman\MySQL\Connection` entry, which is enough for every
 * binding that takes the connection as a dependency. Several do not: the
 * server-facing handlers that run explicit transactions call
 * `ConnectionPool::getConnection('txn')` INSIDE their factory closure, on
 * purpose (a reaper on the shared `mysql` socket trips 2014 mid-transaction —
 * see `config/database.php`). A container definition cannot intercept that, so a
 * test resolving anything that reaches `HeartbeatHandler` — {@see
 * \Phlix\Hub\Relay\IdleReaper} does — dies with `ValueError: Path cannot be
 * empty` from an uninitialised pool.
 *
 * ## Why the state is snapshotted rather than merely set
 *
 * 🔴 `ConnectionPool::init()` writes a private static config path and the pool
 * MEMOISES every named connection it opens, with no reset method. With
 * `executionOrder="random"` in `phpunit.xml`, calling `init()` and walking away
 * would hand every later suite in the same process a pool pointing at the test
 * schema — and, worse, memoised sockets to it. That is a new order dependency,
 * i.e. exactly the class of defect
 * {@see \Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl} exists to remove,
 * so this trait follows the same contract: snapshot in `#[Before]`, restore in
 * `#[After]`, which PHPUnit runs even when the test fails or errors.
 *
 * The generated config sets `pool_enabled => false` deliberately. The pooled
 * implementation is a Swoole coroutine pool, and PHPUnit never enters a
 * coroutine; the single mutex-serialised socket is the arm that a non-coroutine
 * process actually runs, so it is the one to exercise here.
 *
 * @package Phlix\Hub\Tests\Support
 */
trait ConnectionPoolTestControl
{
    /** @var array<string, mixed> Snapshot of `ConnectionPool::$connections`. */
    private array $poolConnectionsSnapshot = [];

    /** Snapshot of `ConnectionPool::$configPath`. */
    private string $poolConfigPathSnapshot = '';

    /** Snapshot of `ConnectionPool::$instance`. */
    private ?ConnectionPool $poolInstanceSnapshot = null;

    /** Temp config file written by {@see pointConnectionPoolAtTestDatabase()}. */
    private string $poolConfigFile = '';

    private bool $poolStateCaptured = false;

    #[Before]
    protected function captureConnectionPoolState(): void
    {
        /** @var array<string, mixed> $connections */
        $connections                   = (new ReflectionProperty(ConnectionPool::class, 'connections'))->getValue();
        $this->poolConnectionsSnapshot = $connections;

        /** @var string $path */
        $path                         = (new ReflectionProperty(ConnectionPool::class, 'configPath'))->getValue();
        $this->poolConfigPathSnapshot = $path;

        /** @var ConnectionPool|null $instance */
        $instance                   = (new ReflectionProperty(ConnectionPool::class, 'instance'))->getValue();
        $this->poolInstanceSnapshot = $instance;

        $this->poolStateCaptured = true;
    }

    #[After]
    protected function restoreConnectionPoolState(): void
    {
        if (!$this->poolStateCaptured) {
            return;
        }

        (new ReflectionProperty(ConnectionPool::class, 'connections'))
            ->setValue(null, $this->poolConnectionsSnapshot);
        (new ReflectionProperty(ConnectionPool::class, 'configPath'))
            ->setValue(null, $this->poolConfigPathSnapshot);
        (new ReflectionProperty(ConnectionPool::class, 'instance'))
            ->setValue(null, $this->poolInstanceSnapshot);

        if ($this->poolConfigFile !== '') {
            @unlink($this->poolConfigFile);
            $this->poolConfigFile = '';
        }

        $this->poolStateCaptured = false;
    }

    /**
     * Write a throwaway `database.php` describing the `HUB_TEST_DB_*` schema and
     * initialise the pool from it, dropping any memoised connection first so the
     * next `getConnection()` really opens against the test database.
     */
    protected function pointConnectionPoolAtTestDatabase(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-hub-pool-' . bin2hex(random_bytes(6));
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $connection = [
            'host'         => (string) (getenv('HUB_TEST_DB_HOST') ?: '127.0.0.1'),
            'port'         => (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
            'user'         => (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            'password'     => (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
            'database'     => (string) getenv('HUB_TEST_DB_NAME'),
            'pool_enabled' => false,
            'pool_size'    => 1,
        ];

        $this->poolConfigFile = $dir . '/database.php';
        file_put_contents(
            $this->poolConfigFile,
            "<?php\n\n\$c = " . var_export($connection, true)
            . ";\n\nreturn ['mysql' => \$c, 'metrics' => \$c, 'txn' => \$c];\n",
        );

        (new ReflectionProperty(ConnectionPool::class, 'connections'))->setValue(null, []);
        ConnectionPool::init($this->poolConfigFile);
    }
}
