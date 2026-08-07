<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolInterface;

/**
 * A real {@see McpToolInterface} implementation that records the `$arguments`
 * map it was handed, and nothing else.
 *
 * ## Why this exists
 *
 * `McpController::stringKeyed()` drops non-string (positional) keys from the
 * JSON-RPC `params` / `arguments` maps before they reach a tool. Nothing
 * downstream of that filter can observe whether it ran: every shipped tool reads
 * its arguments BY NAME ({@see \Phlix\Hub\Mcp\McpArguments}), so an extra `0 =>
 * "…"` entry changes no shipped tool's answer. Mutation M24 (delete the filter)
 * therefore survived the whole suite.
 *
 * The observable consequence of the filter is what a tool RECEIVES, so that is
 * what this records. It is registered in a real {@see
 * \Phlix\Hub\Mcp\McpToolRegistry} and invoked through the real
 * `POST /mcp` dispatcher, at the exact seam the shipped tools occupy — so the
 * assertion is "a positional key does not reach the tool boundary", proven by
 * standing at that boundary, not "the private helper returns a string-keyed
 * array" measured in isolation.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class RecordingMcpTool implements McpToolInterface
{
    /**
     * The argument map handed to {@see call()}, or `null` if it never ran.
     *
     * Deliberately typed `array<array-key, mixed>` rather than
     * `array<string, mixed>`: the point of the assertions against it is to
     * detect an INTEGER key arriving, and a narrower type here would be a claim
     * about the very thing under test.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $received = null;

    /** How many times {@see call()} ran. */
    public int $calls = 0;

    /**
     * The payload {@see call()} answers with.
     *
     * Settable so a test can drive the EMPTY case. That is not a curiosity: an
     * empty payload is what `McpController::toolResult()` must render as
     * `"structuredContent": {}` rather than `[]`, and PHP's single array type
     * means no decoding assertion can tell those two apart — see
     * `McpControllerTest::test_an_empty_tool_payload_encodes_as_a_json_object()`.
     *
     * @var array<string, mixed>
     */
    public array $payload = ['recorded' => true];

    public function name(): string
    {
        return 'recording_probe';
    }

    public function description(): string
    {
        return 'Test-only probe that records the arguments map it is handed.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function requiredScope(): string
    {
        return McpScopes::SERVERS_READ;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function call(array $arguments, McpToolContext $context): array
    {
        ++$this->calls;
        $this->received = $arguments;

        return ['status' => 200, 'payload' => $this->payload];
    }
}
