<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Hub\DnsAliasManager;
use Phlex\Hub\Hub\Dns\StaticZoneManager;
use Phlex\Hub\Hub\EnrollmentJwtService;
use Phlex\Hub\Hub\TlsCertificateManager;
use Phlex\Hub\Http\Controllers\SubdomainController;
use Phlex\Hub\Http\Request;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class SubdomainControllerTest extends TestCase
{
    public function test_allocate_returns_401_without_auth_header(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('POST', [], null);
        $response = $controller->allocate($request, ['id' => 'server-123']);

        $this->assertSame(401, $response->statusCode);
    }

    public function test_allocate_returns_400_without_server_id(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('POST', [], "Bearer valid-token");
        $response = $controller->allocate($request, []);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_revoke_returns_401_without_auth_header(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('DELETE', [], null);
        $response = $controller->revoke($request, ['id' => 'server-123']);

        $this->assertSame(401, $response->statusCode);
    }

    public function test_revoke_returns_400_without_server_id(): void
    {
        $db = $this->createMock(Connection::class);
        $zoneManager = new StaticZoneManager('/tmp/zones');
        $certManager = $this->createMock(TlsCertificateManager::class);
        $logger = new StructuredLogger('test', []);
        $dnsManager = new DnsAliasManager($db, $zoneManager, $certManager, $logger);

        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('DELETE', [], "Bearer valid-token");
        $response = $controller->revoke($request, []);

        $this->assertSame(400, $response->statusCode);
    }

    private function createRequest(string $method, array $body, ?string $authHeader): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/api/v1/servers/test/subdomain';
        $request->body = $body;
        if ($authHeader !== null) {
            $request->headers['Authorization'] = $authHeader;
        }
        return $request;
    }
}
