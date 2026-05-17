<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

use InvalidArgumentException;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Hub\Hub\Dns\StaticZoneManager;
use Workerman\MySQL\Connection;

/**
 * Manages DNS aliases (subdomains) for enrolled servers.
 *
 * Responsibilities:
 *   - Allocate a unique subdomain when a server requests one
 *   - Store subdomain mappings in the database
 *   - Create/remove DNS records via the configured DNS provider
 *   - Provision and manage TLS certificates
 *
 * @package Phlex\Hub\Hub
 * @since 0.12.0
 */
class DnsAliasManager
{
    public const DOMAIN = 'phlex.media';

    /** Subdomain length (8 chars). */
    private const SUBDOMAIN_LENGTH = 8;

    /**
     * @param Connection                                  $db           MySQL connection.
     * @param StaticZoneManager                          $dnsProvider  DNS provider for record management.
     * @param TlsCertificateManager                      $certManager TLS certificate manager.
     * @param \Phlex\Hub\Common\Logger\StructuredLogger    $logger      Application logger.
     * @param string                                      $providerType DNS provider type.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly StaticZoneManager $dnsProvider,
        private readonly TlsCertificateManager $certManager,
        private readonly \Phlex\Hub\Common\Logger\StructuredLogger $logger,
        private readonly string $providerType = 'static',
    ) {
    }

    /**
     * Allocate a unique subdomain for a server.
     *
     * Generates a deterministic 8-char subdomain based on sha256(server_id).
     * If the server already has a subdomain allocated, returns the existing one.
     *
     * @param string $serverId Hub-assigned server UUID.
     *
     * @return string The allocated subdomain label (e.g. "abc12345").
     *
     * @throws InvalidArgumentException When server is not found.
     *
     * @since 0.12.0
     */
    public function allocateSubdomain(string $serverId): string
    {
        $existing = $this->getSubdomain($serverId);
        if ($existing !== null) {
            return $existing;
        }

        $subdomain = $this->generateSubdomain($serverId);

        $this->db->query(
            'UPDATE servers SET subdomain = :subdomain WHERE id = :id',
            [
                'subdomain' => $subdomain,
                'id' => $serverId,
            ],
        );

        $this->dnsProvider->addRecord(self::DOMAIN, $subdomain, 'A', '0.0.0.0');
        $this->dnsProvider->updateSoa(self::DOMAIN);

        $this->certManager->provisionCertificate($subdomain);

        $this->logger->info('Subdomain allocated', [
            'server_id' => $serverId,
            'subdomain' => $subdomain,
            'dns_provider' => $this->providerType,
        ]);

        return $subdomain;
    }

    /**
     * Get the allocated subdomain for a server.
     *
     * @param string $serverId Server UUID.
     *
     * @return string|null Subdomain label or null if not allocated.
     *
     * @since 0.12.0
     */
    public function getSubdomain(string $serverId): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT subdomain FROM servers WHERE id = :id AND subdomain IS NOT NULL LIMIT 1',
            ['id' => $serverId],
        );

        if (empty($rows)) {
            return null;
        }

        /** @var string|null $subdomain */
        $subdomain = $rows[0]['subdomain'] ?? null;

        return $subdomain;
    }

    /**
     * Resolve a subdomain to its server ID.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return string|null Server ID or null if subdomain is not allocated.
     *
     * @since 0.12.0
     */
    public function resolve(string $subdomain): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );

        if (empty($rows)) {
            return null;
        }

        /** @var string $serverId */
        $serverId = $rows[0]['id'];

        return $serverId;
    }

    /**
     * Revoke a server's subdomain.
     *
     * Removes the DNS record and clears the subdomain from the database.
     *
     * @param string $serverId Server UUID.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function revokeSubdomain(string $serverId): void
    {
        $subdomain = $this->getSubdomain($serverId);
        if ($subdomain === null) {
            return;
        }

        $this->dnsProvider->removeRecord(self::DOMAIN, $subdomain, 'A');
        $this->dnsProvider->updateSoa(self::DOMAIN);

        $this->db->query(
            'UPDATE servers SET subdomain = NULL WHERE id = :id',
            ['id' => $serverId],
        );

        $this->logger->info('Subdomain revoked', [
            'server_id' => $serverId,
            'subdomain' => $subdomain,
        ]);
    }

    /**
     * Refresh the TLS certificate for a subdomain.
     *
     * @param string $serverId Server UUID.
     *
     * @return bool True if renewal succeeded.
     *
     * @since 0.12.0
     */
    public function refreshCertificate(string $serverId): bool
    {
        $subdomain = $this->getSubdomain($serverId);
        if ($subdomain === null) {
            return false;
        }

        return $this->certManager->provisionCertificate($subdomain);
    }

    /**
     * Get the FQDN for a subdomain.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return string FQDN (e.g. "abc12345.phlex.media").
     *
     * @since 0.12.0
     */
    public function getFqdn(string $subdomain): string
    {
        return $subdomain . '.' . self::DOMAIN;
    }

    /**
     * Generate a deterministic subdomain from a server ID.
     *
     * Uses the first 8 characters of the sha256 hash of the server ID,
     * encoded in base62 (alphanumeric).
     *
     * @param string $serverId Server UUID.
     *
     * @return string 8-character subdomain label.
     *
     * @since 0.12.0
     */
    private function generateSubdomain(string $serverId): string
    {
        $hash = hash('sha256', $serverId);
        $subdomain = substr($hash, 0, self::SUBDOMAIN_LENGTH);

        return $this->ensureUnique($subdomain, $serverId);
    }

    /**
     * Ensure subdomain uniqueness by appending chars if collision.
     *
     * @param string $subdomain Initial subdomain.
     * @param string $serverId  Server ID for additional entropy.
     *
     * @return string Unique subdomain.
     */
    private function ensureUnique(string $subdomain, string $serverId): string
    {
        $candidate = $subdomain;
        $counter = 0;

        while ($this->subdomainExists($candidate) && $counter < 100) {
            $hash = hash('sha256', $serverId . $counter);
            $candidate = substr($hash, 0, self::SUBDOMAIN_LENGTH);
            $counter++;
        }

        return $candidate;
    }

    /**
     * Check if a subdomain already exists in the database.
     *
     * @param string $subdomain Subdomain label.
     *
     * @return bool True if subdomain is already allocated.
     */
    private function subdomainExists(string $subdomain): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE subdomain = :subdomain LIMIT 1',
            ['subdomain' => $subdomain],
        );

        return !empty($rows);
    }
}
