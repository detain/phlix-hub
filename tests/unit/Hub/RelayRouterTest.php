<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Hub\DnsAliasManager;
use Phlex\Hub\Hub\Dns\StaticZoneManager;
use Phlex\Hub\Hub\RelayRouter;
use Phlex\Hub\Hub\RelaySessionManager;
use Phlex\Hub\Hub\TlsCertificateManager;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class RelayRouterTest extends TestCase
{
    public function test_extractSubdomain_extracts_correctly(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('abc12345.phlex.media');

        $this->assertSame('abc12345', $result);
    }

    public function test_extractSubdomain_returns_null_for_non_phlex_domain(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('sub.example.com');

        $this->assertNull($result);
    }

    public function test_extractSubdomain_handles_case_insensitivity(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('ABC12345.Phlex.Media');

        $this->assertSame('abc12345', $result);
    }

    public function test_extractSubdomain_returns_null_for_invalid_subdomain_length(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('ab.phlex.media');

        $this->assertNull($result);
    }

    public function test_extractSubdomain_returns_null_for_empty_subdomain(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('.phlex.media');

        $this->assertNull($result);
    }

    public function test_routeBySubdomain_returns_server_id(): void
    {
        $serverId = 'server-abc-123';
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('getActiveSession')
            ->with($serverId)
            ->willReturn(['id' => 'session-123', 'server_id' => $serverId]);

        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->routeBySubdomain('abc12345.phlex.media');

        $this->assertNull($result);
    }

    public function test_getRelaySession_returns_null_when_no_session(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('getActiveSession')
            ->willReturn(null);

        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->getRelaySession('server-no-session');

        $this->assertNull($result);
    }
}
