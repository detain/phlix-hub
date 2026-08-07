<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use InvalidArgumentException;

use function array_keys;
use function in_array;
use function ksort;
use function sprintf;

/**
 * The catalogue of MCP tools the hub exposes, and the single place they are
 * invoked from (S62).
 *
 * Two invariants live here rather than in each tool, so neither can be skipped
 * by a tool that forgets:
 *
 *  1. **Scope enforcement.** {@see call()} refuses the invocation when the
 *     presenting token does not hold {@see McpToolInterface::requiredScope()}.
 *     No tool checks scope itself; there is nowhere for that check to be
 *     omitted.
 *  2. **Identity.** The only thing handed to a tool is the
 *     {@see McpToolContext} built once per request from the validated PAT. The
 *     registry never accepts a user id, so it has none to pass and none to get
 *     wrong.
 *
 * Registration is closed at construction: tools are supplied as a list and the
 * registry has no `add()`. A name collision throws at wiring time rather than
 * silently replacing a tool (which is how a narrow tool becomes a wide one
 * without anybody noticing).
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpToolRegistry
{
    /** @var array<string, McpToolInterface> Tools by machine name. */
    private array $tools = [];

    /**
     * @param list<McpToolInterface> $tools Tools to expose.
     *
     * @throws InvalidArgumentException When two tools claim the same name, or a
     *         tool declares a scope {@see McpScopes} does not know (which would
     *         make it permanently uninvokable — a silently dead tool).
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (isset($this->tools[$name])) {
                throw new InvalidArgumentException(
                    sprintf('Duplicate MCP tool name "%s"; the later registration would replace the earlier.', $name),
                );
            }
            if (!McpScopes::isKnown($tool->requiredScope())) {
                throw new InvalidArgumentException(sprintf(
                    'MCP tool "%s" requires unknown scope "%s"; no token could ever hold it, so the tool '
                    . 'would be permanently uninvokable.',
                    $name,
                    $tool->requiredScope(),
                ));
            }
            $this->tools[$name] = $tool;
        }

        ksort($this->tools);
    }

    /**
     * Tool names, sorted. Exposed for tests and diagnostics.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * The `tools/list` result body: the MCP descriptor for every tool.
     *
     * Every tool is listed regardless of the presenting token's scopes. That is
     * deliberate — an MCP client caches the tool list and a scope-filtered list
     * would make "why can't the model see this tool?" indistinguishable from
     * "the tool was removed". The descriptor names the scope it needs, and a
     * call without it is refused by {@see call()}.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
                // The scope is published TWICE, and that is deliberate (S63).
                //
                // `x-phlix-scope` is S62's spelling and is kept because it is
                // pinned and because a non-SDK client reading the raw JSON finds
                // it there. But a client built on the official MCP SDK never
                // sees it: `ListToolsResultSchema` is a Zod object that STRIPS
                // unrecognised keys, so the field is silently discarded before
                // the model is shown the catalogue — verified by driving the real
                // client. `_meta` is part of the `Tool` schema, so it survives.
                // Without this line the registry's promise that "the descriptor
                // names the scope it needs" is false for exactly the clients that
                // matter most.
                'x-phlix-scope' => $tool->requiredScope(),
                '_meta' => ['phlix/scope' => $tool->requiredScope()],
            ];
        }

        return $out;
    }

    /**
     * Whether a tool of this name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Invoke a tool as the context's token holder.
     *
     * @param string               $name      Tool name from `tools/call`.
     * @param array<string, mixed> $arguments Caller-supplied arguments. Untrusted.
     * @param McpToolContext       $context   Built from the validated PAT.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function call(string $name, array $arguments, McpToolContext $context): array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return [
                'status' => 404,
                'payload' => [
                    'error' => 'Unknown tool',
                    'code' => 'mcp.unknown_tool',
                    'message' => sprintf('No MCP tool named "%s" is registered.', $name),
                ],
            ];
        }

        $required = $tool->requiredScope();
        if (!in_array($required, $context->scopes(), true)) {
            return [
                'status' => 403,
                'payload' => [
                    'error' => 'Forbidden',
                    'code' => 'mcp.scope_denied',
                    'message' => sprintf(
                        'This token does not hold the "%s" scope required by "%s".',
                        $required,
                        $name,
                    ),
                ],
            ];
        }

        return $tool->call($arguments, $context);
    }
}
