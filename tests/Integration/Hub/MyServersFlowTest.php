<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * End-to-end My Servers dashboard flow:
 * signup → claim server → GET /my-servers → see server list
 * and server removal.
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set.
 *
 * S185: the connect / skip-gate / schema / data-reset boilerplate moved to
 * {@see RealDatabaseTestCase}, which builds the schema once per process and
 * empties every table before and after each test instead of re-applying all 29
 * migrations six times over.
 *
 * @package Phlix\Hub\Tests\Integration\Hub
 *
 * @group integration
 */
final class MyServersFlowTest extends RealDatabaseTestCase
{
    use DecodedJsonAssertions;

    private const SECRET = 'integration-test-secret-32-bytes-minimum';

    private AuthManager $auth;
    private JwtHandler $jwt;
    private ServerInfoHandler $serverInfo;
    private ServerListController $serverListController;
    private ServerManageController $serverManageController;

    protected function setUp(): void
    {
        parent::setUp();

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
        // ⚠ S173 — argument 5 used to be `null`. It was written when
        // `AuthManager::$rateLimiter` was `?RateLimiterInterface $rateLimiter = null`;
        // finding B1 later made it a REQUIRED `RateLimiterInterface` (it replaced an
        // unbounded `static` attempt map), and every test in this file has raised
        // `TypeError: Argument #5 ($rateLimiter) … null given` ever since. Nothing
        // noticed for the same reason S173 exists: with no MySQL service and no
        // `HUB_TEST_DB_*` in CI, all 31 integration tests skipped, and a skip reads
        // as a pass. Mirrors the live wiring the way the sibling
        // SignupLoginFlowTest does.
        $this->auth = new AuthManager(
            $users,
            $this->jwt,
            new AuditLogger($auditLogger),
            $logger,
            new RateLimiter(windowSeconds: 900, maxAttempts: 5, cap: 1000),
            null,
            $this->db,
        );
        $this->serverInfo = new ServerInfoHandler($this->db);
        $this->serverListController = new ServerListController($this->serverInfo);
        $this->serverManageController = new ServerManageController($this->serverInfo, $this->db);
    }

    public function testListServersReturnsEmptyWhenNoServers(): void
    {
        $userResult = $this->auth->register('alice', 'alice@example.com', 'password123');
        $userId = self::stringNode($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverListController->listServers($request);
        self::assertSame(200, $response->statusCode);
        self::assertSame([], $this->decodedServers($response->body));
    }

    public function testListServersReturnsServerAfterClaim(): void
    {
        $userResult = $this->auth->register('bob', 'bob@example.com', 'password123');
        $userId = self::stringNode($userResult['user']['id'] ?? '');
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
        $userId = self::stringNode($userResult['user']['id'] ?? '');
        self::assertNotEmpty($userId);

        $serverId = $this->insertTestServer($userId, 'Deletable Server', '0.11.0', 'online');

        $request = new Request();
        $request->userId = $userId;

        $response = $this->serverManageController->deleteServer($request, ['id' => $serverId]);
        self::assertSame(204, $response->statusCode);

        $listResponse = $this->serverListController->listServers($request);
        self::assertSame([], $this->decodedServers($listResponse->body));
    }

    public function testDeleteServerReturns403ForOtherUsersServer(): void
    {
        $user1Result = $this->auth->register('dave', 'dave@example.com', 'password123');
        $user1Id = self::stringNode($user1Result['user']['id'] ?? '');

        $user2Result = $this->auth->register('eve', 'eve@example.com', 'password123');
        $user2Id = self::stringNode($user2Result['user']['id'] ?? '');

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
        $userId = self::stringNode($userResult['user']['id'] ?? '');
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

    /**
     * Decode the `servers` array out of a JSON response body.
     *
     * ⚠ S173 — this replaces `assertStringContainsString('"servers":[]', …)`.
     * `Response::json()` encodes with `JSON_PRETTY_PRINT` (src/Http/Response.php:116),
     * so the body reads `{\n    "servers": []\n}` and the compact-JSON substring has
     * not matched since pretty-printing was introduced. Asserting on the DECODED
     * structure is both correct and immune to the next formatting change — a
     * substring assertion on serialised JSON is a formatting test wearing a
     * behaviour test's clothes.
     *
     * @return array<array-key, mixed>
     */
    private function decodedServers(string $body): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'response body must be a JSON object');
        self::assertArrayHasKey('servers', $decoded, 'response must carry a `servers` key');
        self::assertIsArray($decoded['servers']);

        return $decoded['servers'];
    }

    /**
     * @param list<string> $hostnameCandidates
     */
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

        // ⚠ S173 — the column list and the value TYPES mirror the production
        // writer, `ClaimRequestHandler::…'INSERT INTO servers'` (src/Hub/ClaimRequestHandler.php:229):
        //  - `servers.last_seen_at` / `created_at` are DATETIME (migration 002), not
        //    integers. This helper passed `time()`, so every test using it errored
        //    with `SQLSTATE[22007] 1292 Incorrect datetime value: '1785780627'` under
        //    the deploy target's STRICT_TRANS_TABLES — unnoticed because the whole
        //    integration suite was skipped in CI.
        //  - `public_key_jwk` is `JSON NOT NULL` with no default (migration 007) and
        //    must be supplied; `jwks_json` was relaxed to NULL by migration 030
        //    precisely because the live code never writes it.
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO servers
                (id, user_id, server_name, version, public_key_jwk, last_seen_at, status,
                 hostname_candidates_json, created_at)
             VALUES
                (:id, :user_id, :server_name, :version, :public_key_jwk, :last_seen_at,
                 :status, :hostname_candidates_json, :created_at)',
            [
                'id' => $serverId,
                'user_id' => $userId,
                'server_name' => $name,
                'version' => $version,
                'public_key_jwk' => json_encode([
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x'   => rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '='),
                ], JSON_THROW_ON_ERROR),
                'last_seen_at' => $now,
                'status' => $status,
                'hostname_candidates_json' => json_encode($hostnameCandidates, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ],
        );

        return $serverId;
    }
}
