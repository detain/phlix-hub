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
use Phlix\Shared\Security\OriginPolicy;
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
 *     never a silent Secure downgrade onto plain HTTP). On a hub marked
 *     production the opt-in now additionally speaks once per process
 *     (HUB-4.2 / SH-033 hub port; {@see self::warnProductionInsecureOnce()}).
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
    public const MODE_COOKIE = OriginPolicy::MODE_COOKIE;

    /** Bearer custody class — mirrors contracts COOKIE_MODE.BEARER (src/auth.ts). */
    public const MODE_BEARER = OriginPolicy::MODE_BEARER;

    /** Session (access) cookie name — the hub's deleted-surface name, revived. */
    public const SESSION_COOKIE = 'phlix_hub_token';

    /** Refresh cookie name — the hub's deleted-surface name, revived. */
    public const REFRESH_COOKIE = 'phlix_hub_refresh';

    /**
     * Env opt-in to LAN-readable cookies (accepts the same '1'/'true' as
     * Response's Secure-resolver).
     *
     * HUB-ENVBOOL census row: the strict spelling pair is the ratified-
     * security vocabulary — deliberately NOT the estate EnvConfig bool
     * grammar (see {@see self::insecureOverride()} for the do-not-merge
     * ruling and the Response twin-lock).
     */
    public const INSECURE_ENV = 'HUB_COOKIE_INSECURE';

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
     * (CD-3a: no silent Secure downgrade). On a hub marked production either
     * form additionally speaks up once per process (HUB-4.2 / SH-033 hub
     * port; {@see self::warnProductionInsecureOnce()}) — a warning, not a
     * gate: the opt-out stays honoured.
     *
     * HUB-ENVBOOL census row — the env limb keeps its STRICT exact-'1'/'true'
     * shape by ratified-security law, not oversight: the estate bool grammar
     * ({@see \Phlix\Hub\Common\Env\EnvConfig::bool()}) would accept
     * yes/on/case-folded/padded spellings on a knob that drops the Secure
     * attribute, and convergence never loosens a security accept-set. The
     * stored twin below is NOT an env read at all (it goes through the store
     * resolver) — only the env limb was ever a bool-grammar candidate, and it
     * stays local, in lockstep with its Response.php twin.
     */
    public function insecureOverride(): bool
    {
        $env = getenv(self::INSECURE_ENV);
        if ($env === '1' || $env === 'true') {
            self::warnProductionInsecureOnce(self::INSECURE_ENV . '=' . $env);

            return true;
        }

        try {
            $stored = ($this->insecureResolver ?? HubSettingsResolvers::bool(self::COOKIE_INSECURE_KEY, false))();
        } catch (Throwable) {
            return false;
        }

        if ($stored === true) {
            self::warnProductionInsecureOnce(self::COOKIE_INSECURE_KEY);
        }

        return $stored;
    }

    /**
     * SH-033 hub port (HUB-4.2): an insecure-cookie opt-in while the hub is
     * marked production is a misconfiguration the operator should HEAR
     * about — exactly one loud E_USER_WARNING per process, the same
     * once-per-process latch idiom as the server twin (phlix-server
     * `src/Auth/CookiePolicy.php::warnProductionInsecureOnce()`, itself
     * modelled on the boot-time DB-credential guard).
     *
     * Posture facts owned by the port, both deliberate:
     *  - production marker: the server keys on its single PHLIX_ENV; the
     *    hub's shipped config readers honour HUB_ENV, APP_ENV and PHLIX_ENV
     *    alike, so ANY of them strictly equal to 'production' marks the
     *    deployment (unset/dev tiers stay silent — no warning theater).
     *    The FIRST marker to match in that order names itself in the
     *    warning text, so the operator hears which knob actually won
     *    (MINOR-2, HUB-4.2 review);
     *  - latch key: hub-scoped (`__phlix_hub_...`) rather than the server's
     *    name, so if both hosts' classes are ever loaded in one process
     *    (integration harness) each keeps its own once-semantics.
     *
     * Warning, never gate: LAN opt-ins keep working (a hard block would
     * strand the operator who genuinely runs plain HTTP). The documented
     * test seam is unsetting the latch key in $GLOBALS
     * ({@see \Phlix\Hub\Tests\Unit\Hub\CookiePolicyProductionInsecureWarnTest}).
     */
    private static function warnProductionInsecureOnce(string $source): void
    {
        $marker = null;
        foreach (['HUB_ENV', 'APP_ENV', 'PHLIX_ENV'] as $candidate) {
            if (getenv($candidate) === 'production') {
                $marker = $candidate;
                break;
            }
        }

        if ($marker === null) {
            return;
        }

        if (isset($GLOBALS['__phlix_hub_cookie_insecure_production_warn'])) {
            return;
        }

        $GLOBALS['__phlix_hub_cookie_insecure_production_warn'] = true;

        trigger_error(
            $source . ' is active while the hub is marked production (' . $marker
            . '=production) — hub auth '
            . 'cookies are issued WITHOUT the Secure attribute and are readable on plain HTTP. '
            . 'Terminate TLS at the front proxy and serve the hub over https, '
            . 'or turn the insecure-cookie opt-in off',
            E_USER_WARNING
        );
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
     *
     * Delegates to the shared {@see OriginPolicy} kernel with the hub's
     * no-form-era posture (formEra=false) — A19 fold, verdict-identical
     * (swept in CookiePolicyWireParityTest).
     */
    public function custodyMode(
        ?string $deviceTypeHeader,
        ?string $originHeader,
        ?string $hostHeader,
        ?string $secFetchSite,
    ): string {
        return OriginPolicy::custodyMode(false, $deviceTypeHeader, $originHeader, $hostHeader, $secFetchSite);
    }

    /**
     * First-hop https test on an X-Forwarded-Proto value (chains append:
     * "https, http" — the client-facing hop wins; only https|http are read).
     */
    public static function isHttpsForwarded(?string $forwardedProto): bool
    {
        return OriginPolicy::httpsForwardedValue($forwardedProto);
    }

    /**
     * Hostname limb of the §2.4 localhost rule: exact-name match after
     * lowercasing and :port strip (bracketed IPv6 literals kept whole).
     */
    public static function isLocalhostHost(?string $hostHeader): bool
    {
        return OriginPolicy::localhostHost($hostHeader);
    }

    /**
     * Whether an Origin header is a same-site http(s) origin against the
     * request Host: http/https scheme only (app://, capacitor://, file:// are
     * device webview vaults — bearer per §2.1), and the host:port pair
     * matches after default-port normalization.
     */
    public static function originIsSameSite(string $origin, ?string $hostHeader): bool
    {
        return OriginPolicy::originIsSameSite($origin, $hostHeader);
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
        return OriginPolicy::stateChangingRequestOriginAllowed(
            $originHeader,
            $refererHeader,
            $hostHeader,
            $secFetchSite
        );
    }
}
