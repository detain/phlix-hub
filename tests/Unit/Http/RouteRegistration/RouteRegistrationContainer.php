<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * PSR-11 stand-in that lets the REAL `Application::register*Routes()` methods
 * run without booting the hub's DI container.
 *
 * Preset entries (the real {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} and
 * {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}) are returned as-is so the
 * middleware chain a registrar wires is production code. Anything else — the
 * `final` controllers, which PHPUnit cannot mock and which take three-to-six
 * collaborators each — is materialised with
 * {@see ReflectionClass::newInstanceWithoutConstructor()}. That is enough for a
 * registrar: it only needs an instance that passes the `instanceof` guard in
 * `Application::resolve*Controller()` and can be captured in the route closure.
 * The route-registration suites never invoke those handlers (an unauthenticated
 * or non-admin request short-circuits in middleware first).
 *
 * Every requested id is recorded so a suite can assert WHICH controllers a
 * registrar wired — deleting a `resolve*Controller()` call changes that list.
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class RouteRegistrationContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances;

    /** @var list<string> */
    private array $requested = [];

    /**
     * @param array<string, object> $preset Real instances to serve verbatim.
     */
    public function __construct(array $preset = [])
    {
        $this->instances = $preset;
    }

    public function get(string $id): mixed
    {
        $this->requested[] = $id;

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!class_exists($id)) {
            throw new RuntimeException(
                'RouteRegistrationContainer was asked for a non-class service id: ' . $id,
            );
        }

        return $this->instances[$id] = (new ReflectionClass($id))->newInstanceWithoutConstructor();
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * Service ids the registrar asked for, in order, de-duplicated.
     *
     * @return list<string>
     */
    public function requestedIds(): array
    {
        return array_values(array_unique($this->requested));
    }
}
