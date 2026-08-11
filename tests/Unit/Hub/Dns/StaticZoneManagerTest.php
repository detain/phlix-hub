<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Dns;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\Dns\StaticZoneManager;

/**
 * Unit tests for {@see StaticZoneManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Dns
 */
final class StaticZoneManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-dns-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testAddRecordCreatesZoneFile(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');

        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        self::assertFileExists($zoneFile);
        self::assertStringContainsString('abc123.phlix.media', file_get_contents($zoneFile));
    }

    public function testAddRecordDoesNotDuplicateSameRecord(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');
        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');

        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        $content = file_get_contents($zoneFile);
        self::assertSame(1, substr_count($content, 'abc123.phlix.media'));
    }

    public function testAddRecordHandlesDifferentRecordTypes(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');
        $manager->addRecord('phlix.media', 'abc123', 'AAAA', '2001:db8::1');
        $manager->addRecord('phlix.media', 'abc123', 'TXT', '"v=spf1 include:_spf.example.com ~all"');

        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        $content = file_get_contents($zoneFile);
        self::assertStringContainsString('IN A 192.0.2.1', $content);
        self::assertStringContainsString('IN AAAA 2001:db8::1', $content);
        self::assertStringContainsString('IN TXT', $content);
    }

    public function testAddRecordThrowsOnInvalidLabel(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNS label must be 1–63 characters');

        // Empty label
        $manager->addRecord('phlix.media', '', 'A', '192.0.2.1');
    }

    public function testAddRecordThrowsOnLabelTooLong(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNS label must be 1–63 characters');

        // 64 character label
        $longLabel = str_repeat('a', 64);
        $manager->addRecord('phlix.media', $longLabel, 'A', '192.0.2.1');
    }

    public function testAddRecordThrowsOnLabelWithInvalidCharacters(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNS label contains invalid characters');

        $manager->addRecord('phlix.media', 'has_underscore', 'A', '192.0.2.1');
    }

    public function testAddRecordThrowsOnLabelStartingWithHyphen(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('starts/ends with a hyphen');

        $manager->addRecord('phlix.media', '-starts', 'A', '192.0.2.1');
    }

    public function testAddRecordThrowsOnLabelEndingWithHyphen(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('starts/ends with a hyphen');

        $manager->addRecord('phlix.media', 'ends-', 'A', '192.0.2.1');
    }

    public function testAddRecordThrowsOnInvalidRecordType(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a recognised standard type');

        $manager->addRecord('phlix.media', 'abc123', 'INVALID', 'value');
    }

    public function testAddRecordThrowsOnEmptyRecordType(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be 1–10 characters');

        $manager->addRecord('phlix.media', 'abc123', '', 'value');
    }

    public function testAddRecordThrowsOnRecordTypeTooLong(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be 1–10 characters');

        $manager->addRecord('phlix.media', 'abc123', '12345678901', 'value');
    }

    public function testAddRecordThrowsOnValueWithNewline(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not contain newline characters');

        $manager->addRecord('phlix.media', 'abc123', 'TXT', "value\nwith\nnewlines");
    }

    public function testAddRecordThrowsOnValueWithCarriageReturn(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not contain newline characters');

        $manager->addRecord('phlix.media', 'abc123', 'TXT', "value\rwith\rnewlines");
    }

    public function testRemoveRecordDeletesMatchingLine(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');
        $manager->removeRecord('phlix.media', 'abc123', 'A');

        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        self::assertStringNotContainsString('abc123.phlix.media', file_get_contents($zoneFile));
    }

    public function testRemoveRecordHandlesNonExistentZone(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        // Should not throw and should not create any file
        $manager->removeRecord('nonexistent.media', 'abc123', 'A');
        self::assertFalse(file_exists($this->tmpDir . '/nonexistent.media.zone'));
    }

    public function testRemoveRecordHandlesNonExistentRecord(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        $manager->addRecord('phlix.media', 'abc123', 'A', '192.0.2.1');
        // Should not throw and should leave the existing record untouched
        $manager->removeRecord('phlix.media', 'nonexistent', 'A');
        self::assertStringContainsString('abc123.phlix.media', file_get_contents($this->tmpDir . '/phlix.media.zone'));
    }

    public function testUpdateSoaUpdatesSerialInExistingZone(): void
    {
        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        $initialContent = <<<ZONE
@ 300 IN SOA ns1.example.com. admin.example.com. 2026010101 3600 900 604800 86400
@ 300 IN NS ns1.example.com.
ZONE;
        file_put_contents($zoneFile, $initialContent);

        $manager = new StaticZoneManager($this->tmpDir);
        $manager->updateSoa('phlix.media');

        $content = file_get_contents($zoneFile);
        self::assertStringContainsString('IN SOA', $content);
        // Serial should have been updated
        self::assertStringContainsString('2026', $content);
    }

    public function testUpdateSoaHandlesNonExistentZone(): void
    {
        $manager = new StaticZoneManager($this->tmpDir);

        // Should not throw and should not create any file
        $manager->updateSoa('nonexistent.media');
        self::assertFalse(file_exists($this->tmpDir . '/nonexistent.media.zone'));
    }

    public function testUpdateSoaHandlesZoneWithoutSoa(): void
    {
        $zoneFile = $this->tmpDir . '/phlix.media.zone';
        file_put_contents($zoneFile, "@ 300 IN NS ns1.example.com.\n");

        $manager = new StaticZoneManager($this->tmpDir);
        $manager->updateSoa('phlix.media');

        // Should not throw, just leave content unchanged
        self::assertStringContainsString('NS', file_get_contents($zoneFile));
    }
}
