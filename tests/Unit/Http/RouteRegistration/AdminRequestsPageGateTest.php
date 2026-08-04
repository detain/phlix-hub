<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Middleware\AdminMiddleware;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Response;

/**
 * S206 — behavioural pin for the admin gate on `GET /admin/requests`.
 *
 * The route used to be registered with `[$authMiddleware]` only while every
 * neighbouring admin group carried `[$authMiddleware, $adminMiddleware]`. The
 * consequence was narrow — the handler is a bare
 * `redirect('/app/admin/requests')`, and the SPA's own data calls under
 * `/api/v1/admin/requests` were (and are) admin-gated — so an authenticated
 * non-admin got a redirect, not data. It is nonetheless the one page path that
 * advertised an admin surface to a caller who may not use it.
 *
 * WHY THIS SUITE EXISTS SEPARATELY FROM THE MANIFEST SUITES — a route table
 * says which middleware a group DECLARES, not what a request actually receives.
 * This estate has already shipped a defect (S208) where a declared middleware
 * was not honoured by the handler, and an S174 manifest of literals can read
 * green while the runtime behaviour is wrong. So every assertion below is on a
 * DISPATCHED response:
 *
 *  - a real HS256 token minted by the real {@see \Phlix\Hub\Auth\JwtHandler};
 *  - the real {@see AuthMiddleware} and the real {@see AdminMiddleware};
 *  - the route table built by the real `Application::registerRequestRoutes()`.
 *
 * Both directions are covered: a non-admin must be REFUSED, and an admin must
 * still get the redirect (otherwise "refused" could just mean "the route is
 * broken/absent for everyone"). The sibling user page `/requests` acts as the
 * control that the non-admin's credentials are themselves acceptable.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class AdminRequestsPageGateTest extends RouteRegistrationTestCase
{
    /** The admin queue page under test. */
    private const ADMIN_PAGE = '/admin/requests';

    /** The SPA route the handler redirects to when the gate passes. */
    private const ADMIN_TARGET = '/app/admin/requests';

    /** Sibling user-facing page, used as the "credentials are fine" control. */
    private const USER_PAGE = '/requests';

    /** The SPA route the user page redirects to. */
    private const USER_TARGET = '/app/requests';

    /**
     * An authenticated NON-admin must be refused by {@see AdminMiddleware}, and
     * must NOT be handed the redirect into the admin SPA route.
     *
     * `parent::setUp()` stubs `userExists() => true` and
     * `findAdminById() => null`, i.e. a real, existing, non-admin user.
     */
    public function testAuthenticatedNonAdminIsRefusedTheAdminRequestsPage(): void
    {
        $this->audit->expects(self::once())
            ->method('logPermissionDenied')
            ->with('s206-non-admin', 'admin', 'access');

        $response = $this->dispatchAdminPage('s206-non-admin');

        self::assertSame(
            403,
            $response->statusCode,
            'GET /admin/requests must be admin-gated — an authenticated non-admin got '
            . $response->statusCode . ' instead of 403',
        );
        self::assertStringContainsString('auth.not_admin', $response->body);

        // The specific regression: the pre-S206 route answered 302 and told the
        // caller where the admin queue lives.
        self::assertArrayNotHasKey(
            'Location',
            $response->headers,
            'a refused non-admin must not be redirected anywhere',
        );
        self::assertStringNotContainsString(self::ADMIN_TARGET, $response->body);
    }

    /**
     * An ADMIN must still get the redirect. Without this, the 403 above could
     * equally be produced by a route that is simply broken for everybody.
     */
    public function testAdminStillReceivesTheRedirectToTheSpa(): void
    {
        $this->useAdminUser();

        $response = $this->dispatchAdminPage('s206-admin');

        self::assertSame(302, $response->statusCode);
        self::assertSame(self::ADMIN_TARGET, $response->headers['Location'] ?? null);
    }

    /**
     * Control — the same non-admin credentials sail through the sibling user
     * page. Proves the 403 above comes from the ADMIN check on this one route,
     * not from a token/harness problem that would refuse everything.
     */
    public function testTheSameNonAdminStillReachesTheUserRequestsPage(): void
    {
        $router = $this->runRegistrar('registerRequestRoutes');

        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request('GET', self::USER_PAGE, 's206-non-admin-control'),
            'Application::registerRequestRoutes() must register GET ' . self::USER_PAGE,
        );

        self::assertSame(302, $response->statusCode);
        self::assertSame(self::USER_TARGET, $response->headers['Location'] ?? null);
    }

    /**
     * The same refusal must hold in the COMPOSED table, where `/admin/requests`
     * sits alongside every other registrar's patterns. A per-registrar router
     * cannot see an earlier pattern shadowing this path with an ungated route.
     */
    public function testTheRefusalHoldsInTheComposedProductionTable(): void
    {
        $router = $this->runRegistrar('registerRoutes');

        $response = $this->dispatchExpectingRegistered(
            $router,
            $this->request('GET', self::ADMIN_PAGE, 's206-non-admin-composed'),
            'Application::registerRoutes() must register GET ' . self::ADMIN_PAGE,
        );

        self::assertSame(
            403,
            $response->statusCode,
            'GET /admin/requests answered ' . $response->statusCode
            . ' in the composed table — an earlier pattern is probably shadowing it',
        );
    }

    /**
     * Build the request-routes table and dispatch the admin page as `$userId`.
     */
    private function dispatchAdminPage(string $userId): Response
    {
        $router = $this->runRegistrar('registerRequestRoutes');

        return $this->dispatchExpectingRegistered(
            $router,
            $this->request('GET', self::ADMIN_PAGE, $userId),
            'Application::registerRequestRoutes() must register GET ' . self::ADMIN_PAGE,
        );
    }

    /**
     * Swap the harness onto an ADMIN user.
     *
     * `parent::setUp()` has already stubbed `findAdminById() => null` on
     * `$this->users`, and PHPUnit honours the FIRST matching stub, so the mock
     * and everything built from it are rebuilt rather than re-stubbed. Must run
     * before {@see RouteRegistrationTestCase::runRegistrar()}, which reads
     * `$this->container`.
     */
    private function useAdminUser(): void
    {
        AuthMiddleware::resetCache();

        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(true);
        $users->method('findAdminById')->willReturn([
            'id'       => 's206-admin',
            'email'    => 's206-admin@example.test',
            'is_admin' => true,
        ]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logPermissionDenied');

        $this->users           = $users;
        $this->audit           = $audit;
        $this->authMiddleware  = new AuthMiddleware($this->jwt, $users);
        $this->adminMiddleware = new AdminMiddleware($users, $audit);
        $this->container       = new RouteRegistrationContainer([
            AuthMiddleware::class  => $this->authMiddleware,
            AdminMiddleware::class => $this->adminMiddleware,
        ]);
    }
}
