<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use function in_array;

/**
 * The identity a validated MCP personal access token resolves to (S62).
 *
 * Deliberately immutable and deliberately WITHOUT a mutator for `$userId`. This
 * object is the ONLY thing {@see \Phlix\Hub\Http\Controllers\McpController}
 * carries from PAT validation into {@see McpToolContext}, and the tool layer
 * never sees a user id it could substitute — so "the request runs as the token's
 * user" is a structural property, not a convention a tool author has to
 * remember.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpToken
{
    /**
     * @param string       $id     `mcp_tokens.id` of the row this token came from.
     * @param string       $userId Hub user UUID the token authenticates as.
     * @param list<string> $scopes Known scopes the token holds (see {@see McpScopes}).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly array $scopes,
    ) {
    }

    /**
     * Whether this token carries `$scope`.
     *
     * @param string $scope One of the {@see McpScopes} constants.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
