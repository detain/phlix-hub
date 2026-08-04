<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Phlix\Hub\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * One test per production registrar: run the REAL private
 * `Application::register*Routes()` method and require its route table to equal
 * the hand-written {@see RouteManifest}, exactly.
 *
 * This is the S174 replacement for the four suites that re-declared their own
 * route table inside the test body (`AdminUserRoutesTest` 9 self-declared route
 * lines, `AdminLogRoutesTest` 4, `AdminDashboardRoutesTest` 3,
 * `AdminSettingsRoutesTest` 3). Those exercise a COPY; this exercises the
 * original. Deleting, renaming, or re-verbing ANY route line in
 * `src/Application.php` turns exactly one of these tests red and names the
 * registrar it came from.
 *
 * Because the comparison is set-EQUALITY, it also fails on an ADDED route — a
 * new endpoint has to be declared in the manifest, which is the point at which
 * someone has to state its auth gate.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class RegistrarRouteTableTest extends RouteRegistrationTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function subRegistrarProvider(): iterable
    {
        foreach (array_keys(RouteManifest::subRegistrarRoutes()) as $registrar) {
            yield $registrar => [$registrar];
        }
    }

    /**
     * The production registrar must register EXACTLY the manifest's routes.
     */
    #[DataProvider('subRegistrarProvider')]
    public function testRegistrarRegistersExactlyItsManifestRoutes(string $registrar): void
    {
        $routes = RouteManifest::subRegistrarRoutes()[$registrar];

        $expected = array_map(
            static fn (array $route): string => RouteManifest::key($route),
            $routes,
        );
        $expected = array_values(array_unique($expected));
        sort($expected);

        $actual = $this->registeredKeys($this->runRegistrar($registrar));

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                'Application::%s() no longer registers the routes S174 pinned. '
                . 'Missing: [%s]. Unexpected: [%s].',
                $registrar,
                implode(', ', array_diff($expected, $actual)),
                implode(', ', array_diff($actual, $expected)),
            ),
        );
    }

    /**
     * A registrar must keep pulling its controller (and middleware) out of the
     * container — the wiring half of "the route is registered".
     */
    #[DataProvider('subRegistrarProvider')]
    public function testRegistrarResolvesItsExpectedServices(string $registrar): void
    {
        $expected = RouteManifest::resolvedServices($registrar);
        self::assertNotSame([], $expected, $registrar . ' must declare its expected container services');

        $this->runRegistrar($registrar);

        $requested = $this->container->requestedIds();
        sort($requested);
        sort($expected);

        self::assertSame(
            $expected,
            $requested,
            sprintf('Application::%s() no longer wires the services S174 pinned', $registrar),
        );
    }

    /**
     * There must be no `register*Routes()` method that nothing pins. A new
     * registrar has to be added to the manifest (and therefore get a route
     * table + gate test) rather than shipping untested.
     */
    public function testEveryRegistrarIsCoveredByTheManifest(): void
    {
        $reflection = new ReflectionClass(Application::class);

        $found = [];
        foreach ($reflection->getMethods() as $method) {
            if (preg_match('/^register[A-Za-z]*Routes$/', $method->getName()) === 1) {
                $found[] = $method->getName();
            }
        }
        sort($found);

        $covered = array_keys(RouteManifest::subRegistrarRoutes());
        // registerRoutes() itself is covered by ApplicationRouteCompositionTest.
        $covered[] = 'registerRoutes';
        sort($covered);

        self::assertSame(
            $covered,
            $found,
            'Application gained or lost a register*Routes() method that the S174 manifest does not know about',
        );
    }
}
