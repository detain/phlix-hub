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
use Phlix\Shared\Security\OriginPolicy;

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
 * The METHOD limb is the shared kernel's law ({@see
 * OriginPolicy::stateChangingMethod()}, phlix-shared v0.56.0 — the verb
 * list this guard used to hand-carry, byte-identical into the kernel). The
 * header law still rides hub {@see CookiePolicy} verbatim; folding that
 * class onto the same kernel is the recorded follow-on leg (the kernel's
 * docblock enumerates the exact method-by-method mapping).
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class CsrfOriginGuard
{
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
        if (!OriginPolicy::stateChangingMethod($request->method)) {
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

        return (new Response())->error(403, 'csrf.invalid_origin', 'CSRF validation failed');
    }
}
