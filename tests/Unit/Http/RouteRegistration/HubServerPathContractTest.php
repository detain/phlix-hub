<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Http\Router;

/**
 * Pin the hub route paths that a DIFFERENT repository hard-codes (S204).
 *
 * WHY THIS EXISTS — `phlix-server` reaches the hub over HTTP with path strings
 * written out as literals in its own source. Nothing on either side could see a
 * disagreement:
 *
 *  - the hub's `SubdomainControllerTest` calls `SubdomainController::allocate()`
 *    DIRECTLY, so the registered path string never reaches a {@see Router};
 *  - the hub's route suites compare the router against {@see RouteManifest},
 *    which is a hand-transcription of `src/Application.php` — it pins that the
 *    hub registers what the hub meant to register, never that the hub registers
 *    what the SERVER calls;
 *  - `phlix-server`'s `SubdomainClientTest` stubs its HTTP client and never
 *    asserts the request path at all.
 *
 * So from hub commit 19d05b7 until S204 the hub registered
 * `POST|DELETE /servers/{id}/subdomain` at the ROOT while phlix-server called
 * `/api/v1/servers/{id}/subdomain` — subdomain claim and release 404'd in
 * production with every gate green in both repos.
 *
 * The pin therefore works from the CALLER's literal inward: the URLs below are
 * transcribed from phlix-server, and each is dispatched through the REAL
 * composed production router. Reaching the handler is asserted by the handler's
 * OWN response (`401 UNAUTHORIZED`, which only the controller emits — the
 * router's miss is a `404 Not Found`), so a route that exists but is shadowed by
 * an earlier pattern cannot pass either.
 *
 * ⚠ Do NOT "fix" a failure here by editing the constants. They are a copy of
 * another repository's source; if they no longer match it, the two repos have
 * diverged and one of them has to move.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class HubServerPathContractTest extends RouteRegistrationTestCase
{
    /**
     * Transcribed from phlix-server `src/Hub/SubdomainClient.php`:
     *
     *   claimSubdomain()   line  81: "/api/v1/servers/{$this->serverId}/subdomain"
     *   releaseSubdomain() line 130: "/api/v1/servers/{$this->serverId}/subdomain"
     *
     * with `{serverId}` standing in for the interpolated `$this->serverId`.
     * Also published as the public contract by phlix-docs
     * (`docs/hub-admin/tls.md`, `docs/dev/tls-certificates.md`) and repeated in
     * {@see \Phlix\Hub\Http\Controllers\SubdomainController}'s own docblock.
     */
    private const SERVER_SUBDOMAIN_PATH = '/api/v1/servers/{serverId}/subdomain';

    /**
     * Transcribed from phlix-server `config/relay.php` line 19:
     *
     *   'hub_wss_url' => 'wss://hub.example.com/api/v1/servers/{id}/relay',
     *
     * i.e. the path component of the legacy relay endpoint template, with the
     * same `{serverId}` placeholder as above. The multiplexed relay protocol
     * connects to the `:8802` WS worker instead, so what this route owes a
     * caller is `RelayController`'s 501 signpost pointing there — which a 404
     * does not deliver.
     */
    private const SERVER_RELAY_PATH = '/api/v1/servers/{serverId}/relay';

    /**
     * A concrete server id of the shape the hub issues (8-4-4-4-12 hex UUID).
     */
    private const SERVER_ID = '7f3b1c20-4d5e-4a1b-9c2d-8e6f0a1b2c3d';

    /**
     * The paths as they were registered before S204: the hub root, with no
     * `/api/v1`. Nothing in the estate has ever addressed these, so they must
     * NOT be registered as well — a route kept at both places would let the two
     * repos drift apart again without any test noticing.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const RETIRED_ROOT_PATHS = [
        ['POST', '/servers/{serverId}/subdomain'],
        ['DELETE', '/servers/{serverId}/subdomain'],
        ['POST', '/servers/{serverId}/relay'],
    ];

    /**
     * `POST` — phlix-server `SubdomainClient::claimSubdomain()`.
     */
    public function testServerSubdomainClaimUrlReachesTheSubdomainController(): void
    {
        $this->assertUrlReachesItsController(
            'POST',
            self::SERVER_SUBDOMAIN_PATH,
            'phlix-server SubdomainClient::claimSubdomain() (src/Hub/SubdomainClient.php:81)',
        );
    }

    /**
     * `DELETE` — phlix-server `SubdomainClient::releaseSubdomain()`.
     */
    public function testServerSubdomainReleaseUrlReachesTheSubdomainController(): void
    {
        $this->assertUrlReachesItsController(
            'DELETE',
            self::SERVER_SUBDOMAIN_PATH,
            'phlix-server SubdomainClient::releaseSubdomain() (src/Hub/SubdomainClient.php:130)',
        );
    }

    /**
     * `POST` — the relay endpoint phlix-server's `config/relay.php` names.
     */
    public function testServerRelayUrlReachesTheRelayController(): void
    {
        $this->assertUrlReachesItsController(
            'POST',
            self::SERVER_RELAY_PATH,
            'phlix-server config/relay.php `hub_wss_url` (line 19)',
        );
    }

    /**
     * The pre-S204 root paths must be gone, not merely duplicated.
     */
    public function testTheRetiredRootPathsAreNoLongerRegistered(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        foreach (self::RETIRED_ROOT_PATHS as [$method, $template]) {
            $url      = $this->concreteUrl($template);
            $response = $router->dispatch($this->request($method, $url));

            self::assertSame(
                404,
                $response->statusCode,
                $method . ' ' . $url . ' is registered at the hub ROOT. No caller in the estate '
                . 'has ever addressed that form (it is the S204 defect); registering it alongside '
                . 'the /api/v1 form would let the two repos silently diverge again.',
            );
        }
    }

    /**
     * The literal really is still the literal phlix-server ships.
     *
     * Only runs where a sibling `phlix-server` checkout exists (the dev box and
     * any full-estate checkout); CI clones one repo, so it is skipped there and
     * the dispatch tests above carry the pin on their own. Its value is that it
     * catches the OTHER direction — phlix-server moving its path — which no
     * hub-only assertion can see.
     */
    public function testTheServerSideLiteralStillMatchesThisContract(): void
    {
        $clientPath = dirname(__DIR__, 5) . '/phlix-server/src/Hub/SubdomainClient.php';

        if (!is_file($clientPath)) {
            self::markTestSkipped('no sibling phlix-server checkout at ' . $clientPath);
        }

        $source = file_get_contents($clientPath);
        self::assertIsString($source, 'could not read ' . $clientPath);

        // The server interpolates its own property into the path, so compare
        // against that exact spelling rather than the resolved URL.
        $serverLiteral = str_replace('{serverId}', '{$this->serverId}', self::SERVER_SUBDOMAIN_PATH);

        self::assertSame(
            2,
            substr_count($source, $serverLiteral),
            'phlix-server no longer requests "' . $serverLiteral . '" twice (claim + release) in '
            . $clientPath . '. The hub registers ' . self::SERVER_SUBDOMAIN_PATH
            . '; if the server has moved, the hub route must move with it.',
        );
    }

    /**
     * Dispatch `$template` through the production route table and require the
     * controller behind it to have answered.
     *
     * No `Authorization` header is sent, so the controller's own
     * `401 UNAUTHORIZED` is the proof of arrival: the router answers a miss with
     * `404 Not Found`, and no middleware runs on these routes (they are in
     * {@see ApplicationRouteCompositionTest}'s ungated set precisely because
     * they authenticate inside the controller).
     */
    private function assertUrlReachesItsController(string $method, string $template, string $caller): void
    {
        $router   = $this->runRegistrar('registerRoutes');
        $url      = $this->concreteUrl($template);
        $response = $router->dispatch($this->request($method, $url));

        self::assertSame(
            401,
            $response->statusCode,
            sprintf(
                '%s %s did not reach its handler (got %d). %s sends exactly this URL, so a 404 here '
                . 'is a production 404 there. Either Application::registerServerRoutes() no longer '
                . 'registers this path, or an earlier pattern shadows it.',
                $method,
                $url,
                $response->statusCode,
                $caller,
            ),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'UNAUTHORIZED',
            $body['error'] ?? null,
            $method . ' ' . $url . ' answered 401 but not from the controller — '
            . 'the body was ' . $response->body,
        );
    }

    /**
     * Substitute the concrete server id into a `{serverId}` template.
     */
    private function concreteUrl(string $template): string
    {
        return str_replace('{serverId}', self::SERVER_ID, $template);
    }
}
