<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\integration\Hub;

use Phlex\Hub\Auth\AuthManager;
use Phlex\Hub\Auth\JwtHandler;
use Phlex\Hub\Auth\UserRepository;
use Phlex\Hub\Common\Database\MigrationRunner;
use Phlex\Hub\Common\Logger\AuditLogger;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Hub\Hub\ServerInfoHandler;
use Phlex\Hub\Http\Controllers\ServerListController;
use Phlex\Hub\Http\Controllers\ServerManageController;
use Phlex\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * End-to-end My Servers dashboard flow:
 * signup → claim server → GET /my-servers → see server list
 * and server removal.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set.
 *
 * @package Phlex\Hub\Tests\integration\Hub
 * @since 0.4.0
 *
 * @covers \Phlex\Hub\Http\Controllers\ServerListController
 * @covers \Phlex\Hub\Http\Controllers\ServerManageController
 * @covers \Phlex\Hub\Hub\ServerInfoHandler
 *
 * @group integration
 */
final class MyServersFlowTest extends TestCase
{
    private const SECRET = 'integration-test-secret-32-bytes-minimum';

    private Connection $db;
    private AuthManager $auth;
    private JwtHandler $jwt;
    private ServerInfoHandler $serverInfo;
    private ServerListController $serverListController;
    private ServerManageController $serverManageController;

    protected function setUp(): void
    {
        $host = getenv('HUB_TEST_DB_HOST');
        $name = getenv('HUB_TEST_DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            self::markTestSkipped(
                'HUB_TEST_DB_* environment variables not set — skipping integration suite.',
            );
        }

        $port = (int) (getenv('HUB_TEST_DB_PORT') ?: '3306');
        $user = (string) (getenv('HUB_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('HUB_TEST_DB_PASSWORD') ?: '');

        $this->db = new Connection($host, $port, $user, $pass, $name);
        $this->skipOnIncompatibleCluster();
        $this->dropAllTables();

        $runner = new MigrationRunner($this->db, dirname(__DIR__, 3) . '/migrations');
        $runner->run();

        $loggerConfig = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => 'php://memory',
                    'level' => 'debug',
                ],
            ],
            'processors' => [],
        ];
        $logger = new StructuredLogger('test', $loggerConfig);
        $auditLogger = new StructuredLogger('test-audit', $loggerConfig);

        $this->jwt = new JwtHandler(self::SECRET);
        $users = new UserRepository($this->db);
        $this->auth = new AuthManager(
            $users,
            $this->jwt,
            new AuditLogger($auditLogger),
            $logger,
            null,
            $this->db,
        );
        $this->serverInfo = new ServerInfoHandler($this->db);
        $this->serverListController = new ServerListController($this->serverInfo);
        $this->serverManageController = new ServerManageController($this->serverInfo, $this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAllTables();
        }
    }

    public function testListServersReturnsEmptyWhenNoServers(): void
    {
        $userResult = $this->auth->register('alice', 'alice@example.com', 'password123');
        $userId = (string) ($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverListController->listServers($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"servers":[]', $response->body);
    }

    public function testListServersReturnsServerAfterClaim(): void
    {
        $userResult = $this->auth->register('bob', 'bob@example.com', 'password123');
        $userId = (string) ($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $serverId = $this->insertTestServer($userId, 'My NAS Server', '0.11.0', 'online');

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverListController->listServers($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString($serverId, $response->body);
        self::assertStringContainsString('My NAS Server', $response->body);
        self::assertStringContainsString('"servers"', $response->body);
    }

    public function testDeleteServerRemovesOwnedServer(): void
    {
        $userResult = $this->auth->register('carol', 'carol@example.com', 'password123');
        $userId = (string) ($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $serverId = $this->insertTestServer($userId, 'Deletable Server', '0.11.0', 'online');

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverManageController->deleteServer($request, ['id' => $serverId]);
        self::assertSame(204, $response->statusCode);

        $listResponse = $this->serverListController->listServers($request);
        self::assertStringContainsString('"servers":[]', $listResponse->body);
    }

    public function testDeleteServerReturns403ForOtherUsersServer(): void
    {
        $user1Result = $this->auth->register('dave', 'dave@example.com', 'password123');
        $user1Id = (string) ($user1Result['user']['id'] ?? '');

        $user2Result = $this->auth->register('eve', 'eve@example.com', 'password123');
        $user2Id = (string) ($user2Result['user']['id'] ?? '');

        $serverId = $this->insertTestServer($user1Id, 'Daves Server', '0.11.0', 'online');

        $request = new Request();
        $request->userId = $user2Id;

        $response = $this->serverManageController->deleteServer($request, ['id' => $serverId]);
        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('server.not_owned', $response->body);
    }

    public function testAccessInfoReturnsDirectUrl(): void
    {
        $userResult = $this->auth->register('frank', 'frank@example.com', 'password123');
        $userId = (string) ($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $directUrl = 'https://192.168.1.100:32400';
        $serverId = $this->insertTestServer(
            $userId,
            'Frank Server',
            '0.12.0',
            'online',
            [$directUrl],
        );

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverManageController->accessInfo($request, ['id' => $serverId]);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString($directUrl, $response->body);
        self::assertStringContainsString('relay_active', $response->body);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $request = new Request();
        $response = $this->serverListController->listServers($request);
        self::assertSame(401, $response->statusCode);
    }

    private function insertTestServer(
        string $userId,
        string $name,
        string $version,
        string $status,
        array $hostnameCandidates = [],
    ): string {
        $serverId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );

        $this->db->query(
            'INSERT INTO servers
                (id, user_id, server_name, version, last_seen_at, status, hostname_candidates_json, created_at)
             VALUES
                (:id, :user_id, :server_name, :version, :last_seen_at,
                 :status, :hostname_candidates_json, :created_at)',
            [
                'id' => $serverId,
                'user_id' => $userId,
                'server_name' => $name,
                'version' => $version,
                'last_seen_at' => time(),
                'status' => $status,
                'hostname_candidates_json' => json_encode($hostnameCandidates),
                'created_at' => time(),
            ],
        );

        return $serverId;
    }

    private function skipOnIncompatibleCluster(): void
    {
        try {
            $rows = $this->db->query(
                "SHOW VARIABLES LIKE 'group_replication_enforce_update_everywhere_checks'",
            );
        } catch (\Throwable) {
            return;
        }
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $row = $rows[0];
        $rawValue = is_array($row) && isset($row['Value']) ? $row['Value'] : '';
        $value = is_string($rawValue) ? $rawValue : '';
        if (strtoupper($value) === 'ON') {
            self::markTestSkipped(
                'Test DB runs Group Replication multi-primary; integration suite needs single-primary.',
            );
        }
    }

    private function dropAllTables(): void
    {
        $name = (string) getenv('HUB_TEST_DB_NAME');
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema",
            ['schema' => $name],
        );
        if (!is_array($rows)) {
            return;
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['TABLE_NAME']) || !is_string($row['TABLE_NAME'])) {
                continue;
            }
            $table = $row['TABLE_NAME'];
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
