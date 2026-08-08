<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

/**
 * The RFC 6749 error codes this Authorization Server emits (S92).
 *
 * Named constants rather than string literals scattered through the controller,
 * because these strings are a WIRE CONTRACT: a client library matches on them
 * exactly, and a typo (`invalid_grants`, `unsupported_grant`) produces an error
 * that no client can classify — it degrades into a generic failure with no
 * remediation path. A constant makes the typo a fatal one at the point it is
 * written instead of a silent one at the point it is read.
 *
 * The codes are deliberately COARSE, and that is the specification's intent,
 * not an omission on our part. `invalid_grant` covers "no such code", "expired
 * code", "already-redeemed code", "code belongs to another client", "redirect
 * URI does not match" and "PKCE verification failed" — all six, with the same
 * string. Distinguishing them on the wire would tell an attacker holding a
 * stolen code exactly which of its six bindings they still need to satisfy.
 * The `error_description` is likewise kept non-diagnostic; the specific reason
 * goes to the server log, where only an operator can read it.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 * @link    https://www.rfc-editor.org/rfc/rfc6749#section-4.1.2.1
 * @link    https://www.rfc-editor.org/rfc/rfc6749#section-5.2
 */
final class OAuthError
{
    /** A required parameter is missing, repeated, or malformed. */
    public const string INVALID_REQUEST = 'invalid_request';

    /** Client authentication failed, or the client is unknown/disabled. HTTP 401. */
    public const string INVALID_CLIENT = 'invalid_client';

    /**
     * The grant presented is invalid, expired, revoked, does not match the
     * redirect URI, or was issued to another client. See the class docblock for
     * why this one code covers so much ground.
     */
    public const string INVALID_GRANT = 'invalid_grant';

    /** The client is not permitted to use this grant/response type. */
    public const string UNAUTHORIZED_CLIENT = 'unauthorized_client';

    /** The resource owner (or this server) refused the request. */
    public const string ACCESS_DENIED = 'access_denied';

    /** The `response_type` is not one this server supports. */
    public const string UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';

    /** The `grant_type` is not one this server supports. */
    public const string UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';

    /** The requested scope is unknown, malformed, or exceeds the client's ceiling. */
    public const string INVALID_SCOPE = 'invalid_scope';

    /** An unexpected condition prevented the request from being fulfilled. */
    public const string SERVER_ERROR = 'server_error';

    /**
     * RFC **6750** §3.1 — the access token presented to a PROTECTED RESOURCE is
     * malformed, expired, revoked, or of the wrong kind. HTTP 401 (S286).
     *
     * ⚠ A different registry from the constants above. Everything before this
     * point is RFC 6749 §5.2, emitted by the Authorization Server's own
     * endpoints; this and {@see INSUFFICIENT_SCOPE} are Bearer-token errors
     * emitted by a RESOURCE server ({@see \Phlix\Hub\Http\Middleware\OAuthResourceMiddleware})
     * and appear in the `WWW-Authenticate` header as well as the body. They live
     * in the same class because a client parses both off the same wire, and a
     * second constants file would be one more place for a typo to hide.
     */
    public const string INVALID_TOKEN = 'invalid_token';

    /**
     * RFC 6750 §3.1 — the access token is VALID but does not carry the scope the
     * resource requires. HTTP **403**, never 401 (S286).
     *
     * The distinction is deliberate and is asserted by the suite: a 403 here
     * proves the credential authenticated and that the SCOPE check is what
     * refused it. Collapsing both onto 401 would make "the scope gate works"
     * indistinguishable from "the token was rejected for some other reason" —
     * and a refusal that cannot be attributed is not evidence of a gate.
     */
    public const string INSUFFICIENT_SCOPE = 'insufficient_scope';
}
