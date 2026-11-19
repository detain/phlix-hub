<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use JsonException;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Shared\Settings\SettingsCodec;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HubSettingsRepository}.
 *
 * The M-32 suite pins the v0.57 codec adoption: the retired private arms
 * are byte-paired against the shared kernel across the whole grammar
 * (the HUB-JSON-F14 legacy-pin idiom), the kernel keeps the hub's strict
 * posture against the server's silent variant, and the twins' absence
 * from the class is held by tripwires.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class HubSettingsRepositoryTest extends TestCase
{
    private function repo(Connection $db, ?string $configDir = null): HubSettingsRepository
    {
        return new HubSettingsRepository($db, $configDir);
    }

    /** A connection whose store holds no override rows at all. */
    private function noRowsDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        return $db;
    }

    // ------------------------------------------------------------ getOverride

    public function testGetOverrideReturnsDecodedValueWhenRowExists(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '3600', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('auth.access_ttl');

        self::assertNotNull($result);
        self::assertSame(3600, $result['value']);
        self::assertSame('int', $result['value_type']);
    }

    public function testGetOverrideReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db);
        self::assertNull($repo->getOverride('auth.access_ttl'));
    }

    public function testGetOverrideDecodesBoolTrue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '1', 'value_type' => 'bool'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.bool_key');

        self::assertNotNull($result);
        self::assertTrue($result['value']);
    }

    public function testGetOverrideDecodesBoolFalse(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '0', 'value_type' => 'bool'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.bool_key');

        self::assertNotNull($result);
        self::assertFalse($result['value']);
    }

    public function testGetOverrideDecodesFloat(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '3.14', 'value_type' => 'float'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.float_key');

        self::assertNotNull($result);
        self::assertSame(3.14, $result['value']);
    }

    public function testGetOverrideDecodesJson(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => '{"foo":"bar"}', 'value_type' => 'json'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.json_key');

        self::assertNotNull($result);
        self::assertSame(['foo' => 'bar'], $result['value']);
    }

    public function testGetOverrideDefaultsToStringType(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_value' => 'hello', 'value_type' => null],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getOverride('some.string_key');

        self::assertNotNull($result);
        self::assertSame('hello', $result['value']);
        self::assertSame('string', $result['value_type']);
    }

    // --------------------------------------------------------- getAllOverrides

    public function testGetAllOverridesReturnsKeyedMap(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_key' => 'auth.access_ttl', 'setting_value' => '3600', 'value_type' => 'int'],
            ['setting_key' => 'auth.refresh_ttl', 'setting_value' => '86400', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getAllOverrides();

        self::addToAssertionCount(1);
        self::assertSame(3600, $result['auth.access_ttl']);
        self::assertSame(86400, $result['auth.refresh_ttl']);
    }

    public function testGetAllOverridesReturnsEmptyArrayWhenNoRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db);
        self::assertSame([], $repo->getAllOverrides());
    }

    public function testGetAllOverridesSkipsMalformedRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['setting_key' => 'auth.access_ttl', 'setting_value' => '3600', 'value_type' => 'int'],
            ['setting_key' => null, 'setting_value' => 'x', 'value_type' => 'string'], // malformed
            ['setting_key' => 'auth.refresh_ttl', 'setting_value' => '86400', 'value_type' => 'int'],
        ]);

        $repo = $this->repo($db);
        $result = $repo->getAllOverrides();

        self::assertCount(2, $result);
        self::assertArrayHasKey('auth.access_ttl', $result);
        self::assertArrayHasKey('auth.refresh_ttl', $result);
    }

    // --------------------------------------------------------------- set

    public function testSetUsesUpsert(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringStartsWith('INSERT INTO hub_settings'),
                self::isArray()
            );

        $repo = $this->repo($db);
        $repo->set('auth.access_ttl', 3600, 'int');
    }

    // ----------------------------------------------------------- getDefault

    public function testGetDefaultReadsFromConfigFile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // no overrides

        // Use realpath to get correct config path from test file location
        $configDir = realpath(__DIR__ . '/../../../config');
        self::assertNotFalse($configDir, 'config directory should exist');
        $repo = $this->repo($db, $configDir);
        $result = $repo->getDefault('auth.access_ttl');

        // auth.php has access_ttl defined - value depends on env vars
        self::assertNotNull($result);
    }

    public function testGetDefaultReturnsNullForUnknownKey(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault('nonexistent.file.key'));
    }

    public function testGetDefaultReturnsNullForUnknownFile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault('unknownfile.some_key'));
    }

    public function testGetDefaultReturnsNullForEmptyKey(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        self::assertNull($repo->getDefault(''));
    }

    public function testGetDefaultNavigatesNestedKeys(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        // server.php has nested 'arr' key with 'sonarr' containing 'api_key' (empty string in dev config)
        $result = $repo->getDefault('server.arr.sonarr.api_key');
        // The nested navigation works - value is empty string from config
        self::assertSame('', $result);
    }

    // --------------------------------------------------------- getEffective

    public function testGetEffectiveReturnsOverrideWhenPresent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['setting_value' => '7200', 'value_type' => 'int']], // getOverride call
        );

        $repo = $this->repo($db);
        $result = $repo->getEffective('auth.access_ttl');

        self::assertSame(7200, $result);
    }

    public function testGetEffectiveFallsBackToDefaultWhenNoOverride(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [], // getOverride returns null (no override)
        );

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $result = $repo->getEffective('auth.access_ttl');

        // Should fall back to config default
        self::assertNotNull($result);
    }

    // ----------------------------------------------------- getEffectiveMany

    public function testGetEffectiveManyReturnsValuesAndOverriddenList(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [ // getAllOverrides
                ['setting_key' => 'auth.access_ttl', 'setting_value' => '7200', 'value_type' => 'int'],
            ],
        );

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $keys = ['auth.access_ttl', 'auth.refresh_ttl'];
        $result = $repo->getEffectiveMany($keys);

        self::assertArrayHasKey('values', $result);
        self::assertArrayHasKey('overridden', $result);
        self::assertSame(7200, $result['values']['auth.access_ttl']);
        self::assertContains('auth.access_ttl', $result['overridden']);
        self::assertNotContains('auth.refresh_ttl', $result['overridden']);
    }

    public function testGetEffectiveManyReturnsDefaultsWhenNotOverridden(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]); // no overrides

        $repo = $this->repo($db, __DIR__ . '/../../../config');
        $keys = ['auth.access_ttl'];
        $result = $repo->getEffectiveMany($keys);

        self::assertSame([], $result['overridden']);
        self::assertArrayHasKey('auth.access_ttl', $result['values']);
    }

    // -------------------------------------------------------- ALLOWED_KEYS

    public function testAllowedKeysContainsExpectedEntries(): void
    {
        self::assertArrayHasKey('server.enrollment_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.access_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.refresh_ttl', HubSettingsRepository::ALLOWED_KEYS);
        self::assertArrayHasKey('auth.signups_disabled', HubSettingsRepository::ALLOWED_KEYS);
    }

    /**
     * Live signup toggle, precedence leg 1: with NO `hub_settings` row the
     * effective value falls through to `config/auth.php`, which derives it
     * from HUB_SIGNUPS_ENABLED — the exact behavior the boot-time-only flag
     * had before the live gate existed.
     */
    public function testSignupsDisabledFallsBackToEnvDerivedDefaultWhenNoRow(): void
    {
        $previous = getenv('HUB_SIGNUPS_ENABLED');
        try {
            putenv('HUB_SIGNUPS_ENABLED=false');
            $closed = $this->repo($this->noRowsDb(), __DIR__ . '/../../../config')
                ->getEffective('auth.signups_disabled');
            self::assertTrue($closed, 'HUB_SIGNUPS_ENABLED=false must read as signups_disabled=true');

            putenv('HUB_SIGNUPS_ENABLED=true');
            $open = $this->repo($this->noRowsDb(), __DIR__ . '/../../../config')
                ->getEffective('auth.signups_disabled');
            self::assertFalse($open, 'HUB_SIGNUPS_ENABLED=true must read as signups_disabled=false');
        } finally {
            if ($previous === false) {
                putenv('HUB_SIGNUPS_ENABLED');
            } else {
                putenv('HUB_SIGNUPS_ENABLED=' . $previous);
            }
        }
    }

    /**
     * Live signup toggle, precedence leg 2: the stored override row wins over
     * whatever the env said — this is the whole point of the live toggle
     * (flip it from the admin UI, effective on the next request).
     */
    public function testSignupsDisabledOverrideRowBeatsEnvDefault(): void
    {
        $previous = getenv('HUB_SIGNUPS_ENABLED');
        try {
            putenv('HUB_SIGNUPS_ENABLED=true'); // env says OPEN

            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturn([
                ['setting_value' => '1', 'value_type' => 'bool'],
            ]);

            $effective = $this->repo($db, __DIR__ . '/../../../config')
                ->getEffective('auth.signups_disabled');
            self::assertTrue($effective, 'an override row must close signups even when env opens them');
        } finally {
            if ($previous === false) {
                putenv('HUB_SIGNUPS_ENABLED');
            } else {
                putenv('HUB_SIGNUPS_ENABLED=' . $previous);
            }
        }
    }

    public function testDeniedKeysContainsSecrets(): void
    {
        self::assertContains('auth.secret', HubSettingsRepository::DENIED_KEYS);
        self::assertContains('server.hub_base_url', HubSettingsRepository::DENIED_KEYS);
        self::assertContains('server.tls_enabled', HubSettingsRepository::DENIED_KEYS);
    }

    // ------------------------------------------------- M-32 codec adoption pins

    /**
     * The full value_type vocabulary of the storage grammar: the five named
     * types plus the fall-through shapes the default arm owes identical
     * answers on (unknown spellings included).
     */
    private const CODEC_TYPES = ['string', 'int', 'bool', 'float', 'json', '', 'unknown', 'BOOL', 'Json'];

    /**
     * `HubSettingsRepository`'s private encode() arm verbatim, as it shipped
     * until v0.57 leg M-32 (deleted from the class; reproduced here the way
     * SharedJsonDelegationTest keeps its legacy bodies). Golden bytes are
     * pinned against the kernel — never against an implementation.
     *
     * @param mixed $value Value to encode.
     */
    private static function retiredHubEncode(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'bool'  => ($value ? '1' : '0'),
            'int'   => (string) (int) (is_numeric($value) ? $value : 0),
            'float' => (string) (float) (is_numeric($value) ? $value : 0),
            'json'  => json_encode($value, JSON_THROW_ON_ERROR),
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /** `HubSettingsRepository`'s private decode() arm verbatim (pre M-32). */
    private static function retiredHubDecode(string $raw, string $valueType): mixed
    {
        return match ($valueType) {
            'bool'  => $raw === '1' || strtolower($raw) === 'true',
            'int'   => (int) $raw,
            'float' => (float) $raw,
            'json'  => json_decode($raw, true),
            default => $raw,
        };
    }

    /**
     * Outcome tag for one codec call: the result's var_export, or the
     * exception class and message. Any other Throwable is a bug and must
     * surface as a test error, so only JsonException is caught here.
     *
     * @param callable(): mixed $call
     *
     * @return array{0: string, 1: string}
     */
    private static function codecOutcome(callable $call): array
    {
        try {
            return ['ok', var_export($call(), true)];
        } catch (JsonException $e) {
            return ['throw', get_class($e) . ':' . $e->getMessage()];
        }
    }

    public function testTheKernelIsByteIdenticalToTheRetiredHubEncodeArms(): void
    {
        $values = [
            'true' => true,
            'false' => false,
            'int0' => 0,
            'int1' => 1,
            'intneg' => -2,
            'str0' => '0',
            'str1' => '1',
            'abc' => 'abc',
            'empty' => '',
            'float' => 2.7,
            'strFloat' => '2.7',
            'strJunkNum' => '2.7x',
            'null' => null,
            'emptyArr' => [],
            'list' => [1, 2],
            'map' => ['a' => 1, 'b' => 'テスト'],
            'nested' => ['x' => ['y' => [true, null]]],
            'obj' => new \stdClass(),
            'infMap' => ['v' => INF],
            'badUtf8' => "\xB1\x31",
            'intMax' => PHP_INT_MAX,
            'sciStr' => '-1.5e3',
        ];

        $cases = 0;
        foreach (self::CODEC_TYPES as $type) {
            foreach ($values as $name => $value) {
                $cases++;
                $legacy = self::codecOutcome(
                    static fn(): string => self::retiredHubEncode($value, $type)
                );
                $kernel = self::codecOutcome(
                    static fn(): string => SettingsCodec::encode($value, $type)
                );

                self::assertSame(
                    $legacy[0] . '|' . $legacy[1],
                    $kernel[0] . '|' . $kernel[1],
                    "encode(type={$type}, value={$name}) drifted across the M-32 adoption."
                );
            }
        }

        self::assertGreaterThanOrEqual(180, $cases, 'corpus shrank — the parity proof is going vacuous');
    }

    public function testTheKernelIsByteIdenticalToTheRetiredHubDecodeArms(): void
    {
        $raws = [
            '1', '0', 'true', 'TRUE', 'False', 'yes', '5', '-3', '007', '5.7', '2e3',
            'abc', '', '{"a":1}', '[1,2]', 'null', 'garbage{', '{"a":}', 'é', '1e999', '0.0',
        ];

        $cases = 0;
        foreach (self::CODEC_TYPES as $type) {
            foreach ($raws as $raw) {
                $cases++;
                $legacy = self::codecOutcome(
                    static fn(): mixed => self::retiredHubDecode($raw, $type)
                );
                $kernel = self::codecOutcome(
                    static fn(): mixed => SettingsCodec::decode($raw, $type)
                );

                self::assertSame(
                    $legacy[0] . '|' . $legacy[1],
                    $kernel[0] . '|' . $kernel[1],
                    "decode(type={$type}) drifted across the M-32 adoption."
                );
            }
        }

        self::assertGreaterThanOrEqual(180, $cases, 'corpus shrank — the parity proof is going vacuous');
    }

    /**
     * Risk-seam R1: the kernel ships the HUB's strict posture, never the
     * server's silent-'' variant. If a future kernel refactor folds the
     * strictness back out, this pin reddens and the server leg — not the
     * hub — is where the harmonization has to happen.
     */
    public function testTheServerSilentVariantIsTheDivergenceTheKernelRefuses(): void
    {
        $unencodable = ['v' => INF];
        self::assertSame('', (string) json_encode($unencodable), 'silent variant renders empty');

        $thrown = null;
        try {
            SettingsCodec::encode($unencodable, 'json');
        } catch (JsonException $e) {
            $thrown = $e;
        }
        self::assertInstanceOf(JsonException::class, $thrown, 'kernel must keep the strict json arm');
    }

    public function testTheRepositoryNoLongerDeclaresTheRetiredCodecArms(): void
    {
        $names = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(HubSettingsRepository::class))->getMethods()
        );

        self::assertNotContains('encode', $names, 'retired private encode arm re-materialised');
        self::assertNotContains('decode', $names, 'retired private decode arm re-materialised');
    }

    public function testTheRepositorySourceDelegatesEveryCodecSiteToTheKernel(): void
    {
        $file = (new ReflectionClass(HubSettingsRepository::class))->getFileName();
        self::assertIsString($file, 'reflection must locate the repository source');
        $src = file_get_contents((string) $file);
        self::assertIsString($src);

        self::assertSame(
            2,
            substr_count($src, 'SettingsCodec::decode($raw, $type)'),
            'both decode sites must call the kernel'
        );
        self::assertSame(
            1,
            substr_count($src, 'SettingsCodec::encode($value, $valueType)'),
            'the set() seam must call the kernel'
        );
        self::assertStringNotContainsString('self::encode', $src, 'no self::encode may survive');
        self::assertStringNotContainsString('self::decode', $src, 'no self::decode may survive');
        self::assertStringNotContainsString('private static function encode', $src);
        self::assertStringNotContainsString('private static function decode', $src);
    }

    public function testTheEncodeThrowCrossesTheSetSeamBeforeAnyWrite(): void
    {
        /** @var list<string> $issued */
        $issued = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($sql) use (&$issued): int {
                $issued[] = (string) $sql;

                return 1;
            }
        );

        try {
            $this->repo($db)->set('meta.test', ['v' => INF], 'json');
            self::fail('unencodable json must not write silently through set()');
        } catch (JsonException $e) {
            self::assertSame([], $issued, 'the kernel throw must fire before the upsert');
        }
    }
}
