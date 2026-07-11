<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\ClientRelayTokenService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function hash;
use function preg_match;
use function strlen;

/**
 * Unit tests for {@see ClientRelayTokenService}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\ClientRelayTokenService
 */
final class ClientRelayTokenServiceTest extends TestCase
{
    public function test_mint_stores_only_a_hash_never_the_plaintext(): void
    {
        $db = $this->createMock(Connection::class);

        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
                $this->assertStringContainsString('INSERT INTO client_relay_tokens', $sql);
                $captured = $params;
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $result = $service->mint('user-1', 'srv-1');

        // Plaintext returned to caller once.
        $this->assertArrayHasKey('token', $result);
        $this->assertIsString($result['token']);
        $this->assertNotSame('', $result['token']);

        // What was persisted is the SHA-256 hash, NOT the plaintext.
        $this->assertArrayHasKey('token_hash', $captured);
        $this->assertSame(hash('sha256', $result['token']), $captured['token_hash']);
        $this->assertNotSame($result['token'], $captured['token_hash']);
        $this->assertSame('user-1', $captured['user_id']);
        $this->assertSame('srv-1', $captured['server_id']);
    }

    public function test_minted_token_is_csprng_and_at_least_128_bits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $service = new ClientRelayTokenService($db);
        $a = $service->mint('user-1', 'srv-1')['token'];
        $b = $service->mint('user-1', 'srv-1')['token'];

        // Hex token of >= 32 chars => >= 128 bits of entropy; lower-case hex.
        $this->assertGreaterThanOrEqual(32, strlen($a));
        $this->assertSame(1, preg_match('/^[0-9a-f]+$/', $a));
        // Two mints never collide.
        $this->assertNotSame($a, $b);
    }

    public function test_validate_accepts_a_fresh_token_and_returns_identity(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('FROM client_relay_tokens', $sql);
                $this->assertStringContainsString('revoked_at IS NULL', $sql);
                $this->assertStringContainsString('expires_at > NOW()', $sql);
                $this->assertSame(hash('sha256', 'plain-token'), $params['token_hash']);
                return [['user_id' => 'user-1', 'server_id' => 'srv-1']];
            });

        $service = new ClientRelayTokenService($db);
        $identity = $service->validate('plain-token');

        $this->assertSame(['user_id' => 'user-1', 'server_id' => 'srv-1'], $identity);
    }

    public function test_validate_rejects_unknown_expired_or_revoked_token(): void
    {
        // The SQL filters out expired/revoked rows, so the DB returns nothing.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $service = new ClientRelayTokenService($db);
        $this->assertNull($service->validate('does-not-exist'));
    }

    public function test_validate_rejects_empty_token_without_querying(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $service = new ClientRelayTokenService($db);
        $this->assertNull($service->validate(''));
    }

    public function test_revoke_marks_the_token_revoked(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('UPDATE client_relay_tokens SET revoked_at = NOW()', $sql);
                $this->assertStringContainsString('revoked_at IS NULL', $sql);
                $this->assertSame(hash('sha256', 'plain-token'), $params['token_hash']);
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $service->revoke('plain-token');
    }

    public function test_revoke_empty_token_is_a_noop(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $service = new ClientRelayTokenService($db);
        $service->revoke('');
    }

    public function test_revoke_for_user_server_targets_the_pair(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params): array {
                $this->assertStringContainsString('UPDATE client_relay_tokens SET revoked_at = NOW()', $sql);
                $this->assertSame('user-1', $params['user_id']);
                $this->assertSame('srv-1', $params['server_id']);
                return [];
            });

        $service = new ClientRelayTokenService($db);
        $service->revokeForUserServer('user-1', 'srv-1');
    }

    public function test_custom_ttl_is_reflected_in_expiry(): void
    {
        $db = $this->createMock(Connection::class);
        /** @var array<string, mixed> $captured */
        $captured = [];
        $db->method('query')->willReturnCallback(function (string $sql, array $params) use (&$captured): array {
            $captured = $params;
            return [];
        });

        $before = time();
        $service = new ClientRelayTokenService($db, 7200);
        $result = $service->mint('user-1', 'srv-1');

        $this->assertIsInt($result['expires_at']);
        // ~2h ahead (allow a small execution skew).
        $this->assertGreaterThanOrEqual($before + 7200, $result['expires_at']);
        $this->assertLessThanOrEqual($before + 7200 + 5, $result['expires_at']);
        $this->assertSame($result['expires_at'], $captured['expires_at']);
    }

    public function test_non_positive_ttl_falls_back_to_default(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $before = time();
        $service = new ClientRelayTokenService($db, 0);
        $result = $service->mint('user-1', 'srv-1');

        $this->assertGreaterThanOrEqual(
            $before + ClientRelayTokenService::DEFAULT_TTL_SECONDS,
            $result['expires_at'],
        );
    }

    public function test_prune_expired_tokens_issues_correct_delete_query(): void
    {
        $db = $this->createMock(Connection::class);
        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 7;
            },
        );

        $service = new ClientRelayTokenService($db);
        $deleted = $service->pruneExpiredTokens();

        $this->assertSame(7, $deleted);
        $this->assertStringContainsString('DELETE FROM client_relay_tokens', $capturedSql);
        $this->assertStringContainsString('expires_at < NOW() - INTERVAL 1 DAY', $capturedSql);
        $this->assertStringContainsString('revoked_at IS NOT NULL', $capturedSql);
        // No params needed for this query.
        $this->assertNull($capturedParams);
    }

    public function test_prune_expired_tokens_returns_zero_when_result_not_int(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(null);

        $service = new ClientRelayTokenService($db);
        $this->assertSame(0, $service->pruneExpiredTokens());
    }
}
