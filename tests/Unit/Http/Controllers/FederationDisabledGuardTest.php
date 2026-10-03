<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W5 enforcement tests for the `federation.enabled` gate on the admin API.
 *
 * Law: every MUTATOR answers 409 (`provider.not_configured`) before touching
 * any repository while federation is off; reads and hub-config CRUD stay open
 * so the operator can inspect state and re-enable. With no resolver wired the
 * controller behaves exactly as pre-W5 (the 7-arg construction is what every
 * other suite uses — those greens are the preservation pin).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class FederationDisabledGuardTest extends TestCase
{
    use DecodedJsonAssertions;

    private FederationHubRepository&MockObject $hubRepo;

    private FederationController $controller;

    /** @var list<string> */
    private array $mutators = [
        'createPeer',
        'bindPeerLeafHubId',
        'deletePeer',
        'toggleRelay',
        'toggleAdminDelegation',
        'createOutgoingShare',
        'revokeOutgoingShare',
        'acceptIncomingOffer',
        'rejectIncomingOffer',
        'createAdminDelegation',
        'deleteAdminDelegation',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->controller = new FederationController(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(FederationPeerManager::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(FederationMasterPusher::class),
            static fn (): bool => false,
        );
    }

    public function testEveryMutatorRefuses409BeforeAnyRepositoryTouch(): void
    {
        // Nothing on the persistence side may run while disabled.
        $this->hubRepo->expects(self::never())->method('getHubConfig');

        $request        = new Request();
        $request->path  = '/api/v1/me/federation/peers';
        $request->method = 'POST';
        $request->userId = 'admin-1';

        foreach ($this->mutators as $method) {
            self::assertTrue(method_exists($this->controller, $method), "{$method} must exist");

            /** @var \Phlix\Hub\Http\Response $response */
            $response = $this->controller->{$method}($request, ['id' => 'x-1']);

            self::assertSame(409, $response->statusCode, "{$method} must refuse while disabled");
            $body = self::arrayNode(json_decode((string) $response->body, true));
            self::assertSame('provider.not_configured', $body['code'], "{$method} wire code");
        }
    }

    public function testReadsStayOpenWhileDisabled(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id'          => 'hub-1',
            'name'        => 'My Hub',
            'url'         => 'https://hub1.example.com',
            'public_key'  => 'key123',
            'role'        => 'leaf',
            'is_master'   => 0,
            'is_active'   => 1,
        ]);

        $request       = new Request();
        $request->path = '/api/v1/me/federation/hub-config';
        $request->method = 'GET';

        $response = $this->controller->getHubConfig($request);

        self::assertSame(200, $response->statusCode);
        $body = self::arrayNode(json_decode((string) $response->body, true));
        self::assertSame('hub-1', $body['id']);
    }

    public function testEnabledFlagIsLiveReadPerRequest(): void
    {
        $on = false;
        $controller = new FederationController(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(FederationPeerManager::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(FederationMasterPusher::class),
            static function () use (&$on): bool {
                return $on;
            },
        );

        $request        = new Request();
        $request->path  = '/api/v1/me/federation/peers';
        $request->method = 'POST';

        self::assertSame(409, $controller->createPeer($request)->statusCode);

        $on = true;
        // Re-enabled: the guard must release — the request now proceeds past
        // it and fails further down on the missing body (400), NOT 409.
        self::assertNotSame(409, $controller->createPeer($request)->statusCode);
    }
}
