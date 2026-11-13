<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Closure;
use Throwable;

/**
 * Single source of truth for the hub's auth-cookie policy
 * (COOKIE-DESIGN.md §2.1/§2.4/§2.5, owner rulings CD-3a/CD-8a; the semantic
 * twin of the server's `Phlix\Auth\CookiePolicy` — same law, hub codebase).
 * Answers four independent questions — three issuance, one request law:
 *
 *  1. CLASS — is this request browser-class (may receive HttpOnly cookies) or
 *     device-class (bearer-only, NO Set-Cookie ever)? Device marker wins:
 *     `X-Phlix-Device-Type` present, or an Origin that is NOT a same-site
 *     http(s) origin (app://, capacitor://, foreign web host), classifies the
 *     request device-side (§2.1 discriminator, contracts COOKIE_MODE law).
 *     Unlike the server there is NO form-era limb here: the hub retired its
 *     SSR form UI with Smarty, so its ONLY browser signal is a same-site
 *     http(s) `Origin` (the same-origin /app SPA's fetch) or
 *     `Sec-Fetch-Site: same-origin`. Requests with no browser signal at all
 *     (curl, mobile/console/rok/tizen/windows natives) default to
 *     device-class — exactly today's JSON bearer-only posture, byte-ident.
 *  2. MASTER SWITCH — `auth.cookie.enabled` (shared schema, landed @2683579):
 *     false means the hub sets no auth cookie on ANY issuance path (§7
 *     rollback law). Read live through the canonical {@see
 *     HubSettingsResolvers::bool()} idiom (override row, else `auth.cookie.*`
 *     config twins, else the shipped default); fail-OPEN to enabled, because
 *     a settings outage must never strand the working browser login. The hub
 *     reads the same key names as the server but NEVER re-declares the
 *     schema — phlix-shared owns it.
 *  3. SCHEME LAW — cookies are issued only when the request arrived over
 *     https or addressed localhost, or the operator opted into insecure
 *     cookies (`HUB_COOKIE_INSECURE=1|true` env — the name the hub's
 *     pre-existing Secure-resolver already shipped — or its stored twin
 *     `auth.cookie.insecure`, which ALSO drops the Secure attribute; there is
 *     never a silent Secure downgrade onto plain HTTP).
 *  4. REQUEST LAW (CP-012) — a state-changing request that authenticated via
 *     the session cookie must NAME this origin:
 *     {@see stateChangingRequestOriginAllowed()} is the pure law the
 *     {@see \Phlix\Hub\Http\Middleware\CsrfOriginGuard} enforces, the hub
 *     twin of the server's ratified `validateSameSiteOrigin` plus the §2.5
 *     `Sec-Fetch-Site: cross-site` reject belt. It lives on this class
 *     because custody and request-validation are the SAME axis — both read
 *     Origin/Referer/Host/Sec-Fetch — and one SSOT owning both is what keeps
 *     the issuance classification and the guard from ever disagreeing about
 *     what "same-site" means to this deployment.
 *
 * CSRF posture, ratified (§2.5, CP-011→CP-012 pair): `SameSite=Lax` is the
 * SHIPPED and FINAL attribute — deliberately NOT tightened to `Strict` now
 * that the Origin guard exists. Lax keeps the top-level GET navigations the
 * SPA legitimately performs (deep links, reloads with an expired access
 * token) authenticated; the state-changing attack surface it cannot cover
 * (cross-site POST) is exactly the surface the guard convicts, so the belt
 * and the attribute are complementary, not redundant. A `Strict` cookie
 * would buy nothing the guard does not already enforce and would strand
 * same-tab navigations arriving from an external page.
 *
 * Cookie NAMES are the hub's legacy-surface names (`phlix_hub_token` /
 * `phlix_hub_refresh`, per the deletion record in the AuthMiddleware history)
 * deliberately DISTINCT from the server's `phlix_session`/`phlix_refresh`:
 * server and hub deploy behind one hostname on different ports, and cookies
 * are port-agnostic — sharing names would let the two sessions clobber each
 * other in one jar.
 *
 * The read path (AuthMiddleware's session-cookie fallback, refresh/logout
 * accepting a presented refresh cookie) is intentionally NOT gated by the
 * master switch — mirroring the server's RequestAuthenticator law: presented
 * artifacts are validated, never re-adjudicated by the toggle that governs
 * ISSUANCE. The CP-012 Origin guard rides that same ungating (toggling
 * issuance OFF must never leave a still-authenticated jar UNGUARDED).
 * Logout's cookie CLEAR also rides ungated: clearing is revocation
 * hygiene, not credential issuance.
 *
 * @package Phlix\Hub\Hub
 */
final class CookiePolicy
{
    /** Shared-schema master switch (phlix-shared 2683579 era; hub read-through). */
    public const COOKIE_ENABLED_KEY = 'auth.cookie.enabled';

    /** Shared-schema stored form of the HUB_COOKIE_INSECURE opt-in. */
    public const COOKIE_INSECURE_KEY = 'auth.cookie.insecure';

    /** Cookie custody class — mirrors contracts COOKIE_MODE.COOKIE (src/auth.ts). */
    public const MODE_COOKIE = 'cookie';

    /** Bearer custody class — mirrors contracts COOKIE_MODE.BEARER (src/auth.ts). */
    public const MODE_BEARER = 'bearer';

    /** Session (access) cookie name — the hub's deleted-surface name, revived. */
    public const SESSION_COOKIE = 'phlix_hub_token';

    /** Refresh cookie name — the hub's deleted-surface name, revived. */
    public const REFRESH_COOKIE = 'phlix_hub_refresh';

    /** Env opt-in to LAN-readable cookies (accepts the same '1'/'true' as Response's Secure-resolver). */
    public const INSECURE_ENV = 'HUB_COOKIE_INSECURE';

    /** Hostnames that satisfy the §2.4 localhost limb (any port). */
    private const LOCALHOST_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * @param Closure():bool|null $enabledResolver  Live master-switch read
     *        (injectable for tests; defaults to the canonical shared-key
     *        resolver). Fail-open: any absence or failure reads as enabled.
     * @param Closure():bool|null $insecureResolver Live stored-twin read for
     *        the insecure opt-in (env form is read directly). Fail-closed.
     */
    public function __construct(
        private readonly ?Closure $enabledResolver = null,
        private readonly ?Closure $insecureResolver = null,
    ) {
    }

    /**
     * Master switch: when false the hub sets no auth cookie anywhere (§7
     * rollback law). Anything other than an explicit `false` means enabled —
     * the stored default is true and an unreachable store must not strand
     * the working browser login.
     */
    public function cookieModeEnabled(): bool
    {
        try {
            return ($this->enabledResolver ?? HubSettingsResolvers::bool(self::COOKIE_ENABLED_KEY, true))();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Explicit operator opt-in to LAN-readable cookies: the env switch (the
     * name the hub's shipped Secure-resolver already honours) or its stored
     * twin. True = the scheme gate is bypassed AND the Secure attribute is
     * dropped. Never enabled by default, never inferred from anything else
     * (CD-3a: no silent Secure downgrade).
     */
    public function insecureOverride(): bool
    {
        $env = getenv(self::INSECURE_ENV);
        if ($env === '1' || $env === 'true') {
            return true;
        }

        try {
            return ($this->insecureResolver ?? HubSettingsResolvers::bool(self::COOKIE_INSECURE_KEY, false))();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether issued cookies carry the `Secure` attribute — the inverse of
     * the override. A `Secure` cookie over plain HTTP would never be stored
     * (silent breakage), so the override drops the attribute together with
     * the scheme gate; that IS the documented LAN-sniffable posture the
     * operator opted into.
     */
    public function cookiesSecure(): bool
    {
        return !$this->insecureOverride();
    }

    /**
     * §2.4 scheme law for a browser-class request: https per the first hop of
     * X-Forwarded-Proto (the HAProxy front is the hub's only TLS terminator),
     * or a localhost/127.0.0.1/::1 Host (dev boxes, any port), or the
     * explicit insecure override. A spoofed `X-Forwarded-Proto: https` on a
     * plain-HTTP box only yields a `Secure` cookie a plain-HTTP browser
     * refuses to store: fail-safe.
     */
    public function schemeEligible(?string $forwardedProto, ?string $hostHeader): bool
    {
        if ($this->insecureOverride()) {
            return true;
        }

        if (self::isHttpsForwarded($forwardedProto)) {
            return true;
        }

        return self::isLocalhostHost($hostHeader);
    }

    /**
     * The §2.1 device-class discriminator over raw header values. Device
     * marker present → bearer. An Origin that is present but not a same-site
     * http(s) origin (foreign web host, app://, capacitor://) → bearer.
     * Otherwise a same-site Origin or `Sec-Fetch-Site: same-origin` marks the
     * request browser-class; with no signal at all the request stays bearer,
     * which is today's JSON posture for every legacy native client.
     */
    public function custodyMode(
        ?string $deviceTypeHeader,
        ?string $originHeader,
        ?string $hostHeader,
        ?string $secFetchSite,
    ): string {
        if (is_string($deviceTypeHeader) && $deviceTypeHeader !== '') {
            return self::MODE_BEARER;
        }

        if (is_string($originHeader) && $originHeader !== '' && !self::originIsSameSite($originHeader, $hostHeader)) {
            return self::MODE_BEARER;
        }

        $browserClass = (is_string($originHeader) && $originHeader !== '')
            || (is_string($secFetchSite) && strtolower($secFetchSite) === 'same-origin');

        return $browserClass ? self::MODE_COOKIE : self::MODE_BEARER;
    }

    /**
     * First-hop https test on an X-Forwarded-Proto value (chains append:
     * "https, http" — the client-facing hop wins; only https|http are read).
     */
    public static function isHttpsForwarded(?string $forwardedProto): bool
    {
        if (!is_string($forwardedProto) || $forwardedProto === '') {
            return false;
        }

        $first = strtolower(trim(explode(',', $forwardedProto)[0]));

        return $first === 'https';
    }

    /**
     * Hostname limb of the §2.4 localhost rule: exact-name match after
     * lowercasing and :port strip (bracketed IPv6 literals kept whole).
     */
    public static function isLocalhostHost(?string $hostHeader): bool
    {
        if (!is_string($hostHeader) || $hostHeader === '') {
            return false;
        }

        return in_array(self::hostOf($hostHeader), self::LOCALHOST_HOSTS, true);
    }

    /**
     * Whether an Origin header is a same-site http(s) origin against the
     * request Host: http/https scheme only (app://, capacitor://, file:// are
     * device webview vaults — bearer per §2.1), and the host:port pair
     * matches after default-port normalization.
     */
    public static function originIsSameSite(string $origin, ?string $hostHeader): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $originHost = strtolower($parts['host'] ?? '');
        $originHost = str_starts_with($originHost, '[') && str_ends_with($originHost, ']')
            ? substr($originHost, 1, -1)
            : $originHost;
        if ($originHost === '') {
            return false;
        }

        $requestHost = self::hostOf((string) $hostHeader);
        if ($originHost !== $requestHost) {
            return false;
        }

        $hostParts = parse_url('//' . (string) $hostHeader);
        $originPort = $parts['port'] ?? self::defaultPort($scheme);
        $requestPort = (is_array($hostParts) ? ($hostParts['port'] ?? null) : null) ?? self::defaultPort($scheme);

        return $originPort === $requestPort;
    }

    /**
     * CP-012 (COOKIE-DESIGN §2.5/§4.2): the fourth question this SSOT answers
     * — REQUEST VALIDATION, the read-side twin of the issuance laws above.
     * Whether a state-changing request that authenticated via the session
     * cookie (lineage scope is the CALLER's decision; see
     * {@see \Phlix\Hub\Http\Middleware\CsrfOriginGuard::enforce()}) names
     * this server as its origin. The law is the server's ratified shape in
     * `RequestAuthenticator::validateSameSiteOrigin()`, adopted semantically:
     *
     *  - the §2.5 design-ADD belt first: a present `Sec-Fetch-Site:
     *    cross-site` refuses outright — an honest UA reporting a cross-site
     *    context is refused even behind a forged same-host Origin, the one
     *    hole the header law alone cannot see (re-checked HUB-S51 against
     *    phlix-server `Auth\CookiePolicy::stateChangingRequestOriginAllowed()`:
     *    the server has since grown the same belt, arm order identical, so
     *    the two laws now agree arm for arm);
     *  - no Origin AND no Referer → refuse (fail-closed; browsers always
     *    send at least one on a same-site state-changing request, and
     *    cookie-lineage requests only ever come from browsers);
     *  - the literal `null` Origin → refuse (M-SERVER-76 opaque-origin
     *    marker, checked before either header leg);
     *  - every PRESENT header leg must name the Host exactly: parsed hosts
     *    compare RAW (case-sensitive — the fail-closed direction the server
     *    ratified) with the suffix-bypass law (`evil-hub`, `hub…evil` both
     *    die), and ports must both-be-present-and-equal or both-be-absent.
     *
     * Deliberately NOT shared with {@see originIsSameSite()}: that law
     * CLASSIFIES custody for issuance and normalizes case + default ports;
     * THIS law VALIDATES a credential-carrying request and refuses anything
     * not literally named. The server's own pair (`isBrowserRequest()` vs
     * the guard) differs the same way — collapsing the two axes would
     * silently loosen the guard, which is exactly what M-SERVER-76 caught
     * once already. The empty-string Origin keeps the server's accept
     * quirk (the skip names only `'null'`); it is pinned, not polished. The
     * quirk stays DISCLOSED, not fixed: a present-but-empty Origin with a
     * Referer that names the Host is accepted by both repos (the server
     * twin's arm (6) carries the identical skip). Any tightening is a
     * both-repos change in one step, never a hub-solo edit.
     *
     * @param string|null $originHeader   Raw `Origin` header value or null.
     * @param string|null $refererHeader  Raw `Referer` header value or null.
     * @param string|null $hostHeader     Raw request `Host` header value.
     * @param string|null $secFetchSite   Raw `Sec-Fetch-Site` value or null.
     */
    public static function stateChangingRequestOriginAllowed(
        ?string $originHeader,
        ?string $refererHeader,
        ?string $hostHeader,
        ?string $secFetchSite,
    ): bool {
        if ($secFetchSite !== null && strtolower(trim($secFetchSite)) === 'cross-site') {
            return false;
        }

        if ($originHeader === null && $refererHeader === null) {
            return false;
        }

        if ($originHeader === 'null') {
            return false;
        }

        $server = self::originLawHostParts($hostHeader ?? '');

        if ($refererHeader !== null) {
            $refererParts = parse_url($refererHeader);
            if (!is_array($refererParts)) {
                return false;
            }

            $refererHost = $refererParts['host'] ?? '';
            $refererPort = isset($refererParts['port']) ? $refererParts['port'] : null;
            if (!self::originLawHostsMatch($refererHost, $refererPort, $server)) {
                return false;
            }
        }

        if ($originHeader !== null && $originHeader !== '') {
            $originParts = parse_url($originHeader);
            if (!is_array($originParts)) {
                return false;
            }

            $originHost = $originParts['host'] ?? '';
            $originPort = isset($originParts['port']) ? $originParts['port'] : null;
            if (!self::originLawHostsMatch($originHost, $originPort, $server)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Host-port split of a Host header for the CP-012 law (mirror of the
     * server's parseHostHeader, including its raw-string fallback when
     * parse_url refuses the value — an unparseable/empty Host then matches
     * nothing and everything is refused: fail-closed by construction).
     *
     * @return array{host: string, port: int|null}
     */
    private static function originLawHostParts(string $hostHeader): array
    {
        $parts = parse_url('http://' . $hostHeader);
        if (!is_array($parts)) {
            return ['host' => $hostHeader, 'port' => null];
        }

        return [
            'host' => $parts['host'] ?? $hostHeader,
            'port' => isset($parts['port']) ? $parts['port'] : null,
        ];
    }

    /**
     * The CP-012 exact-match limb: hosts compare raw, and the pair must
     * either both carry a port and agree, or both omit it (server-ratified;
     * no default-port normalization on this axis).
     *
     * @param array{host: string, port: int|null} $server
     */
    private static function originLawHostsMatch(string $candidateHost, ?int $candidatePort, array $server): bool
    {
        if ($candidateHost !== $server['host']) {
            return false;
        }

        if (($candidatePort !== null) !== ($server['port'] !== null)) {
            return false;
        }

        return !($candidatePort !== null && $candidatePort !== $server['port']);
    }

    /** Default port used when a side omits the :port (URL normalization). */
    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * Lowercased hostname of a Host header value, :port stripped and bracketed
     * IPv6 literals unwrapped (parse_url keeps the brackets on both forms).
     */
    private static function hostOf(string $hostHeader): string
    {
        $parts = parse_url('//' . $hostHeader);
        $host = strtolower(is_array($parts) ? ($parts['host'] ?? '') : '');

        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }
}
