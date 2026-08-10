<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Dns\StaticZoneManager;
use Phlix\Hub\Hub\RelayRouter;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class RelayRouterTest extends TestCase
{
    public function testExtractSubdomainExtractsCorrectly(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('abc12345.phlix.media');

        $this->assertSame('abc12345', $result);
    }

    public function testExtractSubdomainReturnsNullForNonPhlixDomain(): void
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

    public function testExtractSubdomainHandlesCaseInsensitivity(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('ABC12345.Phlix.Media');

        $this->assertSame('abc12345', $result);
    }

    public function testExtractSubdomainReturnsNullForInvalidSubdomainLength(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('ab.phlix.media');

        $this->assertNull($result);
    }

    public function testExtractSubdomainReturnsNullForEmptySubdomain(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $router = new RelayRouter($dnsManager, $sessionManager);

        $result = $router->extractSubdomain('.phlix.media');

        $this->assertNull($result);
    }

    public function testRouteBySubdomainReturnsServerId(): void
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

        $result = $router->routeBySubdomain('abc12345.phlix.media');

        $this->assertNull($result);
    }

    public function testGetRelaySessionReturnsNullWhenNoSession(): void
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
