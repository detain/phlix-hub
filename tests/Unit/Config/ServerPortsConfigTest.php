<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Config;

use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use PHPUnit\Framework\TestCase;

/**
 * Guards R14-15: the auxiliary listener ports are REAL config keys now.
 *
 * Before the fix, config/server.php set none of relay_port /
 * client_relay_port / syncplay_port / federation_port / channel_port, so the
 * `?? <literal>` fallbacks at the consumers were the only values the daemon
 * ever bound — configurability theater. Each key is now wired through the
 * $envInt seam with defaults byte-identical to the consumer fallbacks, so an
 * env-less boot binds exactly what it bound before.
 *
 * Also carries the static companion pins of the same lane: the federation
 * construction reads the config key (not the bare const), its skip is logged
 * rather than swallowed (R14-17), and start.php's header tells the truth
 * about the missing public/index.php shim (R3-02).
 */
final class ServerPortsConfigTest extends TestCase
{
    private const PORT_ENVS = [
        'HUB_RELAY_PORT',
        'HUB_CLIENT_RELAY_PORT',
        'HUB_SYNCPLAY_PORT',
        'HUB_FEDERATION_PORT',
        'HUB_CHANNEL_PORT',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::PORT_ENVS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function serverConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/server.php';

        return $config;
    }

    public function testEveryPortKeyDefaultsToTheConsumerFallbackLiteral(): void
    {
        $config = $this->serverConfig();

        // The config side and the consumer-side `?? default` must agree to the
        // byte — an env-less boot changes ports by nobody noticing is exactly
        // the drift this pin kills.
        $this->assertSame(8802, $config['relay_port']);
        $this->assertSame(ClientRelayWorker::DEFAULT_PORT, $config['client_relay_port']);
        $this->assertSame(SyncPlayRelayWorker::DEFAULT_PORT, $config['syncplay_port']);
        $this->assertSame(FederationWorker::DEFAULT_PORT, $config['federation_port']);
        $this->assertSame(RelayProxyProtocol::DEFAULT_CHANNEL_PORT, $config['channel_port']);
    }

    public function testEveryPortKeyIsEnvOverridable(): void
    {
        putenv('HUB_RELAY_PORT=9102');
        putenv('HUB_CLIENT_RELAY_PORT=9103');
        putenv('HUB_SYNCPLAY_PORT=9104');
        putenv('HUB_FEDERATION_PORT=9105');
        putenv('HUB_CHANNEL_PORT=9106');

        $config = $this->serverConfig();

        $this->assertSame(9102, $config['relay_port']);
        $this->assertSame(9103, $config['client_relay_port']);
        $this->assertSame(9104, $config['syncplay_port']);
        $this->assertSame(9105, $config['federation_port']);
        $this->assertSame(9106, $config['channel_port']);
    }

    public function testCoroutineBlockShipsCuratedDefault(): void
    {
        $config = $this->serverConfig();

        /** @var array<string, mixed> $coroutine */
        $coroutine = $config['coroutine'];
        $this->assertTrue($coroutine['enabled']);
        // hook_flags stays UNSET so SwooleRuntime::runtimeHookMask() resolves
        // the curated allowlist; an accidental literal here would silently
        // widen every worker's hook surface.
        $this->assertArrayNotHasKey('hook_flags', $coroutine);
    }

    public function testApplicationSitesReadTheConfigKeysTheyUsedToHardcode(): void
    {
        $app = (string) file_get_contents(__DIR__ . '/../../../src/Application.php');

        foreach (['relay_port', 'client_relay_port', 'syncplay_port', 'channel_port'] as $key) {
            $this->assertStringContainsString(
                "\$this->config['" . $key . "']",
                $app,
                "Application must read the '{$key}' config key (the theater-era fallback alone is not wiring)"
            );
        }
    }

    public function testFederationSiteReadsFederationPortWithConstFallback(): void
    {
        $provider = (string) file_get_contents(
            __DIR__ . '/../../../src/Common/Container/Providers/HubServicesProvider.php'
        );

        $this->assertStringContainsString(
            '$appConfig[\'federation_port\'] ?? FederationWorker::DEFAULT_PORT',
            $provider,
            'the federation worker must take its port from config with the const only as the fallback'
        );
        $this->assertStringContainsString(
            '            $federationWorker = new FederationWorker(
                $container,
                $federationPort,',
            $provider,
            'the parsed $federationPort must be what is actually constructed'
        );
    }

    public function testFederationSkipIsLoggedNotSwallowed(): void
    {
        $provider = (string) file_get_contents(
            __DIR__ . '/../../../src/Common/Container/Providers/HubServicesProvider.php'
        );

        // R14-17: the catch keeps skipping (tests/stripped contexts still
        // lack federation deps) but must leave a visible trace.
        $this->assertStringContainsString(
            "error_log('[phlix-hub] Federation worker skipped: '",
            $provider
        );
        $this->assertStringNotContainsString(
            '} catch (\Throwable) {',
            $provider,
            'the bare argumentless Throwable catch was the silent-swallow shape R14-17 removed'
        );
    }

    public function testStartPhpHeaderTellsTheEntryTruth(): void
    {
        $start = (string) file_get_contents(__DIR__ . '/../../../start.php');

        // R3-02: public/index.php has not existed since the S300 serving-model
        // rewrite — the header must not promise a shim that isn't there.
        $this->assertStringContainsString('ONLY entry point', $start);
        $this->assertStringNotContainsString('kept as a thin shim', $start);
    }
}
