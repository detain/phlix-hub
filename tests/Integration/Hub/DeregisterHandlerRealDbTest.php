<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * Deregistration against the REAL MySQL venue.
 *
 * The historical `DELETE ... RETURNING id` was PostgreSQL syntax; MySQL
 * rejects it with error 1064 before ever reaching the row-count check, which
 * only a live InnoDB round-trip can prove fixed (dialect-blind unit mocks
 * happily replayed the Postgres shape). This test runs the true statement
 * and asserts both the delete and the idempotent-second-call path.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set.
 *
 * @group integration
 */
final class DeregisterHandlerRealDbTest extends RealDatabaseTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-dereg-int-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testDeregisterDeletesTheRealRowAndSecondCallReportsNotFound(): void
    {
        $userId = $this->insertUser();
        $serverId = $this->insertServer($userId);

        $loggerConfig = [
            'handlers' => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => [],
        ];
        $logger = new StructuredLogger('test', $loggerConfig);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $handler = new DeregisterHandler($this->db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);

        $handler->handle($serverId, $token);

        $rows = $this->db->query('SELECT id FROM servers WHERE id = :id', ['id' => $serverId]);
        self::assertSame([], $rows, 'deregister must actually remove the row');

        // Second call: the row is gone, so the guarded DELETE affects 0 rows
        // and the handler must surface SERVER_NOT_FOUND (not a SQL error).
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle($serverId, $token);
    }

    private function insertUser(): string
    {
        $id = $this->uuid();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash)'
                . ' VALUES (:id, :username, :email, :pwd)',
            [
                'id' => $id,
                'username' => 'dereg-user-' . substr($id, 0, 8),
                'email' => 'dereg-' . substr($id, 0, 8) . '@example.com',
                'pwd' => password_hash('irrelevant', PASSWORD_ARGON2ID),
            ],
        );

        return $id;
    }

    private function insertServer(string $userId): string
    {
        $id = $this->uuid();
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO servers
                (id, user_id, server_name, version, public_key_jwk, last_seen_at, status,
                 hostname_candidates_json, created_at)
             VALUES
                (:id, :user_id, :server_name, :version, :public_key_jwk, :last_seen_at,
                 :status, :hostname_candidates_json, :created_at)',
            [
                'id' => $id,
                'user_id' => $userId,
                'server_name' => 'dereg-server',
                'version' => '1.0.0',
                'public_key_jwk' => json_encode([
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '='),
                ], JSON_THROW_ON_ERROR),
                'last_seen_at' => $now,
                'status' => 'online',
                'hostname_candidates_json' => json_encode(['dereg.example.com'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ],
        );

        return $id;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
