<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaRejectionAuditorInterface;
use Phlix\Hub\Alexa\AuditLogAlexaRejectionAuditor;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use Phlix\Hub\Common\Container\Providers\CommonServicesProvider;
use Phlix\Hub\Common\Container\Providers\HttpServicesProvider;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Http\Controllers\AlexaSkillController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\SyncPlay\ChannelPendingCommandPusher;
use Phlix\Hub\SyncPlay\PendingCommandPusherInterface;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function count;
use function is_object;

/**
 * S91 — the defect this suite catches in the container wiring.
 *
 * **A dependency that is silently `null`.** PHP-DI's `autowire()` SKIPS optional
 * constructor parameters, so a collaborator with a default quietly stays at its
 * default; this estate has been bitten by it more than once, and nothing except a
 * container-resolution test can see it. `AlexaSkillController` is bound with an
 * explicit `factory()` for exactly that reason, and this suite is what holds the
 * binding to it — including the plain `string $hubBaseUrl`, which is the one
 * parameter a resolution error would NOT report (an empty string resolves
 * perfectly and produces a play link pointing at `/app/search?q=…` with no
 * origin: a URL that resolves nowhere, spoken to a user as if it did).
 *
 * The property list is DERIVED from the constructor by reflection rather than
 * hand-written, so a parameter added by a later step is covered without anybody
 * remembering to extend this test — the shape of gap that made the check
 * necessary in the first place.
 *
 * The container is the REAL one: the four production service providers, plus a
 * `Connection` double because the auditor pulls in `AuditLogRepository` and that
 * is a database boundary a unit suite has no business owning. Nothing else is
 * stubbed — in particular `CommonServicesProvider` is registered rather than
 * faked, so the production `rate_limiter.alexa` binding is what gets exercised.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class AlexaSkillControllerWiringTest extends TestCase
{
    use LoggerFactoryIsolation;

    private const HUB_BASE_URL = 'https://hub.alexa-wiring.test';

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-alexa-skill-wiring-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        // A real >=32-byte secret so JwtHandler resolves without the insecure
        // dev fallback (which would also make this suite env-dependent).
        file_put_contents(
            $this->tmpDir . '/auth.php',
            "<?php return ['secret' => 'alexa-skill-wiring-secret-0123456789abcdef'];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @unlink($this->tmpDir . '/auth.php');
        @rmdir($this->tmpDir);
    }

    // ------------------------------------------------------------------
    // AlexaSkillController
    // ------------------------------------------------------------------

    public function testTheSkillControllerResolvesWithEveryConstructorPropertyPopulated(): void
    {
        $controller = $this->buildContainer()->get(AlexaSkillController::class);
        self::assertInstanceOf(AlexaSkillController::class, $controller);

        // Derived, not hand-listed: a parameter added later is covered here
        // without anyone remembering to update the list.
        $parameters = (new \ReflectionClass(AlexaSkillController::class))->getConstructor()?->getParameters() ?? [];

        self::assertGreaterThanOrEqual(
            5,
            count($parameters),
            'AlexaSkillController was expected to carry at least the five collaborators S91 shipped. '
            . 'Finding fewer means the reflection is not seeing the constructor, and every assertion '
            . 'below is measuring nothing.',
        );

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $property = new ReflectionProperty(AlexaSkillController::class, $name);

            self::assertTrue(
                $property->isInitialized($controller),
                'AlexaSkillController::$' . $name . ' was never assigned by the container factory',
            );

            /** @var mixed $value */
            $value = $property->getValue($controller);
            self::assertNotNull(
                $value,
                'AlexaSkillController::$' . $name . ' resolved to null — the classic PHP-DI '
                . 'skipped-optional-parameter defect',
            );

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if (!class_exists($typeName) && !interface_exists($typeName)) {
                    self::fail(
                        sprintf('parameter type "%s" is neither an autoloadable class nor an interface', $typeName),
                    );
                }
                self::assertTrue(is_object($value));
                self::assertInstanceOf($typeName, $value);
            }
        }
    }

    /**
     * The concrete types, named. "Not null" would be satisfied by a stand-in;
     * the whole point of handing the skill the PRODUCTION proxy controller is
     * that its ownership and browse-scope gates come with it.
     */
    public function testTheSkillControllerGetsTheProductionCollaboratorsByType(): void
    {
        $controller = $this->buildContainer()->get(AlexaSkillController::class);
        self::assertInstanceOf(AlexaSkillController::class, $controller);

        self::assertInstanceOf(AlexaAccountLink::class, self::readPrivate($controller, 'accountLink'));
        self::assertInstanceOf(
            ServerProxyController::class,
            self::readPrivate($controller, 'proxy'),
            'the skill must be handed the PRODUCTION proxy controller, or its ownership, quota, '
            . 'traversal and browse-scope gates are not inherited at all',
        );
        self::assertInstanceOf(ServerListController::class, self::readPrivate($controller, 'serverList'));
        self::assertInstanceOf(StructuredLogger::class, self::readPrivate($controller, 'logger'));
    }

    /**
     * `hubBaseUrl` is a plain string, so a broken binding produces no resolution
     * error at all — just a link with no origin.
     */
    public function testTheHubBaseUrlIsANonEmptyStringTakenFromTheApplicationConfig(): void
    {
        $configured = $this->buildContainer()->get(AlexaSkillController::class);
        self::assertInstanceOf(AlexaSkillController::class, $configured);

        /** @var mixed $url */
        $url = self::readPrivate($configured, 'hubBaseUrl');
        self::assertIsString($url);
        self::assertNotSame('', $url, 'an empty hub base url makes every play link origin-less');
        self::assertSame(self::HUB_BASE_URL, $url, 'the configured hub_base_url must reach the controller');

        // And with NOTHING configured it must still be a usable origin rather
        // than an empty string — the default is part of the contract.
        $defaulted = $this->buildContainer([])->get(AlexaSkillController::class);
        self::assertInstanceOf(AlexaSkillController::class, $defaulted);
        /** @var mixed $fallback */
        $fallback = self::readPrivate($defaulted, 'hubBaseUrl');
        self::assertIsString($fallback);
        self::assertNotSame('', $fallback);
    }

    public function testTheAccountLinkGetsBothOfItsDependencies(): void
    {
        $container = $this->buildContainer();
        $link = $container->get(AlexaAccountLink::class);
        self::assertInstanceOf(AlexaAccountLink::class, $link);

        self::assertInstanceOf(JwtHandler::class, self::readPrivate($link, 'jwt'));
        self::assertInstanceOf(UserRepository::class, self::readPrivate($link, 'users'));

        // The SAME handler the rest of the hub validates with — a second
        // instance with a different secret would silently reject every linked
        // account.
        self::assertSame($container->get(JwtHandler::class), self::readPrivate($link, 'jwt'));
    }

    /**
     * S93 — the pending-command pusher, by CONCRETE type.
     *
     * "Not null" would be satisfied by any stand-in. The controller's confirmation
     * is gated on a real delivered count, and only the channel implementation can
     * produce one: anything else would report a number nobody measured, or 0
     * forever.
     */
    public function testThePendingCommandPusherResolvesToTheChannelImplementation(): void
    {
        $container = $this->buildContainer();

        $pusher = $container->get(PendingCommandPusherInterface::class);
        self::assertInstanceOf(
            ChannelPendingCommandPusher::class,
            $pusher,
            'PendingCommandPusherInterface must resolve to the channel-backed pusher — it is the only '
            . 'implementation that can cross into the :8804 process where the client sockets live',
        );

        $skillController = $container->get(AlexaSkillController::class);
        self::assertInstanceOf(AlexaSkillController::class, $skillController);

        self::assertSame(
            $pusher,
            self::readPrivate($skillController, 'pendingCommands'),
            'the skill controller must be handed the SAME pusher instance the container binds, not a '
            . 'second one whose reply event nobody subscribes to',
        );
    }

    /**
     * S93 — ⚠ THE PER-WORKER-SINGLETON HAZARD, pinned.
     *
     * {@see ChannelPendingCommandPusher} owns a UNIQUE reply event and the map of
     * in-flight pushes keyed against it, and `Application::run()`'s HTTP
     * `onWorkerStart` subscribes exactly ONE instance's `replyEvent()` to the
     * channel broker. If a second resolve produced a different object, the request
     * path would publish a push carrying a reply event nobody is subscribed to,
     * wait out the timeout, and report 0 delivered — **for every user, every
     * time, permanently and silently**. The skill would speak "you do not have the
     * Phlix app open" forever and no log line would say why.
     *
     * `HubServicesProvider` states that hazard in a comment. A comment is not a
     * check, and this is the check.
     */
    public function testThePendingCommandPusherIsOnePerWorkerAndItsReplyEventIsStable(): void
    {
        $container = $this->buildContainer();

        $first = $container->get(PendingCommandPusherInterface::class);
        $second = $container->get(PendingCommandPusherInterface::class);

        self::assertSame(
            $first,
            $second,
            'PendingCommandPusherInterface resolved to TWO different instances. Application::run() '
            . 'subscribes one instance\'s replyEvent() to the broker, so the other one\'s pushes are '
            . 'answered to an event nobody listens on: every push times out and reports 0 delivered, '
            . 'and every user is told they have no app open, forever, with nothing in the logs.',
        );

        self::assertInstanceOf(ChannelPendingCommandPusher::class, $first);
        self::assertInstanceOf(ChannelPendingCommandPusher::class, $second);
        self::assertSame(
            $first->replyEvent(),
            $second->replyEvent(),
            'the reply event differs between resolves — the subscription made at worker start would '
            . 'not be the event the request path publishes against',
        );

        // Control: the identity above is a property of the BINDING, not of the
        // class. Two separately constructed pushers must NOT share a reply event,
        // otherwise "the events match" would be true however the container behaved.
        $independent = new ChannelPendingCommandPusher(new StructuredLogger('alexa-wiring-test', []));
        self::assertNotSame(
            $first->replyEvent(),
            $independent->replyEvent(),
            'control: two independently constructed pushers share a reply event, so the equality '
            . 'asserted above says nothing about the container binding',
        );
    }

    /**
     * The controller holds no per-request state, and a per-request instance
     * would rebuild the whole proxy graph on every Alexa utterance inside a
     * resident worker.
     */
    public function testTheSkillControllerIsASingleton(): void
    {
        $container = $this->buildContainer();

        self::assertSame(
            $container->get(AlexaSkillController::class),
            $container->get(AlexaSkillController::class),
        );
    }

    // ------------------------------------------------------------------
    // AlexaSignatureMiddleware's two S91 additions, by CONCRETE identity
    // ------------------------------------------------------------------

    /**
     * `AlexaSignatureMiddlewareWiringTest` already asserts these two are not
     * null and satisfy their interfaces. What it cannot say is WHICH instance:
     * a limiter that is not the `rate_limiter.alexa` profile would silently
     * share (or not share) a budget with another surface, and an auditor that is
     * not the audit-log one would record rejections nowhere.
     */
    public function testTheGatesLimiterIsTheAlexaProfileAndItsAuditorWritesToTheAuditLog(): void
    {
        $container = $this->buildContainer();
        $middleware = $container->get(AlexaSignatureMiddleware::class);
        self::assertInstanceOf(AlexaSignatureMiddleware::class, $middleware);

        self::assertSame(
            $container->get(RateLimitProfiles::ALEXA),
            self::readPrivate($middleware, 'rateLimiter'),
            'the gate must use the rate_limiter.alexa profile, not some other surface\'s bucket',
        );

        $auditor = self::readPrivate($middleware, 'auditor');
        self::assertInstanceOf(AlexaRejectionAuditorInterface::class, $auditor);
        self::assertInstanceOf(
            AuditLogAlexaRejectionAuditor::class,
            $auditor,
            'a rejection auditor that does not reach audit_logs records nothing an operator can read',
        );
        self::assertSame($container->get(AlexaRejectionAuditorInterface::class), $auditor);
    }

    // ------------------------------------------------------------------
    // Container
    // ------------------------------------------------------------------

    /**
     * The real providers. `hub_base_url` is passed explicitly so the assertion
     * about it reaching the controller is about the WIRING rather than about a
     * default nobody set.
     *
     * @param array<string, string>|null $extraConfig Null uses the suite default.
     */
    private function buildContainer(?array $extraConfig = null): Container
    {
        $appConfig = [
            'auth_config_path' => $this->tmpDir . '/auth.php',
        ] + ($extraConfig ?? ['hub_base_url' => self::HUB_BASE_URL]);

        $builder = new ContainerBuilder();
        (new CommonServicesProvider())->register($builder, $appConfig);
        (new AuthServicesProvider())->register($builder, $appConfig);
        (new HttpServicesProvider())->register($builder, $appConfig);
        (new HubServicesProvider())->register($builder, $appConfig);
        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
        ]);

        return $builder->build();
    }

    private static function readPrivate(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
