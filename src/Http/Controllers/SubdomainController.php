<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Handles subdomain allocation and revocation for enrolled servers.
 *
 * POST   /api/v1/servers/{id}/subdomain  — allocate or retrieve subdomain
 * DELETE /api/v1/servers/{id}/subdomain  — revoke subdomain
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.12.0
 */
final class SubdomainController
{
    /**
     * @param DnsAliasManager      $dnsAliasManager DNS alias manager.
     * @param TlsCertificateManager $certManager     TLS certificate manager.
     * @param EnrollmentJwtService $jwtService      JWT validation service.
     */
    public function __construct(
        private readonly DnsAliasManager $dnsAliasManager,
        private readonly TlsCertificateManager $certManager,
        private readonly EnrollmentJwtService $jwtService,
    ) {
    }

    /**
     * POST /api/v1/servers/{id}/subdomain — allocate or retrieve a subdomain.
     *
     * If the server already has a subdomain, returns the existing one.
     * Otherwise, allocates a new subdomain and provisions a TLS certificate.
     *
     * @param Request          $request Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'id' (server UUID).
     *
     * @return Response
     *
     * @since 0.12.0
     */
    public function allocate(Request $request, array $params): Response
    {
        $serverIdFromPath = $params['id'] ?? '';

        if ($serverIdFromPath === '') {
            return (new Response())->status(400)->json([
                'error' => 'MISSING_SERVER_ID',
                'message' => 'Server ID is required',
            ]);
        }

        $authHeader = $request->headers['Authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())->status(401)->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid Authorization header',
            ]);
        }

        $enrollmentJwt = substr($authHeader, 7);

        try {
            $kid = $this->extractKid($enrollmentJwt);
            if ($kid === null) {
                return $this->unauthorized('Invalid token format');
            }

            $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
            if ($payload === null) {
                return $this->unauthorized('Invalid or expired enrollment token');
            }

            if (($payload['server_id'] ?? '') !== $serverIdFromPath) {
                return $this->unauthorized('Server ID mismatch');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->unauthorized($e->getMessage());
        }

        try {
            $subdomain = $this->dnsAliasManager->allocateSubdomain($serverIdFromPath);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(404)->json([
                'error' => 'SERVER_NOT_FOUND',
                'message' => $e->getMessage(),
            ]);
        }

        $fqdn = $this->dnsAliasManager->getFqdn($subdomain);
        $certPath = $this->certManager->getCertificatePath($subdomain);
        $keyPath = $this->certManager->getPrivateKeyPath($subdomain);

        return (new Response())->json([
            'subdomain' => $subdomain,
            'fqdn' => $fqdn,
            'tls_cert_path' => $certPath ?? '',
            'tls_key_path' => $keyPath ?? '',
        ]);
    }

    /**
     * DELETE /api/v1/servers/{id}/subdomain — revoke the server's subdomain.
     *
     * @param Request          $request Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'id' (server UUID).
     *
     * @return Response
     *
     * @since 0.12.0
     */
    public function revoke(Request $request, array $params): Response
    {
        $serverIdFromPath = $params['id'] ?? '';

        if ($serverIdFromPath === '') {
            return (new Response())->status(400)->json([
                'error' => 'MISSING_SERVER_ID',
                'message' => 'Server ID is required',
            ]);
        }

        $authHeader = $request->headers['Authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())->status(401)->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid Authorization header',
            ]);
        }

        $enrollmentJwt = substr($authHeader, 7);

        try {
            $kid = $this->extractKid($enrollmentJwt);
            if ($kid === null) {
                return $this->unauthorized('Invalid token format');
            }

            $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
            if ($payload === null) {
                return $this->unauthorized('Invalid or expired enrollment token');
            }

            if (($payload['server_id'] ?? '') !== $serverIdFromPath) {
                return $this->unauthorized('Server ID mismatch');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $this->dnsAliasManager->revokeSubdomain($serverIdFromPath);

        return (new Response())->status(204);
    }

    /**
     * Build a 401 Unauthorized response.
     *
     * @param string $message Error message.
     *
     * @return Response
     */
    private function unauthorized(string $message): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'UNAUTHORIZED',
            'message' => $message,
        ]);
    }

    /**
     * Extract the `kid` from a JWT header.
     *
     * @param string $token JWT string.
     *
     * @return string|null Key ID or null.
     */
    private function extractKid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($decoded === false) {
                return null;
            }
            /** @var array<string, mixed> $header */
            $header = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
            /** @var string|null $kid */
            $kid = $header['kid'] ?? null;
            return is_string($kid) ? $kid : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
