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
 * Single source of truth for the hub's auth-cookie issuance policy
 * (COOKIE-DESIGN.md §2.1/§2.4, owner rulings CD-3a/CD-8a; the semantic twin
 * of the server's `Phlix\Auth\CookiePolicy` — same law, hub codebase).
 * Answers three independent questions:
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
 * ISSUANCE. Logout's cookie CLEAR also rides ungated: clearing is revocation
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
