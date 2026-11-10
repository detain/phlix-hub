<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

use function in_array;

/**
 * CP-012 (COOKIE-DESIGN §2.5/§4.2, owner ruling CD-8a): the hub's CSRF
 * Origin guard — enforcement shell around {@see
 * CookiePolicy::stateChangingRequestOriginAllowed()}, the twin of the
 * server's ratified validateCsrfOrigin in `RequestAuthenticator`.
 *
 * WHY HERE AND NOT AT A DISPATCH ROW. The server can gate once in
 * `HttpHandler` because its authenticator runs globally: by the time the
 * gate asks `isCookieAuthenticated()` the lineage is already a fact on the
 * request. The hub authenticates PER-ROUTE inside {@see AuthMiddleware}
 * (its Router runs the matched chain, not a global auth pass), so the
 * semantically identical seam is (a) inside AuthMiddleware after the token
 * validated and (b) inside the two auth routes that skip the middleware but
 * consume cookie lineage since CP-011 — POST /auth/refresh and
 * POST /auth/logout. Callers own the LINEAGE decision; this class owns the
 * METHOD limb, the header law and the rejection frame, so the wire shape
 * cannot drift between the two hosts of the guard.
 *
 * The rejection is the server's ratified frame byte-for-byte — 403, compact
 * `{"error":"CSRF validation failed","code":"csrf.invalid_origin"}` (the
 * code is already registered in `openapi.yaml` and the vendored contracts
 * fixture; ZERO new wire codes, estate law) — and, like the server's, it
 * writes no log line: origin refusals are high-volume noise on a public
 * relay front, and the frame itself is the observability.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class CsrfOriginGuard
{
    /**
     * State-changing verbs — the exact list the server's validateCsrfOrigin
     * method limb accepts (OPTIONS/HEAD/GET stay outside it; the hub has no
     * GET-logout route to carve, unlike the server's one form-era path).
     *
     * @var list<string>
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * Apply the Origin law to a COOKIE-LINEAGE request.
     *
     * Returns null when the request passes (wrong verb → exempt by the
     * method limb; same-site headers + no belt → cleared) or a ready 403
     * frame when it must not proceed. LINEAGE SCOPE IS THE CALLER'S: this
     * method must only be reached for requests authenticated by the session
     * cookie — bearer-lineage requests are CSRF-immune by construction and
     * calling this for one would be the regression the guard exists to
     * prevent, not to cause.
     */
    public static function enforce(Request $request): ?Response
    {
        if (!in_array($request->method, self::STATE_CHANGING_METHODS, true)) {
            return null;
        }

        $originNamed = CookiePolicy::stateChangingRequestOriginAllowed(
            $request->getHeader('Origin'),
            $request->getHeader('Referer'),
            $request->getHeader('Host'),
            $request->getHeader('Sec-Fetch-Site'),
        );
        if ($originNamed) {
            return null;
        }

        return (new Response())->status(403)->json([
            'error' => 'CSRF validation failed',
            'code' => 'csrf.invalid_origin',
        ]);
    }
}
