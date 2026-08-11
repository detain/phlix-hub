<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\CoreServicesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CoreServicesProvider}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Container\Providers
 */
final class CoreServicesProviderTest extends TestCase
{
    public function testChannelsReturnsAllLogChannelMappings(): void
    {
        $channels = CoreServicesProvider::channels();

        // Verify all expected channel mappings exist
        $this->assertArrayHasKey('logger.application', $channels);
        $this->assertArrayHasKey('logger.http', $channels);
        $this->assertArrayHasKey('logger.websocket', $channels);
        $this->assertArrayHasKey('logger.database', $channels);
        $this->assertArrayHasKey('logger.auth', $channels);
        $this->assertArrayHasKey('logger.hub', $channels);
        $this->assertArrayHasKey('logger.relay', $channels);
        $this->assertArrayHasKey('logger.audit', $channels);

        // Verify the values match the channel constants
        $this->assertSame('application', $channels['logger.application']);
        $this->assertSame('http', $channels['logger.http']);
        $this->assertSame('websocket', $channels['logger.websocket']);
        $this->assertSame('database', $channels['logger.database']);
        $this->assertSame('auth', $channels['logger.auth']);
        $this->assertSame('hub', $channels['logger.hub']);
        $this->assertSame('relay', $channels['logger.relay']);
        $this->assertSame('audit', $channels['logger.audit']);
    }

    public function testChannelsReturnsArrayWithCorrectCount(): void
    {
        $channels = CoreServicesProvider::channels();

        // We expect 8 channel mappings
        $this->assertCount(8, $channels);
    }

    public function testChannelsReturnsStringKeysOnly(): void
    {
        $channels = CoreServicesProvider::channels();

        foreach (array_keys($channels) as $key) {
            $this->assertIsString($key);
        }
    }

    public function testChannelsReturnsStringValuesOnly(): void
    {
        $channels = CoreServicesProvider::channels();

        foreach ($channels as $value) {
            $this->assertIsString($value);
        }
    }
}
