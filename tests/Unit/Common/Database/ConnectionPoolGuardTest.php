<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Minor 37 — the uninitialised pool must fail fast with ONE descriptive
 * exception, not the old warning-level mess (`include ''` died as
 * `ValueError: Path cannot be empty`, a message that names a language
 * construct rather than the missing bootstrap step).
 *
 * The happy path (init() first, then resolution) is exercised by every suite
 * using ConnectionPoolTestControl; the legs here pin the guard's CONTRACT —
 * a miss without init throws with its own exception naming the fix, while an
 * already-memoised name keeps short-circuiting (the priming seam the suites
 * legitimately use). The static state is
 * snapshotted and restored (random executionOrder: a leaked mutation would
 * hand later suites a wrong pool — same contract as the control trait).
 *
 * @package Phlix\Hub\Tests\Unit\Common\Database
 */
final class ConnectionPoolGuardTest extends TestCase
{
    public function testGetConnectionBeforeInitThrowsDescriptiveRuntimeException(): void
    {
        $this->withUninitialisedPool(static function (): void {
            try {
                ConnectionPool::getConnection('mysql');
                self::fail('uninitialised pool must not resolve a connection');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('before', $e->getMessage());
                self::assertStringContainsString('ConnectionPool::init(', $e->getMessage());
            }
        });
    }

    public function testMemoisedNameShortCircuitsWithoutConfigPath(): void
    {
        // The guard sits inside the MISS branch: a primed memo (the seam
        // ConnectionPoolTestControl documents) still resolves without ever
        // consulting the path. The vendored Connection DIALS in its
        // constructor, so the sentinel is the repo's own in-memory double.
        $sentinel = new InMemoryHubSettingsConnection();
        self::assertSame($sentinel, $this->withUninitialisedPool(function () use ($sentinel) {
            self::poolConnections()->setValue(null, ['mysql' => $sentinel]);
            return ConnectionPool::getConnection('mysql');
        }), 'memoised connection must be returned despite the empty path');
    }

    private static function poolConnections(): ReflectionProperty
    {
        $property = (new \ReflectionClass(ConnectionPool::class))->getProperty('connections');
        $property->setAccessible(true);
        return $property;
    }

    /**
     * Run $body with the pool's statics forced to the never-init'd state,
     * restoring every one of them afterwards (also on failure — PHPUnit runs
     * the restore from the finally block before the exception propagates).
     *
     * @template T
     * @param callable(): T $body
     * @return T
     */
    private function withUninitialisedPool(callable $body): mixed
    {
        $configPath = new ReflectionProperty(ConnectionPool::class, 'configPath');
        $instance = new ReflectionProperty(ConnectionPool::class, 'instance');
        $connections = new ReflectionProperty(ConnectionPool::class, 'connections');

        /** @var string $savedConfig */
        $savedConfig = $configPath->getValue();
        /** @var ConnectionPool|null $savedInstance */
        $savedInstance = $instance->getValue();
        /** @var array<string, mixed> $savedConnections */
        $savedConnections = $connections->getValue();

        $configPath->setValue(null, '');
        $instance->setValue(null, null);
        $connections->setValue(null, []);

        try {
            return $body();
        } finally {
            $configPath->setValue(null, $savedConfig);
            $instance->setValue(null, $savedInstance);
            $connections->setValue(null, $savedConnections);
        }
    }
}
