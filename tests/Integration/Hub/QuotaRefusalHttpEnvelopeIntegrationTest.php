<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use DateTimeImmutable;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * M-HUB-16 real-MySQL round-trip: a bandwidth-quota denial stored in the REAL
 * `relay_user_quotas` table must surface at the relay-proxy HTTP boundary as
 * the hub's CANONICAL flat `{error, code, message, retry_at}` envelope at 429
 * with a `Retry-After` header naming the same instant the period roll actually
 * happens for THAT stored period — not prose a TV cannot parse on a nested
 * shape no reader follows (the review's "least machine-readable can't-play").
 *
 * The monthly period key is a DATE column; the recovery instant is the first
 * second of the NEXT period in the host clock the row is rolled by, so the
 * header/retry_at values are checked against the real row's calendar anchor,
 * end to end: seeded row → real {@see RelaySessionManager::checkUserQuota()} →
 * real {@see ServerProxyController::proxy()} → Response status/headers/body.
 *
 * Skipped automatically when `HUB_TEST_DB_*` is unset (CI S173 runs it against
 * the service DB; locally against a dedicated scratch schema).
 *
 * @package Phlix\Hub\Tests\Integration\Hub
 *
 * @group integration
 */
final class QuotaRefusalHttpEnvelopeIntegrationTest extends RealDatabaseTestCase
{
    private const USER_ID = '11111111-2222-3333-4444-555555555555';

    /**
     * Seed a quota row with the DOWNLOAD cap reached for the CURRENT monthly
     * period (the dimension a media stream grows). Returns the period anchor.
     */
    private function seedDownloadCapReached(): string
    {
        $periodStart = date('Y-m-01');
        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, bytes_in, quota_bytes_in)'
            . ' VALUES (:uid, :ps, :bi, :qi)',
            [
                'uid' => self::USER_ID,
                'ps' => $periodStart,
                'bi' => 5242880,
                'qi' => 5242880,
            ],
        );

        return $periodStart;
    }

    public function testStoredQuotaDenialDerivesTheRealPeriodRollInstant(): void
    {
        $periodStart = $this->seedDownloadCapReached();

        $manager = new RelaySessionManager($this->db, $this->createMock(StructuredLogger::class));
        $verdict = $manager->checkUserQuota(self::USER_ID);

        self::assertFalse($verdict['allowed'], 'the seeded over-cap row must deny');
        self::assertStringContainsString('download', (string) $verdict['reason']);

        // The recovery instant is the first second of the NEXT calendar period
        // in the same clock the DATE anchor is rolled by — derived from the
        // real stored period, computed here independently of the production
        // helper (DateTimeImmutable vs strtotime).
        $expected = (new DateTimeImmutable($periodStart . ' +1 month 00:00:00'))->getTimestamp();
        self::assertSame($expected, $verdict['retryAt']);
        self::assertGreaterThan(time(), $verdict['retryAt'], 'the roll must be in the future');
    }

    public function testRealDenialSurfacesAsCanonicalFlatRefusalWithRecoveryTime(): void
    {
        $periodStart = $this->seedDownloadCapReached();

        $manager = new RelaySessionManager($this->db, $this->createMock(StructuredLogger::class));

        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => self::USER_ID,
            'status' => 'online',
            'relayActive' => true,
        ]);

        $forwarded = false;
        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data) use (&$forwarded): void {
                $forwarded = true;
            },
        );

        $controller = new ServerProxyController(
            $info,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $manager,
            new RateLimiter(60, 600),
        );

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/servers/srv-1/proxy/api/v1/libraries';
        $request->userId = self::USER_ID;
        $request->headers = ['Accept' => 'application/json'];

        $response = $controller->proxy($request, ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        self::assertFalse($forwarded, 'the over-quota request must never reach the relay bridge');
        // Honest status: a monthly cap, not hub unavailability.
        self::assertSame(429, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);

        // The law: FLAT top-level discriminator + human text.
        self::assertSame('quota.exceeded', $body['code'] ?? null);
        self::assertIsString($body['error'] ?? null);
        self::assertSame($body['error'], $body['message'] ?? null, 'legacy mirror kept');
        self::assertIsArray($body, 'sanity: the envelope is an object');
        self::assertFalse(is_array($body['error'] ?? false), 'error must never be the nested carrier again');

        // Recovery time, derived from the REAL stored period: body epoch and
        // header seconds must name the same roll this row's calendar key gives.
        $expectedRoll = (new DateTimeImmutable($periodStart . ' +1 month 00:00:00'))->getTimestamp();
        self::assertSame($expectedRoll, $body['retry_at'] ?? null);
        $retryAfter = (int) ($response->headers['Retry-After'] ?? -1);
        self::assertGreaterThanOrEqual(0, $retryAfter);
        self::assertLessThanOrEqual(
            2,
            abs($retryAfter - max(0, $expectedRoll - time())),
            'header and body name the same instant',
        );

        self::assertSame('application/json', $response->headers['Content-Type'] ?? null);
    }

    public function testWithinQuotaRowAllowsAndCarriesNoRecoveryInstant(): void
    {
        $periodStart = date('Y-m-01');
        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, bytes_in, quota_bytes_in)'
            . ' VALUES (:uid, :ps, :bi, :qi)',
            [
                'uid' => self::USER_ID,
                'ps' => $periodStart,
                'bi' => 1048576,
                'qi' => 5242880,
            ],
        );

        $manager = new RelaySessionManager($this->db, $this->createMock(StructuredLogger::class));
        $verdict = $manager->checkUserQuota(self::USER_ID);

        self::assertTrue($verdict['allowed']);
        self::assertNull(
            $verdict['retryAt'],
            'an allowed verdict has nothing to wait for — no fabricated recovery time',
        );
    }
}
