<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Dns\StaticZoneManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Http\Controllers\SubdomainController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Common\Logger\StructuredLogger;
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

    /**
     * The explicit cert-refresh path must surface ACME-not-
     * implemented as HTTP 501 with a stable error code and a docs
     * link, NOT as a generic 500. A regression to the silent-stub
     * behaviour would either return 204 (success) or 500 (mystery) —
     * both fail this assertion.
     */
    public function test_refreshCertificate_returns_501_when_acme_not_implemented(): void
    {
        $dnsManager = $this->createMock(DnsAliasManager::class);
        $dnsManager->method('refreshCertificate')->willThrowException(new \RuntimeException(
            'ACME certificate provisioning is not implemented in this build. '
            . 'Provision certs out-of-band — see docs/hub-admin/tls.md.',
        ));

        $certManager = $this->createMock(TlsCertificateManager::class);
        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('POST', [], null);
        $response = $controller->refreshCertificate($request, ['id' => 'server-123']);

        $this->assertSame(501, $response->statusCode);
        $this->assertSame(
            '</docs/hub-admin/tls.md>; rel="help"',
            $response->headers['Link'] ?? '',
        );
        $responseBody = (string) $response->body;
        $this->assertStringContainsString('NOT_IMPLEMENTED', $responseBody);
        $this->assertStringContainsString('tls.acme_not_implemented', $responseBody);
        $this->assertStringContainsString('docs/hub-admin/tls.md', $responseBody);
    }

    public function test_refreshCertificate_returns_400_without_server_id(): void
    {
        $dnsManager = $this->createMock(DnsAliasManager::class);
        $certManager = $this->createMock(TlsCertificateManager::class);
        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('POST', [], null);
        $response = $controller->refreshCertificate($request, []);

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
