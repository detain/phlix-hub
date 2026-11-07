<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\ViteAssets;

/**
 * Serves the HTML shell for the shared Vue 3 SPA (`@phlix/ui`) at `/app/*`.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   0.11.0 (Phase C)
 */
final class SharedUiController
{
    private const SHELL_RELATIVE_PATH = '/assets/app/index.html';

    /**
     * @param string $publicRoot Absolute path to the hub's `public/`
     *                           directory. The built SPA shell is read from
     *                           `$publicRoot . self::SHELL_RELATIVE_PATH`.
     */
    /**
     * CI-true rebuild incantation (S467 law, Minor 53 posture): now emitted to
     * the OPERATOR log only — never into the browser response, where it was
     * shell-command text shown to end users and, worse, the single label
     * shared by four very different failure causes including a denied path
     * traversal. The four build-state branches log it; the security refusal
     * does not (rebuilding fixes nothing about a denied path).
     */
    private const REBUILD_GUIDANCE =
        'cd web-ui && NPM_CONFIG_USERCONFIG=/dev/null npm ci && npm run build';

    /**
     * The one user-honest 503 frame for every bundle-serving failure, plus
     * the per-cause operator diagnostic that used to be the (mis)labelled page.
     *
     * The diagnostic rides the estate logger (review minor, 2026-11-03), not
     * `error_log()`: the bare call bypassed the per-handler level routing and the
     * `HUB_LOG_DIR` / `HUB_LOG_LEVEL` seams the whole rest of the corpus respects,
     * so these lines landed on stderr outside the rotating channel files an
     * operator actually reads. `warning` is the level the failure deserves: a hub
     * that cannot serve its own shell is broken but not an emergency, and it
     * clears the `file` handler's default `info` threshold while staying out of
     * `error.log`. Message text is unchanged from the `error_log()` frame; only
     * the ad-hoc `[phlix-hub]` tag is gone, because the channel name now carries
     * that identity.
     */
    private function unavailable(string $cause, bool $withGuidance): Response
    {
        LoggerFactory::get(LogChannels::HTTP)->warning(sprintf(
            'SharedUiController 503 (%s): %s%s',
            $cause,
            'the portal shell could not be served under ' . $this->publicRoot,
            $withGuidance ? ' — rebuild with: ' . self::REBUILD_GUIDANCE : '',
        ));

        return (new Response())
            ->status(503)
            ->html(
                '<h1>503 — UI unavailable</h1>'
                . '<p>The hub web interface cannot be served right now. '
                . 'If this persists, contact your hub administrator.</p>'
            );
    }

    public function __construct(private readonly string $publicRoot)
    {
    }

    /**
     * Return the SPA HTML shell.
     *
     * Reads `public/assets/app/index.html`. If the bundle is absent,
     * returns a 503 with an actionable message.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 200 HTML shell carrying the per-request `__PHLIX__` nonce
     *                  and the {@see self::contentSecurityPolicy()} header, or 503
     *                  when the bundle is missing.
     *
     * @since 0.11.0 (Phase C)
     */
    public function shell(Request $request, array $params = []): Response
    {
        unset($request, $params);

        // Validate build exists via ViteAssets manifest check.
        try {
            $viteAssets = new ViteAssets($this->publicRoot);
            $viteAssets->getEntryJsUrl();
        } catch (\RuntimeException) {
            return $this->unavailable('vite manifest unreadable — the bundle was never built', true);
        }

        $shellPath = $this->publicRoot . self::SHELL_RELATIVE_PATH;
        $real = realpath($shellPath);

        if ($real === false) {
            return $this->unavailable('shell index.html missing under the public root', true);
        }

        /** @var non-empty-string $realPath */
        $realPath = $real;

        if (! str_starts_with($realPath, $this->publicRoot . DIRECTORY_SEPARATOR)) {
            // Minor 53 honesty: this is a SECURITY refusal, not a broken build.
            return $this->unavailable('SECURITY: shell path escapes the public root — request denied', false);
        }

        if (! is_file($realPath)) {
            return $this->unavailable('shell path is not a regular file', true);
        }

        $html = file_get_contents($realPath);
        if ($html === false) {
            return $this->unavailable('shell index.html exists but could not be read (permissions?)', true);
        }

        // Inject window.__PHLIX__ config
        $config = [
            'app' => 'hub',
            // apiBase: empty string = relative URLs (same-origin). Cross-origin would set actual URL.
            'apiBase' => '',
            'routerBase' => '/app',
            'menu' => [],
            'extraRoutes' => [],
            'features' => (object) [],
            // Optional operator-pinned WebSocket endpoint hints for the SPA. Both
            // default to null = "not configured", so the ui lane falls back to its
            // legacy same-origin socket derivation (current shipped behavior). The
            // ui-side consumer lands in a sibling lane; see contentSecurityPolicy()
            // for the socket ports the bundle dials.
            'wsUrl' => self::configuredSocketBaseUrl(),
            'wsPath' => self::configuredSocketBasePath(),
        ];

        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($configJson === false) {
            $configJson = '{}';
        }

        // The bootstrap config is emitted as an inline <script>, which the shell's
        // `script-src 'self'` would otherwise block. Because this block is
        // server-templated (its JSON contents vary per request / per UI build), a
        // per-request cryptographic nonce is the robust fix: the SAME nonce rides
        // the <script> tag and the CSP header, so exactly this inline block runs
        // without ever weakening script-src to 'unsafe-inline'. Fresh per response.
        $nonce = base64_encode(random_bytes(16));

        $configScript = '<script nonce="' . $nonce . '">window.__PHLIX__ = '
            . $configJson . '</script>' . "\n";
        /** @var string $htmlContent */
        $htmlContent = $html;
        $replacement = self::injectBootstrapBlock($htmlContent, $configScript);
        if ($replacement === null) {
            return $this->unavailable(
                'shell index.html has no <head> anchor for the bootstrap injection'
                    . ' — refusing to serve an unconfigured shell',
                true,
            );
        }
        $htmlContent = $replacement;

        return (new Response())
            ->html($htmlContent)
            ->header('Content-Security-Policy', self::contentSecurityPolicy($nonce));
    }

    /**
     * Inject the per-request bootstrap <script> right after the shell's
     * `<head>` open tag — LITERAL-SAFE by construction.
     *
     * HUB-m18 (review hub__fedev): the original `preg_replace(...) ?? $htmlContent`
     * swallowed both a preg error and a zero-count (no bare `<head>` to anchor
     * on — e.g. an attributed `<head data-built=...>` from a changed build)
     * into a SILENT pass-through: the SPA booted with guessed defaults and the
     * validated wsUrl/wsPath seam vanished with no operator signal. That
     * posture is kept: exactly one anchor or null, and the caller fail-louds
     * through unavailable().
     *
     * Follow-up (same review family): the injection side had that posture, the
     * replacement side had none. In a `preg_replace` replacement string, `$N`
     * sequences are BACK-REFERENCES — measured on this file's own shape, a
     * literal `$1` inside the injected block VANISHED (unmatched groups
     * substitute empty), and with a capturing pattern any present group would
     * have been substituted in. The block now rides a
     * `preg_replace_callback` return: the string is inserted verbatim, so no
     * `$` or `\` in the JSON (or in any future config field's value) can ever
     * be re-read as a substitution reference. The HUB-m18 count tooth survives
     * as the callback-invocation counter, and an uppercase bare <HEAD> still
     * matches via /i — shape tolerance preserved.
     *
     * @param string $html         Shell index.html contents.
     * @param string $configScript The nonce-stamped bootstrap block to insert.
     *
     * @return string|null The injected document, or null when the caller must
     *                     fail loud (no exactly-one `<head>` anchor).
     */
    private static function injectBootstrapBlock(string $html, string $configScript): ?string
    {
        $replaceCount = 0;
        $replaced = preg_replace_callback(
            '/<head>/i',
            /** @param list<string> $matches */
            static function (array $matches) use ($configScript, &$replaceCount): string {
                unset($matches);
                $replaceCount++;
                return "<head>\n" . $configScript;
            },
            $html,
            1,
        );

        return ($replaced === null || $replaceCount !== 1) ? null : $replaced;
    }

    /**
     * The hub SPA shell's Content-Security-Policy.
     *
     * Hub analogue of phlix-server's
     * `SecurityHeaders::contentSecurityPolicy()`, but derived from what the
     * hub-served `@phlix/ui` bundle actually loads (verified against the
     * committed `public/assets/app/` build), not copied blindly. Rationale per
     * directive:
     *
     *  - `script-src 'self' 'nonce-<n>'` — the only inline script is the
     *    per-request `window.__PHLIX__` bootstrap, stamped with the SAME nonce;
     *    no `'unsafe-inline'`, no `'unsafe-eval'` (the precompiled bundle never
     *    calls `eval`).
     *  - `style-src 'self' 'unsafe-inline'` — the token-theming law: the SPA
     *    applies themes by writing CSS custom properties through
     *    `el.style.setProperty()`, which lands in element style attributes.
     *  - `img-src 'self' data: blob:` — cover art is relay-proxied same-origin
     *    (`useImageSrc` prefixes the empty `apiBase`); `data:` for the inline
     *    SVG texture in the bundle CSS, `blob:` for object-URL posters. The hub
     *    pulls NOTHING from the TMDB image CDN (unlike the media server), so the
     *    server's `image.tmdb.org` / `tmdb.org` hosts are deliberately ABSENT.
     *  - `font-src 'self'` — the woff2 faces ship under `/assets/app/`.
     *  - `connect-src 'self' ws: wss:` — REST/fetch/XHR stays same-origin; the
     *    SPA additionally opens WebSockets on non-default ports (`:8804` hub
     *    syncplay relay, `:8097` backing-server direct syncplay — verified in the
     *    bundle). CSP cannot express "any port on the current host", and baking
     *    the client's port constants into this header would couple two
     *    independently-versioned artifacts and break cross-origin `hubBaseUrl`
     *    relay setups, so the socket schemes are wildcarded. The abuse connect-src
     *    most defends against (a script exfiltrating via fetch/WS) is already
     *    foreclosed by the `script-src` nonce — no foreign script executes to
     *    dial a hostile origin. Tightening to exact `ws://<host>:<port>` sources
     *    would need a structured, closed-value config key, never a free-form box.
     *  - `media-src 'self' blob:` / `worker-src 'self' blob:` — hls.js drives an
     *    MSE `blob:` object URL and a `blob:`-sourced transmux Worker (both
     *    present in the bundle's `hls-*` chunk); dropping these rejects playback
     *    with "Media load rejected by URL safety check".
     *  - `frame-ancestors 'none'` — the hub's F3 click-jack posture (stricter
     *    than the server's `'self'`). Kept here because stamping an explicit CSP
     *    now bypasses the `Response` baseline default, which would otherwise be
     *    lost on the shell.
     *  - `base-uri 'self'`.
     *
     * DO-NOT-EXPOSE: nothing in this string is operator-tunable. Every
     * relaxation is a load-bearing, code-pinned decision; a free-form CSP text
     * box would let one setting break the SPA with a value no schema could
     * validate.
     *
     * @param string|null $scriptNonce The nonce emitted on the inline block this
     *                                 request; when non-empty it is added to
     *                                 `script-src`. The caller MUST stamp the
     *                                 identical nonce on the inline tag.
     *
     * @return non-empty-string The full CSP header value.
     */
    public static function contentSecurityPolicy(?string $scriptNonce = null): string
    {
        $scriptSrc = "'self'";
        if ($scriptNonce !== null && $scriptNonce !== '') {
            $scriptSrc .= " 'nonce-" . $scriptNonce . "'";
        }

        return "default-src 'self'; script-src " . $scriptSrc . "; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; "
            . "font-src 'self'; connect-src 'self' ws: wss:; "
            . "media-src 'self' blob:; worker-src 'self' blob:; "
            . "frame-ancestors 'none'; base-uri 'self'";
    }

    /**
     * Optional explicit WebSocket base origin for the SPA (`HUB_WS_URL`).
     *
     * When set to a valid `ws://`/`wss://` origin (optional `:port`, NO path —
     * the path belongs to {@see self::configuredSocketBasePath()}), it is emitted
     * as `window.__PHLIX__.wsUrl` so the ui lane can dial an operator-pinned
     * socket endpoint instead of deriving same-origin. Absent, blank, or
     * malformed yields `null` = the client's legacy same-origin derivation
     * (unchanged behavior). Fail-soft by design: the bootstrap hint must never
     * break the shell, mirroring the `HUB_COOKIE_INSECURE` precedent on Response.
     *
     * Accepted: `ws://127.0.0.1:8804`, `wss://hub.example.com`, `wss://hub.example.com:8804`.
     * Rejected (→ null): any non-ws(s) scheme, embedded path/query/userinfo,
     * a bare `//host`, or an out-of-range port.
     *
     * @return string|null The validated origin, or null when unset/invalid.
     */
    public static function configuredSocketBaseUrl(): ?string
    {
        $raw = getenv('HUB_WS_URL');
        if ($raw === false) {
            return null;
        }
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }
        // REVIEW-FIX: the grammar alone admitted `:99999` while the docblock
        // promised out-of-range ports are rejected. Parse, then range-check —
        // the promise is now real (still fail-soft: rejection yields null,
        // the shell renders, the client falls back to same-origin derivation).
        if (preg_match('#^(wss?)://([A-Za-z0-9._-]+)(?::([0-9]{1,5}))?$#', $candidate, $parts) === 1) {
            $port = $parts[3] ?? '';
            if ($port === '') {
                return $candidate;
            }
            if ((int) $port >= 1 && (int) $port <= 65535) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Optional socket base path (`HUB_WS_PATH`), e.g. `/syncplay`.
     *
     * Emitted as `window.__PHLIX__.wsPath`. Must be a single absolute path
     * segment set (leading `/`, path-safe characters only, no query/fragment).
     * Absent, blank, or malformed yields `null` = legacy client behavior.
     *
     * @return string|null The validated path, or null when unset/invalid.
     */
    public static function configuredSocketBasePath(): ?string
    {
        $raw = getenv('HUB_WS_PATH');
        if ($raw === false) {
            return null;
        }
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }
        if (preg_match('#^/[A-Za-z0-9._~/-]*$#', $candidate) === 1) {
            return $candidate;
        }

        return null;
    }
}
