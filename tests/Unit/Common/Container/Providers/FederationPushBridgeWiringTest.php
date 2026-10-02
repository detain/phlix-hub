<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationMasterPusher;
use Phlix\Hub\Federation\FederationPushBridge;
use Phlix\Hub\Federation\FederationPushDispatcher;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The per-worker-singleton law for the federation push bridge, pinned against
 * the REAL provider graph (same technique as
 * {@see AlexaSkillControllerWiringTest}'s pending-command pins).
 *
 * ## The silent-permanent failure this catches
 *
 * `FederationPushBridge` owns a UNIQUE reply event and the in-flight map keyed
 * against it; `Application::boot()`'s HTTP `onWorkerStart` subscribes exactly
 * ONE instance's `replyEvent()` to the broker. If the request path resolved a
 * DIFFERENT instance, its commands would carry a reply event nobody is
 * subscribed to, wait out the timeout, and report not-delivered — for every
 * share create/revoke on the hub, forever, with nothing in the logs naming the
 * cause. A comment in HubServicesProvider states the hazard; this is the check.
 *
 * Also pinned: the pusher is handed the SAME bridge (not a second instance),
 * and the :8805-side dispatcher resolves against the SAME connection manager
 * the WS callbacks stamp — otherwise the verified gate would read an empty map
 * in the very process that owns the truth.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class FederationPushBridgeWiringTest extends TestCase
{
    use LoggerFactoryIsolation;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-fed-push-wiring-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @rmdir($this->tmpDir);
    }

    private function buildContainer(): Container
    {
        $appConfig = [];

        $builder = new ContainerBuilder();
        (new HubServicesProvider())->register($builder, $appConfig);
        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
        ]);

        return $builder->build();
    }

    public function testTheBridgeIsOnePerWorkerAndItsReplyEventIsStable(): void
    {
        $container = $this->buildContainer();

        $first = $container->get(FederationPushBridge::class);
        $second = $container->get(FederationPushBridge::class);

        self::assertInstanceOf(FederationPushBridge::class, $first);
        self::assertInstanceOf(FederationPushBridge::class, $second);
        self::assertSame(
            $first,
            $second,
            'FederationPushBridge resolved to TWO different instances. The HTTP onWorkerStart '
            . 'subscribes one instance\'s replyEvent() to the broker, so the other one\'s commands '
            . 'are answered on an event nobody listens to: every master push times out to '
            . 'not-delivered, permanently and silently.',
        );
        self::assertSame(
            $first->replyEvent(),
            $second->replyEvent(),
            'the reply event differs between resolves — the subscription made at worker start would '
            . 'not be the event the request path publishes against',
        );

        // Control: the identity above is a property of the BINDING, not of the
        // class — two `new`s must NOT share a reply event, or the equality
        // asserted above measures nothing.
        $independent = new FederationPushBridge(
            new \Phlix\Hub\Common\Logger\StructuredLogger('fed-push-wiring-test', []),
        );
        self::assertNotSame(
            $first->replyEvent(),
            $independent->replyEvent(),
            'control: two independently constructed bridges share a reply event',
        );
    }

    public function testThePusherIsHandedTheContainerBridgeInstance(): void
    {
        $container = $this->buildContainer();

        $pusher = $container->get(FederationMasterPusher::class);
        self::assertInstanceOf(FederationMasterPusher::class, $pusher);

        $prop = new ReflectionProperty(FederationMasterPusher::class, 'bridge');
        $prop->setAccessible(true);
        /** @var mixed $bridge */
        $bridge = $prop->getValue($pusher);

        self::assertInstanceOf(
            FederationPushBridge::class,
            $bridge,
            'the container-built pusher must carry the bridge — a null there leaves the HTTP '
            . 'worker on the historical dead-end (warn+false forever)',
        );
        self::assertSame(
            $container->get(FederationPushBridge::class),
            $bridge,
            'the pusher holds a DIFFERENT bridge than the bound singleton — its commands would be '
            . 'answered on an event nobody subscribes to',
        );
    }

    public function testTheDispatcherSharesTheContainerConnectionManager(): void
    {
        $container = $this->buildContainer();

        $dispatcher = $container->get(FederationPushDispatcher::class);
        self::assertInstanceOf(FederationPushDispatcher::class, $dispatcher);

        $prop = new ReflectionProperty(FederationPushDispatcher::class, 'connMgr');
        $prop->setAccessible(true);
        /** @var mixed $connMgr */
        $connMgr = $prop->getValue($dispatcher);

        self::assertSame(
            $container->get(FederationConnectionManager::class),
            $connMgr,
            'the dispatcher must gate on the SAME FederationConnectionManager the WS callbacks '
            . 'register and stamp in this process — a second instance reads an empty map and every '
            . 'bridged push would refuse "channel not verified" forever',
        );
    }

    public function testThePusherStillSharesTheSameLocalConnectionManager(): void
    {
        $container = $this->buildContainer();

        $pusher = $container->get(FederationMasterPusher::class);
        self::assertInstanceOf(FederationMasterPusher::class, $pusher);

        $prop = new ReflectionProperty(FederationMasterPusher::class, 'connMgr');
        $prop->setAccessible(true);
        /** @var mixed $connMgr */
        $connMgr = $prop->getValue($pusher);

        self::assertSame($container->get(FederationConnectionManager::class), $connMgr);
        self::assertInstanceOf(FederationHubRepository::class, $container->get(FederationHubRepository::class));
    }
}
