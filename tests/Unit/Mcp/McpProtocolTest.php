<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Http\Controllers\McpController;
use Phlix\Hub\Mcp\McpProtocol;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function array_values;
use function count;
use function preg_match;

/**
 * Unit tests for {@see McpProtocol} (S63).
 *
 * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\McpControllerTest} already drives
 * negotiation end-to-end through `initialize`. This file asserts the things a
 * behaviour test cannot: that the revision LIST is internally coherent, and that
 * the single copy of the version string really is single.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpProtocol
 */
final class McpProtocolTest extends TestCase
{
    /**
     * The list is non-empty, duplicate-free and newest-first — the order
     * {@see McpProtocol::LATEST} claims to head.
     */
    public function test_the_supported_list_is_coherent(): void
    {
        $supported = McpProtocol::SUPPORTED;

        self::assertNotSame([], $supported, 'ANTI-VACUITY: the hub claims to speak no revision at all.');
        self::assertSame(
            $supported,
            array_values(array_unique($supported)),
            'a duplicate revision means one of them is unreachable in a preference-ordered list.',
        );
        self::assertSame(
            McpProtocol::LATEST,
            $supported[0],
            'LATEST must head the list: it is the fallback offered on a downgrade, and offering a '
            . 'revision that is not the newest supported one loses capability for no reason.',
        );
        self::assertGreaterThan(
            1,
            count($supported),
            'with a single supported revision, negotiation cannot be distinguished from the fixed '
            . 'answer S62 gave.',
        );
    }

    /**
     * Every entry is a real MCP revision date, not a marketing string. This is
     * the promise the list makes to a client, and a malformed entry would be a
     * revision no client ever sends — i.e. a silent no-op.
     */
    public function test_every_supported_revision_is_a_date_stamp(): void
    {
        foreach (McpProtocol::SUPPORTED as $revision) {
            self::assertSame(
                1,
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $revision),
                $revision . ' is not an MCP revision date-stamp.',
            );
        }
    }

    /**
     * The revision assumed for a header-less request must itself be one the hub
     * speaks, or the assumption is incoherent: the hub would be assuming a
     * revision it would then 400 if the client stated it explicitly.
     */
    public function test_the_header_absent_assumption_is_itself_supported(): void
    {
        self::assertTrue(McpProtocol::isSupported(McpProtocol::ASSUMED_WHEN_HEADER_ABSENT));
    }

    /**
     * The controller constant is an ALIAS, not a second literal.
     *
     * Two copies of a version string is how a negotiator ends up disagreeing
     * with the thing it negotiates for — the endpoint would advertise one
     * revision and accept another.
     */
    public function test_the_controller_constant_is_the_same_value(): void
    {
        self::assertSame(McpProtocol::LATEST, McpController::PROTOCOL_VERSION);
    }

    /**
     * @dataProvider negotiationProvider
     */
    public function test_negotiate_echoes_what_it_supports_and_downgrades_the_rest(
        string $requested,
        string $expected,
    ): void {
        self::assertSame($expected, McpProtocol::negotiate($requested));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function negotiationProvider(): array
    {
        return [
            'the latest' => ['2025-06-18', '2025-06-18'],
            'an older supported one' => ['2024-11-05', '2024-11-05'],
            'the middle one' => ['2025-03-26', '2025-03-26'],
            'unknown' => ['1999-01-01', McpProtocol::LATEST],
            'empty' => ['', McpProtocol::LATEST],
            // Near-misses: the check must be equality, never substring.
            'a supported one with a suffix' => ['2025-06-18-rc1', McpProtocol::LATEST],
            'a supported one truncated' => ['2025-06', McpProtocol::LATEST],
            'a supported one with whitespace' => [' 2025-06-18', McpProtocol::LATEST],
        ];
    }
}
