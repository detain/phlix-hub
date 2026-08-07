<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Container;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Substitutes ONE entry — `Workerman\MySQL\Connection` — in an otherwise
 * completely real container.
 *
 * ## Why not simply build a stub container
 *
 * 🔴 S269 was a container-wiring defect: `AuditLogger` was bound with an
 * explicit `factory()` closure that passed one of its two constructor
 * arguments, so its `?AuditLogRepository` was `null` in production forever.
 * **Nothing that hand-constructs the class, and nothing that stubs the binding,
 * can see that** — the argument list only exists in the provider. A test for it
 * has to resolve the class out of the same provider stack production uses, which
 * means every other binding must stay untouched.
 *
 * The one thing a test cannot inherit from production is the database
 * connection: {@see \Phlix\Hub\Common\Container\Providers\CoreServicesProvider}
 * builds it from the static {@see \Phlix\Hub\Common\Database\ConnectionPool},
 * whose `init()` writes a process-global config path with no reset — under
 * `executionOrder="random"` that would leak into every sibling suite in the same
 * PHPUnit process. So this provider is appended AFTER the real stack (PHP-DI
 * lets a later definition win) and hands back the test's own connection to the
 * `HUB_TEST_DB_*` schema. Every other definition — including the
 * `AuditLogger` binding under test — is the production one.
 *
 * @package Phlix\Hub\Tests\Support\Container
 */
final class FixedConnectionProvider implements ServiceProviderInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $db = $this->db;

        $builder->addDefinitions([
            Connection::class => factory(static fn (): Connection => $db),
        ]);
    }
}
