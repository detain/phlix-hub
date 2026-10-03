<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * W5 enforcement tests for `server.max_servers_per_user` at the claim choke
 * ({@see ClaimRequestHandler::handleClaimCode()}).
 *
 * The defaults-preservation law is pinned first: with no resolver wired (or a
 * 0/unlimited answer) the handler must issue EXACTLY the pre-W5 statement set —
 * not even a counting SELECT. Then the cap itself: refusal inside the FOR
 * UPDATE transaction, rollback, no server row.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class ClaimServerCapTest extends TestCase
{
    private string $tmpDir;

    private Connection&MockObject $db;

    /** @var list<string> Every SQL statement the handler issued, in order. */
    private array $statements = [];

    /** @var callable(string, array<string, mixed>): mixed */
    private $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-claimcap-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        $this->statements = [];
        $this->responder = static fn (string $sql, array $params): array => [];

        $this->db = $this->createMock(Connection::class);
        $this->db->method('query')
            ->willReturnCallback(function (string $sql, $params = null) {
                $this->statements[] = $sql;

                return ($this->responder)($sql, (array) $params);
            });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    private function handler(?callable $maxServersResolver): ClaimRequestHandler
    {
        return new ClaimRequestHandler(
            $this->db,
            new Ed25519KeyManager($this->tmpDir . '/key.pem'),
            $this->createMock(StructuredLogger::class),
            $this->createMock(AuditLogger::class),
            'https://hub.example.com',
            $maxServersResolver,
        );
    }

    /**
     * Live unclaimed claim row; COUNT answers come from $count.
     */
    private function seedFlow(?int $ownedCount): void
    {
        $this->responder = function (string $sql, array $params) use ($ownedCount) {
            if (str_contains($sql, 'FROM server_claims WHERE REPLACE')) {
                return [[
                    'id'                        => 'claim-row-1',
                    'claim_code'                => 'DR4Q-7AXB',
                    'expires_at'                => time() + 600,
                    'claimed_by'                => null,
                    'public_key_jwk'            => '{"kty":"OKP","crv":"Ed25519","x":"aaa"}',
                    'hostname_candidates_json'  => '[]',
                    'server_name'               => 'Test Server',
                    'version'                   => '0.12.0',
                ]];
            }
            if ($ownedCount !== null && str_contains($sql, 'COUNT(*) AS cnt FROM servers')) {
                return [['cnt' => $ownedCount]];
            }

            // hub_settings SELECT (enrollment TTL) and everything else: empty.
            return [];
        };
    }

    /** @return list<string> Statements that are the W5 server-cap COUNT query. */
    private function countQueries(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => str_contains($sql, 'COUNT(*) AS cnt FROM servers'),
        ));
    }

    public function testNoResolverIssuesNoCountQueryAndClaims(): void
    {
        $this->seedFlow(null);

        $result = $this->handler(null)->handleClaimCode('DR4Q-7AXB', 'user-1');

        self::assertSame([], $this->countQueries(), 'pre-W5 statement set preserved at default');
        self::assertArrayHasKey('server_id', $result);
        self::assertSame(2, substr_count($result['enrollment_jwt'], '.'), 'JWT minted (3 segments)');
    }

    public function testUnlimitedCapZeroShortCircuitsBeforeAnyQuery(): void
    {
        $this->seedFlow(50);

        $result = $this->handler(static fn (): int => 0)->handleClaimCode('DR4Q-7AXB', 'user-1');

        self::assertSame([], $this->countQueries(), 'cap 0 = unlimited: not even a counting SELECT');
        self::assertArrayHasKey('server_id', $result);
    }

    public function testCapReachedRefusesInsideTransactionAndRollsBack(): void
    {
        $this->seedFlow(2);

        $handler = $this->handler(static fn (): int => 2);
        $this->db->expects(self::never())->method('commitTrans');
        $this->db->expects(self::once())->method('rollBackTrans');

        try {
            $handler->handleClaimCode('DR4Q-7AXB', 'user-1');
            self::fail('expected SERVER_CAP_REACHED');
        } catch (InvalidArgumentException $e) {
            self::assertSame('SERVER_CAP_REACHED', $e->getMessage());
        }

        self::assertCount(1, $this->countQueries());
        foreach ($this->statements as $sql) {
            self::assertStringNotContainsString('INSERT INTO servers', $sql, 'no server row may land on refusal');
        }
    }

    public function testCapNotReachedClaimsAndCountedLive(): void
    {
        $this->seedFlow(1);

        $result = $this->handler(static fn (): int => 2)->handleClaimCode('DR4Q-7AXB', 'user-1');

        self::assertCount(1, $this->countQueries());
        self::assertArrayHasKey('server_id', $result);
    }

    public function testCapReadIsLivePerClaimNotMemoisedValue(): void
    {
        $this->seedFlow(1);

        $calls  = 0;
        $handle = static function () use (&$calls): int {
            $calls++;

            return $calls === 1 ? 5 : 1; // admin tightens the quota between claims
        };

        $handler = $this->handler($handle);
        self::assertArrayHasKey('server_id', $handler->handleClaimCode('DR4Q-7AXB', 'user-1'));

        // Second claim: fresh resolver read says cap 1, owned is still 1 -> refuse.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_CAP_REACHED');
        $handler->handleClaimCode('DR4Q-7AXB', 'user-1');
    }
}
