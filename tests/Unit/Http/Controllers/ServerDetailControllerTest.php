<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Http\Controllers\ServerDetailController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

/**
 * Wire-law tests for GET /api/v1/me/servers/{id} (M-HUB-19).
 *
 * One encoding law for every timestamp on this diagnostics payload:
 * unix seconds as JSON ints (matching the surface's own `last_seen_at`
 * and `last_frame_at` legs), never raw MySQL DATETIME strings whose
 * implied timezone is the DB session's, and never a mapper fallback
 * value posing as a real time.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class ServerDetailControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private const SERVER_ID = '11111111-1111-4111-8111-111111111111';
    private const USER_ID = '22222222-2222-4222-8222-222222222222';

    /**
     * @param array<string, mixed>|null  $sessionRow  Raw getActiveSession() row.
     * @param list<array<string, mixed>> $historyRows Raw getHeartbeatHistory() rows.
     */
    private function makeController(?array $sessionRow, array $historyRows): ServerDetailController
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->willReturn(new ServerInfoDto(
            serverId: self::SERVER_ID,
            userId: self::USER_ID,
            serverName: 'living-room',
            version: '0.11.0',
            lastSeenAt: 1_762_081_234,
            status: 'online',
            hostnameCandidates: [],
            relayActive: true,
            libraryCount: 1,
            subdomain: null,
        ));

        $relay = $this->createMock(RelaySessionManager::class);
        $relay->method('getActiveSession')->willReturn($sessionRow);

        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->method('getHeartbeatHistory')->willReturn($historyRows);

        return new ServerDetailController(
            $serverInfo,
            $relay,
            $heartbeat,
            $this->createMock(TlsCertificateManager::class),
        );
    }

    private function request(): Request
    {
        $request = new Request();
        $request->userId = self::USER_ID;
        return $request;
    }

    public function testDetailPayloadShipsEveryTimestampAsUnixSecondsInt(): void
    {
        // Rows are shaped exactly as the fixed sources emit them: MySQL
        // returns UNIX_TIMESTAMP() aliases as numeric strings.
        $sessionRow = [
            'id' => 'sess-1',
            'server_id' => self::SERVER_ID,
            'worker_node' => 'worker-a',
            'opened_at' => '1762080000',
            'closed_at' => null,
            'bytes_in' => '10',
            'bytes_out' => '20',
            'close_reason' => null,
            'last_frame_at' => 1762081000,
        ];
        $historyRows = [[
            'id' => 'hb-1',
            'version' => '0.11.0',
            'uptime_seconds' => 3600,
            'active_sessions' => 2,
            'active_transcodes' => 0,
            'received_at' => 1762081234,
        ]];

        $response = $this->makeController($sessionRow, $historyRows)
            ->getServerDetail($this->request(), ['id' => self::SERVER_ID]);

        self::assertSame(200, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));

        $server = self::arrayNode($payload['server']);
        self::assertSame(1_762_081_234, $server['last_seen_at']);
        // Red on parent: the numeric string passed through verbatim.
        $relay = self::arrayNode($payload['relay_session']);
        self::assertSame(1_762_080_000, $relay['opened_at']);
        $history = self::arrayNode($payload['heartbeat_history']);
        self::assertSame(1_762_081_234, self::arrayNode($history[0])['received_at']);
    }

    public function testDetailPayloadNeverShipsDatetimeStringNorFakeZero(): void
    {
        // Legacy-shaped sources (the parent's actual behavior): opened_at a
        // raw DATETIME string, received_at already narrowed to null upstream.
        // The wire must render an honest null — never the string, and the
        // body must match no DATETIME pattern at all.
        $sessionRow = [
            'id' => 'sess-1',
            'server_id' => self::SERVER_ID,
            'worker_node' => 'worker-a',
            'opened_at' => '2026-10-28 14:03:22',
            'closed_at' => null,
            'bytes_in' => 10,
            'bytes_out' => 20,
            'close_reason' => null,
            'last_frame_at' => null,
        ];
        $historyRows = [[
            'id' => 'hb-1',
            'version' => '0.11.0',
            'uptime_seconds' => 3600,
            'active_sessions' => 2,
            'active_transcodes' => 0,
            'received_at' => null,
        ]];

        $response = $this->makeController($sessionRow, $historyRows)
            ->getServerDetail($this->request(), ['id' => self::SERVER_ID]);

        self::assertStringNotContainsString('2026-10-28 14:03:22', $response->body);
        self::assertDoesNotMatchRegularExpression(
            '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
            $response->body,
            'No MySQL DATETIME string may reach this payload.',
        );

        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertNull(self::arrayNode($payload['relay_session'])['opened_at']);
        self::assertNull(self::arrayNode(self::arrayNode($payload['heartbeat_history'])[0])['received_at']);
    }

    public function testDetailPayloadWithoutSessionOrHistoryIsClean(): void
    {
        $response = $this->makeController(null, [])
            ->getServerDetail($this->request(), ['id' => self::SERVER_ID]);

        self::assertSame(200, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertNull($payload['relay_session']);
        self::assertSame([], self::arrayNode($payload['heartbeat_history']));
        self::assertIsInt(self::arrayNode($payload['server'])['last_seen_at']);
    }
}
