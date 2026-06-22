<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Database;

use Workerman\MySQL\Connection;

/**
 * Workerman MySQL Connection with the Phlix bug-fixes applied.
 *
 * ## 1. Positional-binding re-key (`bindMore()`)
 *
 * `workerman/mysql` v1.0.9 (the latest tagged release as of writing)
 * has a `bindMore()` implementation that calls `array_keys($parray)`
 * and feeds the raw integer keys (0, 1, 2, …) straight into PDO::bindParam.
 * PHP 8.x's PDO is strict: param-index zero throws
 * "PDOStatement::bindParam(): Argument #1 ($param) must be greater than
 *  or equal to 1".
 *
 * The hub's queries use the natural `$db->query($sql, [$a, $b])` pattern
 * (positional arrays), which exercises that buggy path on every call
 * (e.g. saving hub settings). Rather than re-key every call site to
 * named placeholders or `[1 => $a, 2 => $b]`, we normalise here once.
 *
 * Associative (named) arrays pass through untouched — but their keys must be
 * colon-free (`['id' => $id]`, never `[':id' => $id]`): workerman's `bind()`
 * prepends the `':'` itself, so a leading colon produces the placeholder
 * `'::id'`, which PDO rejects with `SQLSTATE[HY093] Invalid parameter number:
 * parameter was not defined`. Mirrors phlix-server's identical fix.
 *
 * ## 2. utf8mb4 charset default
 *
 * The parent defaults to the legacy 'utf8' alias (= utf8mb3). The schema is
 * utf8mb4 / utf8mb4_unicode_ci, and the parent connects with native prepared
 * statements (`PDO::ATTR_EMULATE_PREPARES = false`) plus a `SET NAMES` init
 * command. On a utf8mb3 connection MySQL 8 tags every bound string parameter
 * utf8mb3_general_ci and then REFUSES to widen it into a utf8mb4_unicode_ci
 * column on INSERT/UPDATE ("SQLSTATE[HY000] 3988: Conversion from collation
 * utf8mb3_general_ci into utf8mb4_unicode_ci impossible for parameter").
 * Connecting as utf8mb4 keeps parameters and columns in the same character
 * set so that conversion never happens.
 *
 * ## 3. Per-connection coroutine mutex (`query()`)
 *
 * Under the Swoole event loop every HTTP request runs in its own coroutine,
 * but the DI container shares ONE Connection instance across all of them.
 * `workerman/mysql` wraps a single PDO socket, and Swoole's runtime hook
 * yields the coroutine while a query waits on that socket — so without a guard
 * a second coroutine can start a query on the same socket mid-flight. That
 * corrupts the shared socket and produces fatals like "Socket#N has already
 * been bound to another coroutine", "2014 Cannot execute queries while other
 * unbuffered queries are active", a `prepare()` that silently returns `false`
 * ("Call to a member function bindParam() on false") and "fetchAll() on null"
 * — exactly what the hub's parallel My-Servers / dashboard widget fetches
 * triggered (→ worker crash → 500). Serialising every query on a per-
 * connection coroutine mutex makes each query atomic w.r.t. other coroutines.
 *
 * ## 4. Emulated + buffered prepared statements (`connect()`)
 *
 * Even with the mutex serialising queries, NATIVE prepares (the parent's
 * default) keep per-statement state on the coroutine-hooked MySQL socket that
 * leaks across coroutine yields, wedging the connection so the next
 * `prepare()` returns `false` / params desync (HY093). {@see connect()} forces
 * emulated + fully-buffered prepares so `prepare()` is client-side only and
 * every result is consumed immediately — no socket state survives a yield.
 * This is the fix that actually eliminated the pairing/login 500s (#3's mutex
 * alone did not).
 *
 * @psalm-suppress PropertyNotSetInConstructor
 *   The parent Connection declares untyped query-builder properties
 *   (e.g. `$table`) it only initialises lazily; our constructor just forwards
 *   to the parent, so Psalm's not-set-in-constructor check is a parent concern.
 */
final class PhlixMySQLConnection extends Connection
{
    /**
     * Binary semaphore that serialises socket access across coroutines.
     *
     * Created lazily on first use because a Swoole\Coroutine\Channel can only
     * exist inside the coroutine runtime (not at construction / CLI time).
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $queryLock = null;

    /** @var int Coroutine id currently holding {@see $queryLock}, or -1 when free. */
    private int $queryLockHolder = -1;

    /**
     * Force emulated + fully-buffered prepared statements.
     *
     * The parent connects with NATIVE prepares (`PDO::ATTR_EMULATE_PREPARES =
     * false`). Under the Swoole event loop the MySQL socket is coroutine-hooked
     * (mysqlnd uses PHP streams), so a query yields the coroutine while it waits
     * on the socket. With native prepares each statement keeps per-statement
     * server-side state on that socket, and that state leaks across coroutine
     * switches — even when queries are otherwise serialised — leaving the
     * connection wedged so the next `prepare()` silently returns `false`
     * ("Call to a member function bindParam() on false") or the bound params
     * desync ("HY093 Invalid parameter number"). This is exactly what 500'd the
     * server↔hub pairing/claim flow and the parallel dashboard fetches.
     *
     * Emulated prepares keep `prepare()` purely client-side (no socket round
     * trip, so it cannot fail at the socket), and buffered queries fetch every
     * result row immediately, so no pending unbuffered result survives a yield.
     * Verified: 150 concurrent claim POSTs with zero connection corruption
     * (was ~5-10% before). Parameterisation stays injection-safe (PDO still
     * escapes every bound value); the connection charset is utf8mb4 (see above).
     *
     * NOTE: emulated prepares send bound params as STRINGS by default, which
     * makes MySQL reject `LIMIT '50'` / `OFFSET '0'` with a 1064 syntax error.
     * {@see execute()} is overridden to bind each value with its natural PDO
     * type (int → PARAM_INT, …) so integer placeholders stay unquoted.
     *
     * @psalm-suppress RedundantConditionGivenDocblockType
     *   Psalm types the parent's `$pdo` as `\PDO` so it deems the instanceof
     *   redundant; PHPStan sees the parent's untyped property as `mixed` and
     *   needs the guard to call `setAttribute()` safely. Keep the guard for
     *   PHPStan; suppress Psalm.
     *
     * @return void
     */
    protected function connect()
    {
        parent::connect();
        if ($this->pdo instanceof \PDO) {
            $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * Prepare + bind + execute with TYPE-AWARE binding.
     *
     * Mirrors the parent's `execute()` (including the one-shot reconnect on
     * MySQL "server has gone away" 2006/2013) but binds each parameter with its
     * natural PDO type via {@see pdoParamType()} instead of the parent's
     * untyped `bindParam()` (which is `PARAM_STR`). Under emulated prepares
     * (see {@see connect()}) a `PARAM_STR` integer is emitted quoted, so
     * `LIMIT ?`/`OFFSET ?` become `LIMIT '50'` and MySQL 1064-errors; binding
     * ints as `PARAM_INT` keeps them unquoted. The parent's `clearSQuery()` is
     * private, so we inline its one line (`$this->sQuery = null`).
     *
     * @param string $query
     * @param mixed  $parameters
     * @return void
     */
    protected function execute($query, $parameters = '')
    {
        try {
            $this->prepareAndBind($query, $parameters);
            $this->success = $this->sQuery instanceof \PDOStatement && $this->sQuery->execute();
        } catch (\PDOException $e) {
            $errno = (is_array($e->errorInfo) && isset($e->errorInfo[1])) ? (int) $e->errorInfo[1] : 0;
            if ($errno === 2006 || $errno === 2013) {
                // "MySQL server has gone away" — drop the dead socket and retry once.
                $this->closeConnection();
                try {
                    $this->prepareAndBind($query, $parameters);
                    $this->success = $this->sQuery instanceof \PDOStatement && $this->sQuery->execute();
                } catch (\PDOException $ex) {
                    $this->rollBackTrans();
                    throw $ex;
                }
            } else {
                $this->rollBackTrans();
                throw new \PDOException('SQL:' . $this->lastSQL() . ' ' . $e->getMessage(), (int) $e->getCode());
            }
        }
        $this->parameters = [];
    }

    /**
     * Prepare $query and bind the accumulated parameters with their natural PDO
     * type. Reconnects if the PDO handle is missing. Replaces the parent's
     * private clearSQuery() with an inline `$this->sQuery = null`.
     *
     * @param mixed $parameters
     */
    private function prepareAndBind(string $query, mixed $parameters): void
    {
        if (!$this->pdo instanceof \PDO) {
            $this->connect();
        }
        if (!$this->pdo instanceof \PDO) {
            throw new \PDOException('PDO connection is not available.');
        }
        $this->sQuery = null;
        $statement = $this->pdo->prepare($query);
        if (!$statement instanceof \PDOStatement) {
            throw new \PDOException('Failed to prepare SQL statement.');
        }
        $this->sQuery = $statement;
        $this->bindMore($parameters);
        /** @var mixed $param */
        foreach ($this->parameters as $param) {
            if (!is_array($param)) {
                continue;
            }
            $placeholder = $param[0] ?? null;
            if (!is_int($placeholder) && !is_string($placeholder)) {
                continue;
            }
            /** @var mixed $value */
            $value = $param[1] ?? null;
            $statement->bindValue($placeholder, $value, $this->pdoParamType($value));
        }
    }

    /**
     * Map a PHP value to the PDO bind type that keeps it correctly typed under
     * emulated prepares (integers stay unquoted so `LIMIT ?`/`OFFSET ?` work).
     *
     * @param mixed $value
     */
    private function pdoParamType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            $value === null => \PDO::PARAM_NULL,
            default         => \PDO::PARAM_STR,
        };
    }

    /**
     * Default the connection charset to utf8mb4 (the parent defaults to the
     * legacy 'utf8' alias = utf8mb3). Callers may still override. See the
     * class docblock (#2) for why this matters.
     *
     * @param string $host
     * @param int    $port
     * @param string $user
     * @param string $password
     * @param string $db_name
     * @param string $charset
     */
    public function __construct($host, $port, $user, $password, $db_name, $charset = 'utf8mb4')
    {
        parent::__construct($host, $port, $user, $password, $db_name, $charset);
    }

    /**
     * Run a query under the per-connection coroutine mutex so the shared
     * socket is never used by two coroutines at once. `query()` performs the
     * full prepare→execute→fetch internally, so holding the lock across it
     * makes each query atomic with respect to every other coroutine.
     *
     * Outside a coroutine (CLI migrations, cron) there is no concurrency, so
     * we run directly. The lock is reentrant per coroutine, so a query issued
     * while this coroutine already holds it (nested call) cannot deadlock.
     *
     * @param string                        $query
     * @param array<int|string, mixed>|null $params
     * @param int                           $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $cid = $this->currentCoroutineId();
        if ($cid < 0) {
            return parent::query($query, $params, $fetchmode);
        }

        $acquired = $this->acquireQueryLock($cid);
        try {
            return parent::query($query, $params, $fetchmode);
        } finally {
            if ($acquired) {
                $this->releaseQueryLock();
            }
        }
    }

    /**
     * Current Swoole coroutine id, or -1 when not running inside a coroutine
     * (e.g. CLI scripts) or when the extension is absent.
     *
     * @psalm-suppress RedundantCondition,TypeDoesNotContainType
     *   ext-swoole's reflected `getCid()` return type is `int`, so Psalm (which
     *   loads swoole in CI) treats the `is_int()` guard as redundant — but
     *   PHPStan runs WITHOUT swoole, sees the call as `mixed`, and REQUIRES the
     *   guard to narrow the `int` return. The guard stays for PHPStan; we
     *   suppress Psalm's redundancy complaint rather than drop a guard that a
     *   sibling tool needs.
     */
    private function currentCoroutineId(): int
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return -1;
        }
        $cid = \Swoole\Coroutine::getCid();
        return is_int($cid) ? $cid : -1;
    }

    /**
     * Acquire the query mutex for the given coroutine. Returns true when this
     * call took the lock (caller must release), false when the coroutine
     * already held it (reentrant — caller must NOT release).
     */
    private function acquireQueryLock(int $cid): bool
    {
        if ($this->queryLockHolder === $cid) {
            return false;
        }
        if ($this->queryLock === null) {
            $this->queryLock = new \Swoole\Coroutine\Channel(1);
            $this->queryLock->push(true);
        }
        // Blocks (yields the coroutine) until the single token is available.
        $this->queryLock->pop();
        $this->queryLockHolder = $cid;
        return true;
    }

    /** Release the query mutex, waking the next waiting coroutine. */
    private function releaseQueryLock(): void
    {
        $this->queryLockHolder = -1;
        if ($this->queryLock !== null) {
            $this->queryLock->push(true);
        }
    }

    /**
     * Signature matches the parent's declared docblock (`@param array`).
     * Workerman's execute() defaults `$parameters = ""` and forwards the
     * string straight into bindMore(); the parent then no-ops on
     * non-array input. We mirror that escape hatch here so the
     * `is_array()` guard below stays meaningful (hence `mixed`, not
     * `array`, which both PHPStan and Psalm accept).
     *
     * @param mixed $parray
     */
    public function bindMore($parray): void
    {
        if (!is_array($parray)) {
            // Defensive: keep the no-op behaviour the parent has when
            // execute() forwards its empty-string default.
            return;
        }
        if ($parray !== [] && array_is_list($parray)) {
            // re-key [0=>'a', 1=>'b'] → [1=>'a', 2=>'b']
            $parray = array_combine(range(1, count($parray)), $parray);
        }
        parent::bindMore($parray);
    }
}
