<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

/**
 * Routes inbound requests to the correct relay session based on subdomain.
 *
 * When a client requests `https://{subdomain}.phlex.media/*`, the hub
 * uses the Host header to look up the server and dispatch to its relay
 * session.
 *
 * @package Phlex\Hub\Hub
 * @since 0.12.0
 */
final class RelayRouter
{
    /**
     * @param DnsAliasManager    $dnsAliasManager DNS alias manager for subdomain resolution.
     * @param RelaySessionManager $sessionManager  Relay session manager for active connections.
     */
    public function __construct(
        private readonly DnsAliasManager $dnsAliasManager,
        private readonly RelaySessionManager $sessionManager,
    ) {
    }

    /**
     * Route an inbound request by Host header to a relay session.
     *
     * Extracts the subdomain from the Host header and resolves it to a
     * server ID, then checks for an active relay session.
     *
     * @param string $host Host header value (e.g. "abc12345.phlex.media").
     *
     * @return string|null Server ID with active relay session, or null if not found.
     *
     * @since 0.12.0
     */
    public function routeBySubdomain(string $host): ?string
    {
        $subdomain = $this->extractSubdomain($host);
        if ($subdomain === null) {
            return null;
        }

        $serverId = $this->dnsAliasManager->resolve($subdomain);
        if ($serverId === null) {
            return null;
        }

        $session = $this->sessionManager->getActiveSession($serverId);
        if ($session === null) {
            return null;
        }

        return $serverId;
    }

    /**
     * Extract the subdomain label from a Host header value.
     *
     * @param string $host Host header value.
     *
     * @return string|null Subdomain label or null if not a subdomain of phlex.media.
     *
     * @since 0.12.0
     */
    public function extractSubdomain(string $host): ?string
    {
        $host = strtolower(trim($host));

        $parts = explode('.', $host);

        if (count($parts) < 3) {
            return null;
        }

        $subdomain = array_shift($parts);
        $domain = implode('.', $parts);

        if ($domain !== DnsAliasManager::DOMAIN) {
            return null;
        }

        if ($subdomain === '' || strlen($subdomain) < 4 || strlen($subdomain) > 63) {
            return null;
        }

        if (!preg_match('/^[a-z0-9]+$/', $subdomain)) {
            return null;
        }

        return $subdomain;
    }

    /**
     * Get the relay session for a server.
     *
     * @param string $serverId Server UUID.
     *
     * @return array<string, mixed>|null Active relay session or null.
     *
     * @since 0.12.0
     */
    public function getRelaySession(string $serverId): ?array
    {
        return $this->sessionManager->getActiveSession($serverId);
    }
}
