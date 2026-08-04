<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Http\Controllers\RelayController;
use Phlix\Hub\Http\Controllers\SubdomainController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\TlsCertificateManager;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * S205 — header reads pinned at the WORKERMAN BOUNDARY, not in the controller.
 *
 * Every other test of these three controllers builds `new Request()` and
 * hand-assigns `$request->headers['Authorization']`. That is precisely the
 * shape production can never produce, and it is why three bearer-token gates
 * rejected unconditionally on the live hub while their tests stayed green:
 *
 *   wire bytes
 *     -> Workerman\Protocols\Http\Request::parseHeaders()  // strtolower()
 *     -> Phlix\Hub\Http\Request::collectHeadersFromWorkerman()  // strtoupper()
 *     -> $request->headers === ['AUTHORIZATION' => 'Bearer …', 'UPGRADE' => …]
 *
 * A test that assigns the array directly SKIPS both transforms, so it can only
 * ever confirm that the controller reads back whatever the test wrote. Every
 * request in this file is therefore assembled as raw HTTP bytes and pushed
 * through `Request::fromWorkerman()` — the same constructor `Application.php`
 * calls on the live dispatch path — so the casing transform actually runs.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Request
 * @covers \Phlix\Hub\Http\Controllers\RelayController
 * @covers \Phlix\Hub\Http\Controllers\SubdomainController
 * @covers \Phlix\Hub\Http\Controllers\ClientMountController
 */
final class WorkermanHeaderBoundaryTest extends TestCase
{
    private string $tmpDir;

    private EnrollmentJwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-header-boundary-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->jwtService = new EnrollmentJwtService(
            new Ed25519KeyManager($this->tmpDir . '/signing-key.pem'),
            'https://hub.example.com',
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
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

    /**
     * The canary that documents the whole defect: after the boundary, the
     * mixed-case key a hand-written test would set does NOT exist.
     *
     * If this ever starts failing because `collectHeadersFromWorkerman()`
     * stopped normalising, the controllers below are still correct — they use
     * the case-insensitive accessor — but this assertion is what tells a reader
     * the normalisation is a real, load-bearing property and not folklore.
     */
    public function testTheBoundaryUppercasesEveryHeaderNameSoAMixedCaseKeyCannotExist(): void
    {
        $request = self::hubRequest('GET', '/api/v1/servers/s1/relay', [
            'Authorization' => 'Bearer token-value',
            'Upgrade' => 'websocket',
            'Accept-Phlix-Protocol' => 'v2',
        ]);

        self::assertArrayHasKey('AUTHORIZATION', $request->headers);
        self::assertArrayNotHasKey('Authorization', $request->headers);
        self::assertArrayNotHasKey('authorization', $request->headers);
        self::assertArrayHasKey('UPGRADE', $request->headers);
        self::assertArrayNotHasKey('Upgrade', $request->headers);

        foreach (array_keys($request->headers) as $name) {
            self::assertSame(strtoupper($name), $name, "header key '{$name}' is not uppercase");
        }

        // The accessor is the only correct way to read it.
        self::assertSame('Bearer token-value', $request->getHeader('Authorization'));
        self::assertSame('Bearer token-value', $request->getHeader('authorization'));
        self::assertSame('websocket', $request->getHeader('Upgrade'));
    }

    // ---------------------------------------------------------------- relay

    /**
     * POSITIVE DIRECTION. A real request carrying a real enrollment JWT must
     * get PAST the bearer gate. 426 (upgrade required) is the next gate down,
     * so seeing 426 rather than 401 proves the token was actually read.
     *
     * The wire casing is varied because a client, a proxy and an HTTP/2 peer
     * all spell the header differently; none of them may change the outcome.
     */
    #[DataProvider('authorizationHeaderCasings')]
    public function testRelayControllerAcceptsAnEnrollmentJwtFromARealRequest(string $headerName): void
    {
        $serverId = 'server-uuid-relay-ok';
        $controller = new RelayController($this->jwtService);

        $request = self::hubRequest('POST', "/api/v1/servers/{$serverId}/relay", [
            $headerName => 'Bearer ' . $this->jwtService->createEnrollmentJwt($serverId),
        ]);

        $response = $controller->handle($request, ['id' => $serverId]);

        self::assertNotSame(401, $response->statusCode, 'the bearer gate rejected a valid real request');
        self::assertSame(426, $response->statusCode);
        self::assertSame('UPGRADE_REQUIRED', self::body($response->body)['error']);
    }

    /**
     * The fully authorised path: valid JWT AND a real `Upgrade: websocket`
     * header on the wire must reach the 501 WS-steer. Both header reads in
     * `RelayController::handle()` are exercised here.
     */
    public function testRelayControllerReachesTheWsSteerForAFullyValidRealRequest(): void
    {
        $serverId = 'server-uuid-relay-ws';
        $controller = new RelayController($this->jwtService);

        $request = self::hubRequest('POST', "/api/v1/servers/{$serverId}/relay", [
            'Authorization' => 'Bearer ' . $this->jwtService->createEnrollmentJwt($serverId),
            'Upgrade' => 'websocket',
        ]);

        $response = $controller->handle($request, ['id' => $serverId]);

        self::assertSame(501, $response->statusCode);
        $body = self::body($response->body);
        self::assertSame('NOT_IMPLEMENTED_VIA_HTTP', $body['error']);
        self::assertSame('relay.ws_http_endpoint', $body['code']);
    }

    /**
     * NEGATIVE DIRECTION. The gate must still reject. Without this, "always
     * allow" would pass every positive assertion above.
     */
    public function testRelayControllerStillRejectsARealRequestWithNoAuthorizationHeader(): void
    {
        $controller = new RelayController($this->jwtService);

        $request = self::hubRequest('POST', '/api/v1/servers/s1/relay', ['Upgrade' => 'websocket']);
        $response = $controller->handle($request, ['id' => 's1']);

        self::assertSame(401, $response->statusCode);
    }

    /**
     * NEGATIVE DIRECTION. A syntactically present but bogus token is still
     * rejected once it is actually read.
     */
    public function testRelayControllerStillRejectsARealRequestWithAGarbageToken(): void
    {
        $controller = new RelayController($this->jwtService);

        $request = self::hubRequest('POST', '/api/v1/servers/s1/relay', [
            'Authorization' => 'Bearer not.a.jwt',
        ]);
        $response = $controller->handle($request, ['id' => 's1']);

        self::assertSame(401, $response->statusCode);
    }

    // ------------------------------------------------------------ subdomain

    /**
     * POSITIVE DIRECTION for `SubdomainController::allocate()` — a real request
     * with a real enrollment JWT must reach subdomain allocation and return
     * 200, not the unconditional 401 the raw-array read produced.
     */
    #[DataProvider('authorizationHeaderCasings')]
    public function testSubdomainAllocateAcceptsAnEnrollmentJwtFromARealRequest(string $headerName): void
    {
        $serverId = 'server-uuid-subdomain-ok';

        $dnsManager = $this->createMock(DnsAliasManager::class);
        $dnsManager->method('allocateSubdomain')->willReturn('alloc-123');
        $dnsManager->method('getFqdn')->willReturn('alloc-123.phlix.media');
        $certManager = $this->createMock(TlsCertificateManager::class);

        $controller = new SubdomainController($dnsManager, $certManager, $this->jwtService);

        $request = self::hubRequest('POST', "/api/v1/servers/{$serverId}/subdomain", [
            $headerName => 'Bearer ' . $this->jwtService->createEnrollmentJwt($serverId),
        ]);

        $response = $controller->allocate($request, ['id' => $serverId]);

        self::assertNotSame(401, $response->statusCode, 'the bearer gate rejected a valid real request');
        self::assertSame(200, $response->statusCode);
        $body = self::body($response->body);
        self::assertSame('alloc-123', $body['subdomain']);
        self::assertSame('alloc-123.phlix.media', $body['fqdn']);
    }

    /**
     * POSITIVE DIRECTION for `SubdomainController::revoke()` — the revocation
     * must actually be invoked, so assert on the collaborator call as well as
     * the 204. A status-only assertion would pass if the handler returned 204
     * without doing anything.
     */
    public function testSubdomainRevokeAcceptsAnEnrollmentJwtFromARealRequest(): void
    {
        $serverId = 'server-uuid-subdomain-revoke';

        $dnsManager = $this->createMock(DnsAliasManager::class);
        $dnsManager->expects(self::once())->method('revokeSubdomain')->with($serverId);
        $certManager = $this->createMock(TlsCertificateManager::class);

        $controller = new SubdomainController($dnsManager, $certManager, $this->jwtService);

        $request = self::hubRequest('DELETE', "/api/v1/servers/{$serverId}/subdomain", [
            'Authorization' => 'Bearer ' . $this->jwtService->createEnrollmentJwt($serverId),
        ]);

        $response = $controller->revoke($request, ['id' => $serverId]);

        self::assertNotSame(401, $response->statusCode, 'the bearer gate rejected a valid real request');
        self::assertSame(204, $response->statusCode);
    }

    /**
     * NEGATIVE DIRECTION — both subdomain gates must still reject, and revoke
     * must not touch DNS when they do.
     */
    public function testSubdomainGatesStillRejectARealRequestWithNoAuthorizationHeader(): void
    {
        $dnsManager = $this->createMock(DnsAliasManager::class);
        $dnsManager->expects(self::never())->method('allocateSubdomain');
        $dnsManager->expects(self::never())->method('revokeSubdomain');
        $certManager = $this->createMock(TlsCertificateManager::class);

        $controller = new SubdomainController($dnsManager, $certManager, $this->jwtService);

        $allocate = $controller->allocate(
            self::hubRequest('POST', '/api/v1/servers/s1/subdomain'),
            ['id' => 's1'],
        );
        $revoke = $controller->revoke(
            self::hubRequest('DELETE', '/api/v1/servers/s1/subdomain'),
            ['id' => 's1'],
        );

        self::assertSame(401, $allocate->statusCode);
        self::assertSame(401, $revoke->statusCode);
    }

    /**
     * NEGATIVE DIRECTION — a token minted for a DIFFERENT server must not
     * allocate a subdomain for this one. This is the assertion that would
     * survive a lazy "always authorise" fix.
     */
    public function testSubdomainAllocateStillRejectsATokenMintedForAnotherServer(): void
    {
        $dnsManager = $this->createMock(DnsAliasManager::class);
        $dnsManager->expects(self::never())->method('allocateSubdomain');
        $certManager = $this->createMock(TlsCertificateManager::class);

        $controller = new SubdomainController($dnsManager, $certManager, $this->jwtService);

        $request = self::hubRequest('POST', '/api/v1/servers/server-a/subdomain', [
            'Authorization' => 'Bearer ' . $this->jwtService->createEnrollmentJwt('server-b'),
        ]);

        $response = $controller->allocate($request, ['id' => 'server-a']);

        self::assertSame(401, $response->statusCode);
    }

    // ----------------------------------------------------------- clientmount

    /**
     * `ClientMountController::handle()` reads `Upgrade` from the same raw bag.
     * With a real request the 501 WS-steer was unreachable — every caller got
     * 426 regardless of what they sent.
     */
    public function testClientMountReachesTheWsSteerWhenARealRequestUpgrades(): void
    {
        $controller = new ClientMountController(self::unusedContainer());

        $request = self::hubRequest('GET', '/client/server-uuid-ccc', ['Upgrade' => 'websocket']);
        $response = $controller->handle($request, ['server_id' => 'server-uuid-ccc']);

        self::assertSame(501, $response->statusCode);
        self::assertSame('NOT_IMPLEMENTED_VIA_HTTP', self::body($response->body)['error']);
    }

    /**
     * NEGATIVE DIRECTION — no upgrade header on the wire still means 426.
     */
    public function testClientMountStillReturns426ForARealRequestWithoutAnUpgradeHeader(): void
    {
        $controller = new ClientMountController(self::unusedContainer());

        $request = self::hubRequest('GET', '/client/server-uuid-ddd');
        $response = $controller->handle($request, ['server_id' => 'server-uuid-ddd']);

        self::assertSame(426, $response->statusCode);
        self::assertSame('UPGRADE_REQUIRED', self::body($response->body)['error']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, array{0: string}>
     */
    public static function authorizationHeaderCasings(): array
    {
        return [
            'canonical' => ['Authorization'],
            'lowercase (HTTP/2, many proxies)' => ['authorization'],
            'screaming' => ['AUTHORIZATION'],
            'mixed' => ['AuThOrIzAtIoN'],
        ];
    }

    /**
     * Assemble raw HTTP bytes and run them through the SAME constructor the
     * live worker uses (`Application.php` -> `Request::fromWorkerman()`), so
     * Workerman's lowercasing and the hub's uppercasing both execute.
     *
     * @param array<string, string> $headers
     */
    private static function hubRequest(string $method, string $path, array $headers = []): Request
    {
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: hub.example.com'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return Request::fromWorkerman(new WorkermanRequest($raw));
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    private static function unusedContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('container not used by handle(): ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
