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
        $envKeys = [
            'HUB_JWT_SECRET',
            'APP_ENV',
            'HUB_ENV',
            'HUB_JWT_ACCESS_TTL',
            'HUB_JWT_REFRESH_TTL',
            AuthServicesProvider::DEV_SECRET_ENV_FLAG,
        ];
        foreach ($envKeys as $key) {
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
     * REGRESSION (Phase 6 → live production): `HUB_JWT_ACCESS_TTL` must
     * actually change the lifetime of a minted access token.
     *
     * Phase 6 renamed `config/auth.php`'s `access_ttl` to `access_token_ttl`
     * to make an orphaned settings key resolve. The only consumer,
     * {@see AuthServicesProvider::register()}, still read `access_ttl`, and
     * its `intOr()` helper falls back to a hardcoded literal when the key is
     * missing — so the env var was silently ignored on every deployed hub and
     * nothing failed.
     *
     * This test therefore asserts the CONSEQUENCE, end to end through the REAL
     * `config/auth.php`: a token minted by the container-resolved handler must
     * expire `HUB_JWT_ACCESS_TTL` seconds after it was issued. Asserting only
     * that a config key exists, or that `getAccessTtl()` echoes a constructor
     * argument, would not have caught the regression.
     */
    public function testAccessTtlEnvVarReachesTheMintedToken(): void
    {
        putenv('HUB_JWT_SECRET=' . str_repeat('S', 40));
        putenv('APP_ENV=production');
        putenv('HUB_JWT_ACCESS_TTL=4321');

        $container = $this->buildContainer(['auth_config_path' => $this->realAuthConfigPath()]);
        $handler = $container->get(JwtHandler::class);

        self::assertSame(4321, $handler->getAccessTtl(), 'expires_in must honour HUB_JWT_ACCESS_TTL');

        $claims = $handler->validateAccessToken($handler->createAccessToken('user-ttl'));
        self::assertNotNull($claims);
        self::assertSame(4321, $claims->exp - $claims->iat, 'minted access token exp must honour the env TTL');
    }

    /**
     * Sibling of {@see testAccessTtlEnvVarReachesTheMintedToken()} for the
     * refresh TTL, which the same rename disabled.
     */
    public function testRefreshTtlEnvVarReachesTheMintedToken(): void
    {
        putenv('HUB_JWT_SECRET=' . str_repeat('S', 40));
        putenv('APP_ENV=production');
        putenv('HUB_JWT_REFRESH_TTL=98765');

        $container = $this->buildContainer(['auth_config_path' => $this->realAuthConfigPath()]);
        $handler = $container->get(JwtHandler::class);

        self::assertSame(98765, $handler->getRefreshTtl());

        $claims = $handler->validateRefreshToken($handler->createRefreshToken('user-ttl'));
        self::assertNotNull($claims);
        self::assertSame(98765, $claims->exp - $claims->iat, 'minted refresh token exp must honour the env TTL');
    }

    /**
     * With no env override, the shipped defaults must still come through the
     * real config file — proving the previous two tests are exercising the
     * config path rather than accidentally hitting a hardcoded literal that
     * happens to agree.
     */
    public function testTtlsFallBackToTheShippedConfigDefaults(): void
    {
        putenv('HUB_JWT_SECRET=' . str_repeat('S', 40));
        putenv('APP_ENV=production');

        $container = $this->buildContainer(['auth_config_path' => $this->realAuthConfigPath()]);
        $handler = $container->get(JwtHandler::class);

        self::assertSame(3600, $handler->getAccessTtl());
        self::assertSame(604800, $handler->getRefreshTtl());
    }

    /**
     * A hub setting override must beat the config default at MINT time (no
     * restart) — this is what keeps the schema's `restart: false` flag honest
     * for `auth.access_ttl` / `auth.refresh_ttl`.
     *
     * The resolver itself is exercised directly here (the container wiring
     * builds one over the live ConnectionPool, which is unavailable in a unit
     * test); {@see \Phlix\Hub\Auth\JwtHandler} is the contract under test.
     */
    public function testAnOverrideResolverBeatsTheConfigDefaultWithoutRestart(): void
    {
        $handler = new JwtHandler(
            str_repeat('S', 40),
            'phlix-hub',
            'hub',
            3600,
            604800,
            static fn (string $key, int $fallback): int => $key === 'auth.access_ttl' ? 1800 : $fallback,
        );

        self::assertSame(1800, $handler->getAccessTtl());
        self::assertSame(604800, $handler->getRefreshTtl(), 'unrelated key must keep its config default');

        $claims = $handler->validateAccessToken($handler->createAccessToken('user-live'));
        self::assertNotNull($claims);
        self::assertSame(1800, $claims->exp - $claims->iat);
    }

    /**
     * The resolver is best-effort: if the settings store is unreachable, token
     * minting must still succeed on the boot-time TTL rather than 500.
     */
    public function testResolverFailureDegradesToTheConfigDefault(): void
    {
        $handler = new JwtHandler(
            str_repeat('S', 40),
            'phlix-hub',
            'hub',
            3600,
            604800,
            static function (string $key, int $fallback): int {
                throw new \RuntimeException('db down');
            },
        );

        self::assertSame(3600, $handler->getAccessTtl());
        self::assertSame(604800, $handler->getRefreshTtl());
    }

    /** Absolute path to the shipped `config/auth.php`. */
    private function realAuthConfigPath(): string
    {
        return dirname(__DIR__, 5) . '/config/auth.php';
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
