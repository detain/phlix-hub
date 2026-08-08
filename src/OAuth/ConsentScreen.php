<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use function htmlspecialchars;
use function implode;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders the OAuth consent screen and the two non-redirectable error pages
 * (S92).
 *
 * ## Why hand-rolled HTML and not the Vue SPA
 *
 * The SPA (`/app`) is the hub's only UI, and this is the one deliberate
 * exception. The consent screen is the single page in the product that a
 * THIRD PARTY sends the user to, and whose entire job is to state accurately
 * what is about to be granted. Rendering it from `public/assets/app/` would
 * make its content depend on a separately-built, separately-versioned bundle
 * that no server-side test can pin — the scope list a user was shown would live
 * outside the repository that decides what the scopes mean. Everything on this
 * page is server-rendered from the same {@see OAuthScopes::describe()} the
 * grant is built from, so the page cannot describe a capability the token will
 * not carry, or omit one it will.
 *
 * ## Escaping
 *
 * `client_id`, the client's display name and `state` are all attacker-influenced
 * (a `client_id` is chosen by whoever provisions a client; `state` is chosen by
 * the client at request time). Every interpolated value goes through
 * {@see esc()} — `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`, UTF-8 — so a name of
 * `"><script>` is inert text. `state` is never interpolated into this page at
 * all; it lives in the stored {@see PendingAuthorization} and is only ever
 * appended to a `Location` header, already URL-encoded.
 *
 * The only value that must NOT be treated as attacker data is the consent
 * ticket, which this class emits into a hidden input — it is CSPRNG hex from
 * {@see \Phlix\Hub\Common\Support\Ids::token()} and is escaped anyway, because
 * "this one is safe" is how the next one stops being escaped.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class ConsentScreen
{
    /** Form field carrying the single-use consent ticket. */
    public const string FIELD_TICKET = 'consent_ticket';

    /** Form field carrying the user's decision. */
    public const string FIELD_DECISION = 'decision';

    /** The ONLY {@see FIELD_DECISION} value that authorises anything. */
    public const string DECISION_ALLOW = 'allow';

    /** Explicit refusal — produces an `access_denied` redirect. */
    public const string DECISION_DENY = 'deny';

    /**
     * Render the consent form.
     *
     * @param OAuthClient  $client    Client requesting access.
     * @param list<string> $scopes    Scopes about to be granted, in
     *                                {@see OAuthScopes::all()} order.
     * @param string       $ticket    Single-use consent ticket.
     * @param string       $formAction Path the form POSTs to.
     *
     * @return string A complete HTML document.
     */
    public static function render(OAuthClient $client, array $scopes, string $ticket, string $formAction): string
    {
        $items = [];
        foreach ($scopes as $scope) {
            $items[] = '<li><strong>' . self::esc(OAuthScopes::describe($scope)) . '</strong>'
                . '<span class="scope-id">' . self::esc($scope) . '</span></li>';
        }

        $body = '<h1>Authorise ' . self::esc($client->name) . '</h1>'
            . '<p class="lede"><strong>' . self::esc($client->name) . '</strong> wants to connect to your Phlix'
            . ' account. If you approve, it will be able to:</p>'
            . '<ul class="scopes">' . implode('', $items) . '</ul>'
            . '<p class="note">It will not be able to do anything else, and you can disconnect it at any time'
            . ' from your account settings.</p>'
            . '<form method="post" action="' . self::esc($formAction) . '">'
            . '<input type="hidden" name="' . self::FIELD_TICKET . '" value="' . self::esc($ticket) . '">'
            . '<button type="submit" name="' . self::FIELD_DECISION . '" value="' . self::DECISION_DENY . '"'
            . ' class="secondary">Cancel</button>'
            . '<button type="submit" name="' . self::FIELD_DECISION . '" value="' . self::DECISION_ALLOW . '"'
            . ' class="primary">Allow</button>'
            . '</form>';

        return self::document('Authorise ' . $client->name, $body);
    }

    /**
     * Render a terminal error page.
     *
     * Used for the two failures that MUST NOT redirect — an unknown/disabled
     * `client_id`, and a `redirect_uri` that is not registered. Redirecting
     * either of those would hand an attacker an open redirect (and, in the
     * second case, would deliver the error to a destination the legitimate
     * client never registered).
     *
     * @param string $heading Short title.
     * @param string $detail  One-sentence explanation, already plain text.
     *
     * @return string A complete HTML document.
     */
    public static function error(string $heading, string $detail): string
    {
        return self::document(
            $heading,
            '<h1>' . self::esc($heading) . '</h1>'
            . '<p class="lede">' . self::esc($detail) . '</p>'
            . '<p class="note">Nothing has been shared. You can close this page and try again from the'
            . ' application that sent you here.</p>',
        );
    }

    /**
     * Wrap a body fragment in the shared shell.
     *
     * @param string $title Document title.
     * @param string $body  Pre-escaped body markup.
     */
    private static function document(string $title, string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            // A consent screen must never be framed: clickjacking it is exactly
            // how a user is made to click "Allow" on something they cannot see.
            // The header is set on the Response as well; this meta CSP is the
            // belt to that braces and survives a header being stripped by an
            // intermediary.
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\';'
            . ' style-src \'unsafe-inline\'; form-action \'self\'; frame-ancestors \'none\'">'
            . '<title>' . self::esc($title) . ' — Phlix</title>'
            . '<style>' . self::STYLE . '</style></head><body><main>' . $body . '</main></body></html>';
    }

    /** Inline stylesheet — inline because the CSP above forbids every external source. */
    private const string STYLE = 'body{margin:0;background:#101014;color:#e8e8ef;'
        . 'font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;display:flex;'
        . 'min-height:100vh;align-items:center;justify-content:center}'
        . 'main{max-width:32rem;padding:2rem;background:#191921;border-radius:12px;margin:1rem}'
        . 'h1{font-size:1.4rem;margin:0 0 1rem}'
        . '.lede{margin:0 0 1rem}.note{font-size:.875rem;color:#9a9aab}'
        . '.scopes{list-style:none;padding:0;margin:0 0 1.5rem}'
        . '.scopes li{padding:.6rem .8rem;background:#22222c;border-radius:8px;margin-bottom:.5rem}'
        . '.scope-id{display:block;font-size:.75rem;color:#8a8a9b;font-family:ui-monospace,monospace}'
        . 'form{display:flex;gap:.75rem;justify-content:flex-end}'
        . 'button{font:inherit;padding:.6rem 1.2rem;border-radius:8px;border:0;cursor:pointer}'
        . '.secondary{background:#2c2c38;color:#e8e8ef}'
        . '.primary{background:#5b6cff;color:#fff}';

    /**
     * Escape a value for HTML text or a double-quoted attribute.
     *
     * `ENT_QUOTES` so it is safe in an attribute, `ENT_SUBSTITUTE` so invalid
     * UTF-8 becomes U+FFFD rather than an EMPTY STRING — the default's
     * empty-string behaviour silently deletes the whole value, which would turn
     * a malformed client name into a blank consent screen that says a third
     * party wants access without saying which.
     *
     * @param string $value Raw value.
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
