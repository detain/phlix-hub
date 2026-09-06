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

/**
 * ⚠ S205 — `createRequest()` below assigns `$request->headers['Authorization']`
 * on a bare `new Request()`, which SKIPS the case normalisation every real
 * request goes through, so these tests can never detect a header read that
 * misses in production. They stayed green while both bearer gates rejected
 * unconditionally on the live hub. The header plumbing is pinned at the
 * boundary instead, by
 * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\WorkermanHeaderBoundaryTest};
 * do not add coverage of a header READ here.
 */
class SubdomainControllerTest extends TestCase
{
    public function testAllocateReturns401WithoutAuthHeader(): void
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

    public function testAllocateReturns400WithoutServerId(): void
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

    public function testRevokeReturns401WithoutAuthHeader(): void
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

    public function testRevokeReturns400WithoutServerId(): void
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
    public function testRefreshCertificateReturns501WhenAcmeNotImplemented(): void
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

    public function testRefreshCertificateReturns400WithoutServerId(): void
    {
        $dnsManager = $this->createMock(DnsAliasManager::class);
        $certManager = $this->createMock(TlsCertificateManager::class);
        $jwtService = $this->createMock(EnrollmentJwtService::class);

        $controller = new SubdomainController($dnsManager, $certManager, $jwtService);

        $request = $this->createRequest('POST', [], null);
        $response = $controller->refreshCertificate($request, []);

        $this->assertSame(400, $response->statusCode);
    }

    /**
     * @param array<string, mixed> $body
     */
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
