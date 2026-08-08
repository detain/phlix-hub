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
}
