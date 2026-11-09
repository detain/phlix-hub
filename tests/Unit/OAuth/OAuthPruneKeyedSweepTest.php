<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthTokenService;
use Workerman\MySQL\Connection;

/**
 * SH-053 (review hub__dba-code #3): the three OAuth stores' pruners speak the
 * two-phase keyed-sweep protocol — one {@see \Phlix\Hub\Common\Database\KeySweeper}
 * batch per arm, never a wide unbounded `DELETE` (Minor 42's law, extended
 * from the relay/MCP stores to the OAuth siblings).
 *
 * The integration behaviour tests (OAuthPruneTimerTest,
 * AuthorizationCodeFlowTest) stay agnostic to HOW rows leave the table; this
 * file pins the HOW, mirroring ClientRelayTokenServiceTest's protocol arms:
 * exact statement sequences, batch ceiling, and the shape-not-value rule that
 * the fake never re-derives predicates.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 */
final class OAuthPruneKeyedSweepTest extends TestCase
{
    /**
     * @var list<string> Captured statement texts.
     */
    private array $sqls = [];

    /**
     * @var list<array<string, mixed>|null> Captured bind params, aligned with $sqls.
     */
    private array $binds = [];

    protected function setUp(): void
    {
        $this->sqls = [];
        $this->binds = [];
    }

    public function testTokenPruneSweepsTheTwoArmsAsSeparateKeyedBatches(): void
    {
        $service = new OAuthTokenService($this->makeFakeDb([
            'SELECT id FROM oauth_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000'
                => [['id' => 'tok-expired']],
            'SELECT id FROM oauth_tokens'
                . ' WHERE revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 1 DAY'
                . ' ORDER BY id LIMIT 1000'
                => [['id' => 'tok-revoked'], ['id' => 'tok-revoked-2']],
        ]));

        self::assertSame(3, $service->pruneExpired(), 'one batch row on arm A, two on arm B');
        self::assertSame(
            [
                'SELECT id FROM oauth_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000',
                'DELETE FROM oauth_tokens WHERE id IN (:id_0)',
                'SELECT id FROM oauth_tokens'
                    . ' WHERE revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 1 DAY'
                    . ' ORDER BY id LIMIT 1000',
                'DELETE FROM oauth_tokens WHERE id IN (:id_0, :id_1)',
            ],
            $this->sqls,
            'each arm must be its own non-locking key SELECT then a PK-named DELETE'
                . ' — the OR-fused wide DELETE must not return',
        );
        self::assertSame(['id_0' => 'tok-expired'], $this->binds[1]);
        self::assertSame(
            ['id_0' => 'tok-revoked', 'id_1' => 'tok-revoked-2'],
            $this->binds[3],
        );
    }

    public function testCodePruneSweepsTheSingleExpiryArm(): void
    {
        $service = new AuthorizationCodeService($this->makeFakeDb([
            'SELECT id FROM oauth_authorization_codes'
                . ' WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000'
                => [['id' => 'code-spent']],
        ]));

        self::assertSame(1, $service->pruneExpired());
        self::assertSame(
            [
                'SELECT id FROM oauth_authorization_codes'
                    . ' WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000',
                'DELETE FROM oauth_authorization_codes WHERE id IN (:id_0)',
            ],
            $this->sqls,
        );
    }

    public function testConsentPruneSweepsExpiryAndConsumedArmsSeparately(): void
    {
        $service = new ConsentTicketService($this->makeFakeDb([
            'SELECT id FROM oauth_consent_requests'
                . ' WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000'
                => [],
            'SELECT id FROM oauth_consent_requests'
                . ' WHERE consumed_at IS NOT NULL ORDER BY id LIMIT 1000'
                => [['id' => 'ticket-burned']],
        ]));

        self::assertSame(1, $service->pruneExpired());
        self::assertSame(
            [
                'SELECT id FROM oauth_consent_requests'
                    . ' WHERE expires_at < NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 1000',
                'SELECT id FROM oauth_consent_requests'
                    . ' WHERE consumed_at IS NOT NULL ORDER BY id LIMIT 1000',
                'DELETE FROM oauth_consent_requests WHERE id IN (:id_0)',
            ],
            $this->sqls,
            'an empty key SELECT must short-circuit phase 2 (no DELETE for a zero batch)',
        );
    }

    public function testIdleTickTouchesEveryArmButDeletesNothing(): void
    {
        $service = new OAuthTokenService($this->makeFakeDb([]));

        self::assertSame(0, $service->pruneExpired());
        self::assertCount(
            2,
            $this->sqls,
            'the healthy steady tick is exactly one key SELECT per arm and zero DELETEs',
        );
    }

    // ------------------------------------------------------------------
    // Fake Connection: answers seeded key-SELECT results verbatim and
    // resolves phase-2 DELETEs from their bound PKs only.
    // ------------------------------------------------------------------

    /**
     * @param array<string, list<array<string, string>>> $keySelectAnswers
     */
    private function makeFakeDb(array $keySelectAnswers): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<string, mixed>|null $params
             * @return list<array<string, mixed>>|int
             */
            function (string $sql, ?array $params = null) use ($keySelectAnswers): array|int {
                $this->sqls[] = $sql;
                $this->binds[] = $params;

                if (str_starts_with($sql, 'SELECT ')) {
                    return $keySelectAnswers[$sql] ?? [];
                }
                if (str_starts_with($sql, 'DELETE FROM ')) {
                    return substr_count($sql, ':id_');
                }

                $this->fail('unexpected SQL in fake: ' . $sql);
            }
        );

        return $db;
    }
}
