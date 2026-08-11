<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\OAuth;

use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Tests\Support\ConnectionPoolTestControl;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Timer;

use function bin2hex;
use function count;
use function glob;
use function hash;
use function is_array;
use function is_callable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function unlink;

/**
 * S286 — the OAuth prune actually FIRES, and it fires again after a restart.
 *
 * ## The landmine this is written against
 *
 * S92 left `pruneExpired()` on all three OAuth stores with nothing calling it,
 * so four tables grew forever. The obvious fix — `Timer::add(86400, …)` at boot
 * — is the exact shape recorded in
 * `project_backup_timer_needs_boot_catchup_2026_07_21`: a daily timer armed at
 * worker start fires its FIRST tick a day later, so on a hub restarted (deploy,
 * update, reboot) more often than once a day it fires **never**, and the tables
 * grow exactly as if nobody had written the timer. A test that asserted "a timer
 * was scheduled" would pass against that.
 *
 * So this suite proves three separate things, and the third is the one the
 * acceptance criterion asks for:
 *
 *  1. the callback the timer holds really deletes the rows (executed by
 *     Workerman's own `Timer::tick()`, not by the test calling the reaper);
 *  2. arming alone deletes nothing — so (1) is attributable to the FIRING;
 *  3. after the process's timer state is reset to what a freshly started worker
 *     has, the sweep arms and fires **again**, with fresh garbage, at a delay
 *     bounded well below a day.
 *
 * ## How a restart is simulated
 *
 * {@see WorkermanTimerRuntimeControl::forceWorkermanRuntime()} resets
 * `Timer::$event`, `Timer::$tasks`, `Timer::$status` and `Worker::$workers` to a
 * known, empty-task state — which is precisely the timer state a newly forked
 * worker begins with — and the trait restores the process's real values after
 * every test. Firing is then done by reflection-invoking Workerman's own
 * `Timer::tick()`, after moving the armed task's run-time key to "now". The
 * callback under test is therefore dispatched by the production scheduler,
 * reading the production task table, rather than being called by name.
 *
 * ⚠ PHPUnit never enters a Swoole coroutine, and `Timer::$event` is null here,
 * so what this proves is the pcntl arm of `Timer::add()` plus the callback's
 * effect on real MySQL. It is NOT evidence about coroutine scheduling. That
 * limitation is exactly why the OAuth pruners were attached to the reaper's
 * already-armed 60-second sweep instead of getting a timer of their own: there
 * is no new scheduling behaviour to be wrong about.
 *
 * @package Phlix\Hub\Tests\Integration\OAuth
 *
 * @group integration
 */
final class OAuthPruneTimerTest extends RealDatabaseTestCase
{
    use WorkermanTimerRuntimeControl;
    use ConnectionPoolTestControl;

    /**
     * The ceiling the boot-catch-up argument rests on.
     *
     * A literal, not a value read from the class under test: a bound derived
     * from its own subject self-adjusts and could never fail. Five minutes is
     * far below any plausible restart cadence and 288× below the 86400 that has
     * already failed in this estate once.
     */
    private const int MAX_ACCEPTABLE_FIRST_TICK_SECONDS = 300;

    private string $tmpDir = '';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        // IdleReaper's graph reaches HeartbeatHandler, whose factory calls
        // ConnectionPool::getConnection('txn') directly — a container definition
        // cannot intercept that, so the process-global pool has to point at the
        // test schema. The trait puts it back after this test.
        $this->pointConnectionPoolAtTestDatabase();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-s286t-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        file_put_contents(
            $this->tmpDir . '/auth.php',
            "<?php\n\nreturn ['secret' => '" . str_repeat('s286-prune-timer-', 4) . "'];\n",
        );

        $this->container = ContainerFactory::create(
            [
                'auth_config_path'   => $this->tmpDir . '/auth.php',
                'logger_config_path' => $this->tmpDir . '/logger.php',
                'public_root'        => dirname(__DIR__, 3) . '/public',
            ],
            [...ContainerFactory::defaultProviders(), new FixedConnectionProvider($this->db)],
        );
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

    // =====================================================================
    // 1. The acceptance criterion: it fires after a (simulated) restart
    // =====================================================================

    /**
     * 🔴 Two full worker lifetimes in one test.
     *
     * Each "boot" resets the process's Workerman timer state to what a freshly
     * forked worker has, arms the DB-maintenance sweep through the PRODUCTION
     * `startDbMaintenance()`, and then lets Workerman's own `Timer::tick()`
     * dispatch it. Garbage planted before each boot must be gone after that
     * boot's tick.
     *
     * The second lifetime is the point. A once-per-process latch, a static
     * "already armed" guard, or a timer whose first tick is a day out would all
     * satisfy the first lifetime and fail the second.
     */
    public function testThePruneSweepArmsAndFiresAgainAfterASimulatedRestart(): void
    {
        $reaper = $this->containerReaper();

        $survivors = $this->plantRowsThatMustSurvive();

        foreach (['first boot', 'second boot (after a simulated restart)'] as $boot) {
            // A brand-new worker: no armed tasks, no timer ids, a live registry.
            $this->forceWorkermanRuntime();
            self::assertSame(0, $this->pendingTimerTaskCount(), $boot . ': the timer table must start empty');

            $garbage = $this->plantPrunableRows();
            self::assertSame(
                [1, 1, 1],
                $this->rowCounts($garbage),
                $boot . ': the garbage rows were not planted',
            );

            $timerId = $reaper->startDbMaintenance();
            self::assertGreaterThan(0, $timerId, $boot . ': startDbMaintenance() returned no timer id');
            self::assertSame(
                1,
                $this->pendingTimerTaskCount(),
                $boot . ': exactly one maintenance task must be armed',
            );

            // ARMED IS NOT FIRED. Without this the deletions below could have
            // come from startDbMaintenance() sweeping inline, which is a
            // different (and wrong) design that a "the rows are gone" assertion
            // alone cannot rule out.
            self::assertSame(
                [1, 1, 1],
                $this->rowCounts($garbage),
                $boot . ': arming the timer must not itself prune — that would mean the sweep '
                . 'runs at arm time rather than on the schedule',
            );

            $delay = $this->firstTickDelaySeconds();
            self::assertLessThanOrEqual(
                self::MAX_ACCEPTABLE_FIRST_TICK_SECONDS,
                $delay,
                $boot . ': the first sweep is ' . $delay . 's after boot. A hub that restarts more '
                . 'often than that never prunes at all — the S286 landmine verbatim.',
            );

            $this->fireDueTimersNow();

            self::assertSame(
                [0, 0, 0],
                $this->rowCounts($garbage),
                $boot . ': the armed timer fired but the OAuth rows were not pruned',
            );
        }

        // Nothing that was still live got swept in either lifetime.
        self::assertSame(
            [1, 1, 1],
            $this->rowCounts($survivors),
            'the sweep deleted rows that are still in use — a pruner that empties the table is not '
            . 'a pruner, and every "it pruned" assertion above would pass for it',
        );
    }

    /**
     * The boot-catch-up property, stated as a fact about the shipped constant
     * rather than as a property of whatever instance a test happened to build.
     *
     * This is the assertion that reds if somebody "optimises" the maintenance
     * sweep to a daily cadence.
     */
    public function testTheMaintenanceIntervalIsShortEnoughToSurviveFrequentRestarts(): void
    {
        self::assertLessThanOrEqual(
            self::MAX_ACCEPTABLE_FIRST_TICK_SECONDS,
            IdleReaper::DEFAULT_INTERVAL_SECONDS,
            'IdleReaper::DEFAULT_INTERVAL_SECONDS is the OAuth prune cadence. It has to stay well '
            . 'under a restart interval: a bare Timer::add(86400, …) never fires on a box that '
            . 'restarts more than once a day, which is why the OAuth pruners live on THIS sweep '
            . 'rather than on a daily timer of their own.',
        );

        // The container-built reaper must actually use it, not some other value.
        self::assertLessThanOrEqual(
            self::MAX_ACCEPTABLE_FIRST_TICK_SECONDS,
            $this->containerReaper()->getIntervalSeconds(),
        );
    }

    // =====================================================================
    // 2. The wiring — the reaper resolved from the CONTAINER really holds them
    // =====================================================================

    /**
     * ⚠ Container-resolved, and this is the whole reason this test exists.
     *
     * `IdleReaper`'s OAuth dependencies are NULLABLE, and `reapDbMaintenance()`
     * null-safe-chains past a null one in complete silence. PHP-DI does not
     * autowire into an explicit `factory()` closure, so omitting a
     * `->parameter()` line in `HubServicesProvider` would leave the pruner
     * permanently inert while every hand-constructed test of the reaper stayed
     * green — the S269 defect, exactly.
     *
     * Asserted by EFFECT rather than by reflecting on the properties: a
     * non-null property could still be a different service on a different
     * connection. The container reaper is made to delete rows this test planted
     * in the test's own database, which nothing but correct wiring can do.
     */
    public function testTheContainerBuiltReaperPrunesAllThreeOAuthStores(): void
    {
        $reaper  = $this->containerReaper();
        $garbage = $this->plantPrunableRows();

        self::assertSame([1, 1, 1], $this->rowCounts($garbage));

        $reaper->reapDbMaintenance();

        self::assertSame(
            [0, 0, 0],
            $this->rowCounts($garbage),
            'the CONTAINER-resolved IdleReaper did not prune the OAuth tables. Check the '
            . '->parameter(\'oauth*\') lines in HubServicesProvider: a missing one resolves to '
            . 'null and reapDbMaintenance() skips it silently.',
        );
    }

    /**
     * Retention is per-store, not one blanket rule — and the boundary is
     * asserted on the SAME side of each predicate that production uses.
     *
     * A token that expired an hour ago must SURVIVE (the day-long grace exists
     * so an operator debugging a failure can still see the row), while one that
     * expired two days ago must go. A pruner that deleted everything expired
     * would pass every "it pruned" assertion in this file and would quietly
     * destroy the diagnostic window.
     */
    public function testRecentlyExpiredTokensAreKeptForTheGraceWindow(): void
    {
        $recent = Ids::uuidV4();
        $this->insertToken($recent, 'access', 'NOW() - INTERVAL 1 HOUR', null);

        $ancient = Ids::uuidV4();
        $this->insertToken($ancient, 'access', 'NOW() - INTERVAL 2 DAY', null);

        $this->containerReaper()->reapDbMaintenance();

        self::assertSame(1, $this->countRow('oauth_tokens', $recent), 'the 1-day grace window was lost');
        self::assertSame(0, $this->countRow('oauth_tokens', $ancient), 'a 2-day-expired token was kept');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function containerReaper(): IdleReaper
    {
        $reaper = $this->container->get(IdleReaper::class);
        self::assertInstanceOf(IdleReaper::class, $reaper);

        return $reaper;
    }

    /**
     * Seconds between now and the earliest armed task's scheduled run time.
     *
     * Read out of `Timer::$tasks`, whose keys ARE the absolute run times
     * (`Timer.php`: `$runTime = (int) floor(time() + $timeInterval)`), so this
     * measures what Workerman will really do rather than what was requested.
     */
    private function firstTickDelaySeconds(): int
    {
        /** @var array<array-key, mixed> $tasks */
        $tasks = (new ReflectionProperty(Timer::class, 'tasks'))->getValue();
        self::assertNotSame([], $tasks, 'no task is armed');

        $runTimes = [];
        foreach ($tasks as $runTime => $bucket) {
            if (is_array($bucket) && $bucket !== []) {
                $runTimes[] = (int) $runTime;
            }
        }
        self::assertNotSame([], $runTimes);
        sort($runTimes);

        return $runTimes[0] - time();
    }

    /**
     * Make every armed task due, then let Workerman's OWN dispatcher run it.
     *
     * Re-keying `Timer::$tasks` to "now" is the only way to reach the callback
     * through `Timer::tick()` without sleeping for the interval — and reaching
     * it through `tick()` rather than by calling the reaper is the point: it
     * proves the callback that was actually stored in the task table is the one
     * that prunes.
     */
    private function fireDueTimersNow(): void
    {
        $property = new ReflectionProperty(Timer::class, 'tasks');
        /** @var array<array-key, mixed> $tasks */
        $tasks = $property->getValue();

        $now      = time();
        $rekeyed  = [];
        $callables = 0;
        foreach ($tasks as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $timerId => $task) {
                self::assertIsArray($task);
                self::assertTrue(is_callable($task[0]), 'an armed task holds no callable');
                $callables++;
                $rekeyed[$now][$timerId] = $task;
            }
        }

        self::assertGreaterThan(0, $callables, 'there was nothing to fire');
        $property->setValue(null, $rekeyed);

        $tick = new ReflectionMethod(Timer::class, 'tick');
        $tick->setAccessible(true);
        $tick->invoke(null);
    }

    /**
     * One prunable row in each of the three OAuth stores.
     *
     * Every row is prunable for a DIFFERENT reason, so a predicate that lost one
     * of its arms is visible: the token is revoked (not expired), the code is
     * long expired, the consent request is consumed (not expired).
     *
     * @return array{token: string, code: string, consent: string}
     */
    private function plantPrunableRows(): array
    {
        $tokenId = Ids::uuidV4();
        $this->insertToken($tokenId, 'access', 'NOW() + INTERVAL 1 HOUR', 'NOW() - INTERVAL 1 MINUTE');

        $codeId = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO oauth_authorization_codes'
            . ' (id, code_hash, client_id, user_id, redirect_uri, scopes, code_challenge, expires_at)'
            . ' VALUES (:id, :hash, :client, :user, :uri, :scopes, :challenge,'
            . ' NOW() - INTERVAL 3 DAY)',
            [
                'id'        => $codeId,
                'hash'      => hash('sha256', $codeId),
                'client'    => 's286-client',
                'user'      => Ids::uuidV4(),
                'uri'       => 'https://example.test/cb',
                'scopes'    => 'phlix:profile:read',
                'challenge' => hash('sha256', 'challenge'),
            ],
        );

        $consentId = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO oauth_consent_requests'
            . ' (id, ticket_hash, user_id, client_id, redirect_uri, scopes, code_challenge,'
            . ' expires_at, consumed_at)'
            . ' VALUES (:id, :hash, :user, :client, :uri, :scopes, :challenge,'
            . ' NOW() + INTERVAL 5 MINUTE, NOW() - INTERVAL 1 MINUTE)',
            [
                'id'        => $consentId,
                'hash'      => hash('sha256', $consentId),
                'user'      => Ids::uuidV4(),
                'client'    => 's286-client',
                'uri'       => 'https://example.test/cb',
                'scopes'    => 'phlix:profile:read',
                'challenge' => hash('sha256', 'challenge'),
            ],
        );

        return ['token' => $tokenId, 'code' => $codeId, 'consent' => $consentId];
    }

    /**
     * One row in each store that a correct pruner must LEAVE ALONE.
     *
     * Without these, "the tables are empty afterwards" would be satisfied by a
     * `DELETE FROM` with no `WHERE` clause at all.
     *
     * @return array{token: string, code: string, consent: string}
     */
    private function plantRowsThatMustSurvive(): array
    {
        $tokenId = Ids::uuidV4();
        $this->insertToken($tokenId, 'refresh', 'NOW() + INTERVAL 30 DAY', null);

        $codeId = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO oauth_authorization_codes'
            . ' (id, code_hash, client_id, user_id, redirect_uri, scopes, code_challenge, expires_at)'
            . ' VALUES (:id, :hash, :client, :user, :uri, :scopes, :challenge,'
            . ' NOW() + INTERVAL 60 SECOND)',
            [
                'id'        => $codeId,
                'hash'      => hash('sha256', $codeId),
                'client'    => 's286-client',
                'user'      => Ids::uuidV4(),
                'uri'       => 'https://example.test/cb',
                'scopes'    => 'phlix:profile:read',
                'challenge' => hash('sha256', 'challenge'),
            ],
        );

        $consentId = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO oauth_consent_requests'
            . ' (id, ticket_hash, user_id, client_id, redirect_uri, scopes, code_challenge, expires_at)'
            . ' VALUES (:id, :hash, :user, :client, :uri, :scopes, :challenge,'
            . ' NOW() + INTERVAL 10 MINUTE)',
            [
                'id'        => $consentId,
                'hash'      => hash('sha256', $consentId),
                'user'      => Ids::uuidV4(),
                'client'    => 's286-client',
                'uri'       => 'https://example.test/cb',
                'scopes'    => 'phlix:profile:read',
                'challenge' => hash('sha256', 'challenge'),
            ],
        );

        return ['token' => $tokenId, 'code' => $codeId, 'consent' => $consentId];
    }

    /**
     * `expires_at` / `revoked_at` are pasted as SQL expressions rather than
     * bound, because binding a PHP-computed timestamp would make the test's
     * clock — not MySQL's — decide which side of the predicate a row falls on.
     * Both values are literals written in this file, never input.
     */
    private function insertToken(string $id, string $kind, string $expiresExpr, ?string $revokedExpr): void
    {
        $this->db->query(
            'INSERT INTO oauth_tokens (id, token_hash, kind, client_id, user_id, scopes, expires_at, revoked_at)'
            . ' VALUES (:id, :hash, :kind, :client, :user, :scopes, ' . $expiresExpr . ', '
            . ($revokedExpr ?? 'NULL') . ')',
            [
                'id'     => $id,
                'hash'   => hash('sha256', $id),
                'kind'   => $kind,
                'client' => 's286-client',
                'user'   => Ids::uuidV4(),
                'scopes' => 'phlix:profile:read',
            ],
        );
    }

    /**
     * @param array{token: string, code: string, consent: string} $ids
     *
     * @return list<int> [tokens, codes, consent requests]
     */
    private function rowCounts(array $ids): array
    {
        return [
            $this->countRow('oauth_tokens', $ids['token']),
            $this->countRow('oauth_authorization_codes', $ids['code']),
            $this->countRow('oauth_consent_requests', $ids['consent']),
        ];
    }

    /**
     * @param string $table Table name — a literal from this file, never input.
     */
    private function countRow(string $table, string $id): int
    {
        /** @var mixed $rows */
        $rows = $this->db->query("SELECT COUNT(*) AS n FROM `{$table}` WHERE id = :id", ['id' => $id]);
        self::assertIsArray($rows);
        self::assertIsArray($rows[0] ?? null);

        return (int) ($rows[0]['n'] ?? -1);
    }
}
