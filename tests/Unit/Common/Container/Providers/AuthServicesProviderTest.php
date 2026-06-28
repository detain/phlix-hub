<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Container\MissingJwtSecretException;
use Phlix\Hub\Common\Container\Providers\AuthServicesProvider;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the S9 fail-fast-on-missing-JWT-secret behaviour of
 * {@see AuthServicesProvider::resolveSecret()}: a real secret is honoured
 * verbatim, a missing secret in production throws, and the insecure random
 * fallback only happens (with a loud warning) in an explicitly-allowed dev
 * environment.
 *
 * The warning is captured via the provider's test seam so no output leaks
 * under PHPUnit's strict output checking.
 */
#[CoversClass(AuthServicesProvider::class)]
final class AuthServicesProviderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['HUB_JWT_SECRET', 'APP_ENV', 'HUB_ENV', AuthServicesProvider::DEV_SECRET_ENV_FLAG] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->warnings = [];
        AuthServicesProvider::setDevFallbackWarner(function (string $message): void {
            $this->warnings[] = $message;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        AuthServicesProvider::setDevFallbackWarner(null);
        parent::tearDown();
    }

    public function testExplicitSecretIsReturnedVerbatim(): void
    {
        $secret = str_repeat('S', 40);
        putenv('HUB_JWT_SECRET=' . $secret);
        putenv('APP_ENV=production');

        $container = $this->buildContainer([]);

        // A token minted by the resolved handler must verify with a handler
        // built from the same explicit secret — proving the secret was used
        // verbatim and is stable (not random per build).
        $resolved = $container->get(JwtHandler::class);
        $token = $resolved->createAccessToken('user-1');
        $reference = new JwtHandler($secret);
        self::assertNotNull($reference->validateToken($token));
        self::assertSame([], $this->warnings, 'no dev warning when a real secret is set');
    }

    public function testMissingSecretInProductionThrows(): void
    {
        putenv('APP_ENV=production');

        $this->expectException(MissingJwtSecretException::class);
        $this->buildContainer([]);
    }

    public function testMissingSecretWithNoEnvAtAllThrows(): void
    {
        // No APP_ENV/HUB_ENV/flag set → treated as production → throw.
        $this->expectException(MissingJwtSecretException::class);
        $this->buildContainer([]);
    }

    public function testDevEnvAllowsRandomFallbackAndWarns(): void
    {
        putenv('APP_ENV=local');

        $container = $this->buildContainer([]);

        $handler = $container->get(JwtHandler::class);
        self::assertInstanceOf(JwtHandler::class, $handler);
        self::assertCount(1, $this->warnings, 'dev fallback must log exactly one loud warning');
        self::assertStringContainsString('insecure random', $this->warnings[0]);
    }

    public function testExplicitDevFlagAllowsRandomFallbackInProductionEnv(): void
    {
        // Even with a prod-looking APP_ENV, the explicit opt-in flag enables
        // the dev fallback (for the rare local-over-prod-config case).
        putenv('APP_ENV=production');
        putenv(AuthServicesProvider::DEV_SECRET_ENV_FLAG . '=1');

        $container = $this->buildContainer([]);

        self::assertInstanceOf(JwtHandler::class, $container->get(JwtHandler::class));
        self::assertCount(1, $this->warnings);
    }

    public function testConfigSecretIsUsedWhenEnvAbsent(): void
    {
        $secret = str_repeat('C', 40);
        $configPath = $this->writeAuthConfig(['secret' => $secret]);
        putenv('APP_ENV=production');

        $container = $this->buildContainer(['auth_config_path' => $configPath]);

        $resolved = $container->get(JwtHandler::class);
        $token = $resolved->createAccessToken('user-2');
        self::assertNotNull((new JwtHandler($secret))->validateToken($token));
        self::assertSame([], $this->warnings);

        unlink($configPath);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig): \DI\Container
    {
        $builder = new ContainerBuilder();
        // The RateLimiter binding lives in CommonServicesProvider; provide a
        // minimal stub so AuthManager (not under test here) can still resolve
        // if touched. JwtHandler resolution does not need it.
        $builder->addDefinitions([
            RateLimiterInterface::class => \DI\autowire(RateLimiter::class),
        ]);
        (new AuthServicesProvider())->register($builder, $appConfig);

        return $builder->build();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeAuthConfig(array $config): string
    {
        $path = sys_get_temp_dir() . '/phlix-hub-auth-config-' . uniqid() . '.php';
        file_put_contents($path, '<?php return ' . var_export($config, true) . ';');
        return $path;
    }
}
