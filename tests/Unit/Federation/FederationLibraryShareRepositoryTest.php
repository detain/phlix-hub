<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see FederationLibraryShareRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationLibraryShareRepository
 */
final class FederationLibraryShareRepositoryTest extends TestCase
{
    public function testCreateOutgoingShareInsertsShare(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO federation_library_shares'),
                self::callback(function (array $params) {
                    return $params['id'] === 'share-1'
                        && $params['library_id'] === 'lib-123'
                        && $params['library_name'] === 'My Library'
                        && $params['peer_id'] === 'peer-abc'
                        && $params['permission'] === 'read';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->createOutgoingShare('share-1', 'lib-123', 'My Library', 'peer-abc', 'read');
    }

    public function testGetOutgoingSharesReturnsAllShares(): void
    {
        $db = $this->createMock(Connection::class);
        $shares = [
            ['id' => 'share-1', 'library_id' => 'lib-1'],
            ['id' => 'share-2', 'library_id' => 'lib-2'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT * FROM federation_library_shares'))
            ->willReturn($shares);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getOutgoingShares();

        self::assertSame($shares, $result);
    }

    public function testGetOutgoingShareByIdReturnsShareWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $share = ['id' => 'share-find', 'library_id' => 'lib-x'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_library_shares WHERE id'),
                ['id' => 'share-find']
            )
            ->willReturn([$share]);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getOutgoingShareById('share-find');

        self::assertSame($share, $result);
    }

    public function testGetOutgoingShareByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getOutgoingShareById('non-existent');

        self::assertNull($result);
    }

    public function testGetActiveOutgoingSharesReturnsOnlyActive(): void
    {
        $db = $this->createMock(Connection::class);
        $activeShares = [
            ['id' => 'share-active', 'status' => 'active'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains("WHERE status = :status"),
                ['status' => 'active']
            )
            ->willReturn($activeShares);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getActiveOutgoingShares();

        self::assertSame($activeShares, $result);
    }

    public function testRevokeOutgoingShareUpdatesStatus(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_library_shares'),
                self::callback(function (array $params) {
                    return $params['id'] === 'share-revoke'
                        && $params['status'] === 'revoked';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->revokeOutgoingShare('share-revoke');
    }

    public function testGetIncomingOffersReturnsAllOffers(): void
    {
        $db = $this->createMock(Connection::class);
        $offers = [
            ['id' => 'offer-1', 'library_id' => 'lib-incoming'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT * FROM federation_incoming_share_offers'))
            ->willReturn($offers);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getIncomingOffers();

        self::assertSame($offers, $result);
    }

    public function testGetIncomingOfferByIdReturnsOfferWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $offer = ['id' => 'offer-find', 'library_id' => 'lib-offer'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_incoming_share_offers WHERE id'),
                ['id' => 'offer-find']
            )
            ->willReturn([$offer]);

        $repo = new FederationLibraryShareRepository($db);
        $result = $repo->getIncomingOfferById('offer-find');

        self::assertSame($offer, $result);
    }

    public function testAcceptIncomingOfferUpdatesStatusAndAcceptedBy(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_incoming_share_offers'),
                self::callback(function (array $params) {
                    return $params['id'] === 'offer-accept'
                        && $params['status'] === 'accepted'
                        && $params['accepted_by'] === 'user-123';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->acceptIncomingOffer('offer-accept', 'user-123');
    }

    public function testRejectIncomingOfferUpdatesStatusWithRejected(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_incoming_share_offers'),
                self::callback(function (array $params) {
                    return $params['id'] === 'offer-reject'
                        && $params['status'] === 'rejected'
                        && $params['accepted_by'] === 'user-456';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->rejectIncomingOffer('offer-reject', 'user-456');
    }

    public function testHandleIncomingOfferInsertsOrUpdatesOffer(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO federation_incoming_share_offers'),
                self::callback(function (array $params) {
                    return $params['id'] === 'incoming-offer-1'
                        && $params['peer_id'] === 'peer-offer'
                        && $params['library_id'] === 'lib-offer'
                        && $params['library_name'] === 'Shared Library'
                        && $params['permission'] === 'readwrite';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->handleIncomingOffer([
            'id' => 'incoming-offer-1',
            'peer_id' => 'peer-offer',
            'library_id' => 'lib-offer',
            'library_name' => 'Shared Library',
            'permission' => 'readwrite',
        ]);
    }

    public function testHandleIncomingOfferIgnoresInvalidOfferWithMissingId(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::never())
            ->method('query');

        $repo = new FederationLibraryShareRepository($db);
        $repo->handleIncomingOffer([
            'peer_id' => 'peer-offer',
            'library_id' => 'lib-offer',
        ]);
    }

    public function testHandleIncomingOfferIgnoresInvalidOfferWithMissingPeerId(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::never())
            ->method('query');

        $repo = new FederationLibraryShareRepository($db);
        $repo->handleIncomingOffer([
            'id' => 'offer-id',
            'library_id' => 'lib-offer',
        ]);
    }

    public function testHandleIncomingOfferIgnoresInvalidOfferWithMissingLibraryId(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::never())
            ->method('query');

        $repo = new FederationLibraryShareRepository($db);
        $repo->handleIncomingOffer([
            'id' => 'offer-id',
            'peer_id' => 'peer-offer',
        ]);
    }

    public function testHandleIncomingOfferDefaultsPermissionToRead(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(function (array $params) {
                    return $params['permission'] === 'read';
                })
            )
            ->willReturn([]);

        $repo = new FederationLibraryShareRepository($db);
        $repo->handleIncomingOffer([
            'id' => 'offer-no-perm',
            'peer_id' => 'peer-offer',
            'library_id' => 'lib-offer',
            'library_name' => 'Library',
            // permission missing - should default to 'read'
        ]);
    }
}
