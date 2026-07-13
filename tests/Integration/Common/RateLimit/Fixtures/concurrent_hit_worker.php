<?php

/**
 * Concurrent-writer fixture for {@see \Phlix\Hub\Common\RateLimit\DbRateLimiter}.
 *
 * Run as an ISOLATED child php process (one per concurrent writer) by
 * {@see \Phlix\Hub\Tests\Integration\Common\RateLimit\DbRateLimiterIntegrationTest::testConcurrentHitsDoNotLoseIncrements}.
 * Each process opens its OWN real {@see Connection} — a distinct MySQL session —
 * and fires `$iterations` {@see DbRateLimiter::hit()} calls at the SAME shared
 * `$key`, so the parent test can prove the atomic `INSERT … ON DUPLICATE KEY
 * UPDATE` upsert loses NO increments under genuine, multi-process row-lock
 * contention (final `attempts` must equal writers × iterations).
 *
 * PHPUnit/PHP is single-threaded per process, so REAL concurrency comes from
 * separate OS processes (each its own DB session) rather than coroutines — this
 * also sidesteps the nested-Swoole-coroutine-in-PHPUnit segfault the sibling
 * {@see \Phlix\Hub\Tests\Unit\Common\Database\Fixtures\pool_harness.php} avoids
 * via the same child-process idiom.
 *
 * A bounded busy-wait barrier (a shared future start-epoch passed as argv, spun
 * on `microtime()` — NO blocking sleep) lines the writers up so their hit loops
 * overlap in time and actually contend on the one InnoDB row.
 *
 * Usage: php concurrent_hit_worker.php <key> <iterations> <window> <max> <startEpoch>
 * Reads DB coords from HUB_TEST_DB_{HOST,PORT,USER,PASSWORD,NAME}. Prints
 * `ok:<iterations>` on success, `err:<message>` on failure.
 */

declare(strict_types=1);

require dirname(__DIR__, 5) . '/vendor/autoload.php';

use Phlix\Hub\Common\RateLimit\DbRateLimiter;
use Workerman\MySQL\Connection;

$key        = (string) ($argv[1] ?? '');
$iterations = (int) ($argv[2] ?? 0);
$window     = (int) ($argv[3] ?? 3600);
$max        = (int) ($argv[4] ?? 1_000_000);
$startEpoch = (float) ($argv[5] ?? 0.0);

if ($key === '' || $iterations < 1) {
    fwrite(STDERR, "err:bad-args\n");
    exit(2);
}

try {
    $db = new Connection(
        (string) getenv('HUB_TEST_DB_HOST'),
        (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
        (string) getenv('HUB_TEST_DB_USER'),
        (string) getenv('HUB_TEST_DB_PASSWORD'),
        (string) getenv('HUB_TEST_DB_NAME'),
    );

    $limiter = new DbRateLimiter($db, $window, $max);

    // Bounded busy-wait barrier so all writers begin contending together.
    // Pure microtime spin (no blocking sleep) with a hard ceiling so a stray
    // clock never wedges the child.
    $ceiling = microtime(true) + 5.0;
    while ($startEpoch > microtime(true) && microtime(true) < $ceiling) {
        // spin
    }

    for ($i = 0; $i < $iterations; $i++) {
        $limiter->hit($key);
    }

    echo 'ok:' . $iterations . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'err:' . $e->getMessage() . "\n");
    exit(1);
}
