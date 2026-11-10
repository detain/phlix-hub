<?php

/**
 * Phlix hub component: Tests.
 *
 * Minor 38 — pins the pure boot-notice law armed by Application's HTTP
 * worker setup: a multi-worker HTTP pool must announce the per-worker
 * dilution of the in-memory rate-limit profiles; a single-worker pool
 * must not (the local counter IS the global one there).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\RateLimit;

use Phlix\Hub\Common\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterMultiWorkerNoticeTest extends TestCase
{
    public function testSingleWorkerPoolStaysQuiet(): void
    {
        self::assertNull(RateLimiter::multiWorkerNotice(1));
        self::assertNull(RateLimiter::multiWorkerNotice(0));
        self::assertNull(RateLimiter::multiWorkerNotice(-5));
    }

    public function testMultiWorkerPoolAnnouncesTheDilutionWithItsFactor(): void
    {
        $notice = RateLimiter::multiWorkerNotice(4);

        self::assertIsString($notice);
        self::assertStringContainsString('4 HTTP workers', (string) $notice);
        self::assertStringContainsString('max x 4', (string) $notice, 'the notice must state the concrete factor');
        self::assertStringContainsString('PER WORKER', (string) $notice);
        self::assertStringContainsString('login/signup', (string) $notice, 'the DB-backed exception must be named');
    }
}
