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
 * MCP protocol-revision negotiation (S63).
 *
 * ## What S62 did, and why it was not negotiation
 *
 * S62 answered `initialize` with one fixed revision no matter what the client
 * asked for, and disclosed in `result._meta` that no negotiation had happened.
 * That was honest but it is not the handshake the specification defines: a
 * client that speaks an OLDER revision was told "you got 2025-06-18", which it
 * must then either refuse or mis-parse.
 *
 * ## What negotiation actually is
 *
 * The MCP lifecycle says: the client sends the revision it prefers; the server
 * responds with the **same** revision if it supports it, otherwise with another
 * revision it does support — preferably the latest. The client then either
 * proceeds on the server's answer or disconnects. Nothing in that flow is an
 * error; a mismatch is a downgrade, not a failure.
 *
 * That is exactly {@see negotiate()}: echo when supported, fall back to
 * {@see LATEST} when not. Every revision in {@see SUPPORTED} is one whose
 * `initialize` / `tools/list` / `tools/call` semantics this build genuinely
 * implements — the three methods the hub answers did not change across them.
 * A revision is listed here only when that is TRUE; the list is not a wish.
 *
 * ⚠ Do NOT add a revision here to make a client connect. The list is the
 * hub's promise about wire semantics, and a revision that changed a method
 * this endpoint serves must be implemented before it is claimed.
 *
 * ## The transport header is a SECOND, independent check
 *
 * From revision `2025-06-18` the client must also stamp every subsequent HTTP
 * request with `MCP-Protocol-Version: <negotiated>`, and a server that does not
 * support the stamped revision must answer `400`. That check is
 * {@see isSupported()} applied to the header, and it is deliberately separate
 * from the `initialize` handshake: the handshake negotiates DOWN to something
 * workable, whereas the header asserts a fact about a session already agreed —
 * so silently downgrading there would be re-negotiating behind the client's
 * back.
 *
 * @package Phlix\Hub\Mcp
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
final class McpProtocol
{
    /**
     * The newest revision this build implements, and the fallback offered when
     * a client asks for one that is not in {@see SUPPORTED}.
     */
    public const string LATEST = '2025-06-18';

    /**
     * The revision a request is assumed to be using when it carries no
     * `MCP-Protocol-Version` header at all.
     *
     * The specification names `2025-03-26` for this case (the header did not
     * exist before it, so its absence cannot mean "latest"). Assuming
     * {@see LATEST} instead would silently promote every header-less client.
     */
    public const string ASSUMED_WHEN_HEADER_ABSENT = '2025-03-26';

    /**
     * Every revision this build will speak, newest first.
     *
     * @var list<string>
     */
    public const array SUPPORTED = [
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    /**
     * Whether this build will speak `$version`.
     *
     * @param string $version A revision string as the client spelled it.
     */
    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * Resolve the revision an `initialize` will run under.
     *
     * @param string $requested The `protocolVersion` the client asked for.
     *
     * @return string `$requested` when supported, otherwise {@see LATEST}.
     */
    public static function negotiate(string $requested): string
    {
        return self::isSupported($requested) ? $requested : self::LATEST;
    }
}
