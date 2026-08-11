<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\ConsentScreen;
use Phlix\Hub\OAuth\OAuthClient;
use Phlix\Hub\OAuth\OAuthScopes;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ConsentScreen}.
 *
 * The consent screen's job is to state accurately what is about to be granted.
 * Two ways it can fail at that, and both are asserted here: it can DESCRIBE the
 * wrong thing (a scope shown that the token will not carry, or one carried that
 * was never shown), and it can be a vector in its own right (an unescaped client
 * name is stored XSS on the one page in the product a third party sends users
 * to).
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 */
final class ConsentScreenTest extends TestCase
{
    private static function client(string $name = 'Phlix for Alexa'): OAuthClient
    {
        return OAuthClient::create(
            'row-id',
            'alexa-skill',
            $name,
            ['https://layla.amazon.com/api/skill/link/M2ABCDEFG'],
            OAuthScopes::all(),
            false,
            null,
        );
    }

    public function testTheScreenNamesEveryScopeBeingGrantedAndNoOthers(): void
    {
        $granted = [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ];

        $html = ConsentScreen::render(self::client(), $granted, 'ticket-abc', '/oauth/authorize');

        foreach ($granted as $scope) {
            self::assertStringContainsString($scope, $html, $scope . ' is granted but not shown');
            self::assertStringContainsString(OAuthScopes::describe($scope), $html);
        }

        // The control that makes the assertion above mean something: a scope
        // that is NOT being granted must not appear. Without this, a screen that
        // listed the whole vocabulary would pass.
        self::assertStringNotContainsString(
            McpScopes::PLAYBACK_CONTROL,
            $html,
            'the screen showed a scope that is not part of this grant',
        );
        self::assertStringNotContainsString(OAuthScopes::describe(McpScopes::PLAYBACK_CONTROL), $html);
    }

    public function testTheScreenCarriesTheTicketAndPostsToTheAuthorizePath(): void
    {
        $html = ConsentScreen::render(self::client(), [OAuthScopes::PROFILE_READ], 'tk-123', '/oauth/authorize');

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/oauth/authorize"', $html);
        self::assertStringContainsString(
            '<input type="hidden" name="' . ConsentScreen::FIELD_TICKET . '" value="tk-123">',
            $html,
        );
        self::assertStringContainsString('value="' . ConsentScreen::DECISION_ALLOW . '"', $html);
        self::assertStringContainsString('value="' . ConsentScreen::DECISION_DENY . '"', $html);
    }

    /**
     * The consent screen is the only page a third party sends a user to, and the
     * client's display name is attacker-controlled at provisioning time.
     */
    public function testAHostileClientNameIsEscapedRatherThanRendered(): void
    {
        $payload = '"><script>alert(1)</script>';

        $html = ConsentScreen::render(self::client($payload), [OAuthScopes::PROFILE_READ], 'tk', '/oauth/authorize');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('"><script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        // The quote must be entity-encoded too, or it escapes the attribute it
        // would sit in on a page that ever interpolated the name into one.
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testAHostileTicketValueIsEscaped(): void
    {
        // The ticket is CSPRNG hex and cannot actually contain this. It is
        // escaped anyway, because "this one is safe" is how the next one stops
        // being escaped.
        $html = ConsentScreen::render(self::client(), [OAuthScopes::PROFILE_READ], '"><img src=x onerror=1>', '/x');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testTheScreenForbidsBeingFramedAndLoadsNothingExternal(): void
    {
        $html = ConsentScreen::render(self::client(), [OAuthScopes::PROFILE_READ], 'tk', '/oauth/authorize');

        // Clickjacking a consent screen is how a user is made to click "Allow"
        // on something they cannot see.
        self::assertStringContainsString("frame-ancestors 'none'", $html);
        self::assertStringContainsString("default-src 'none'", $html);
        self::assertStringContainsString("form-action 'self'", $html);

        // Nothing is fetched from anywhere: no external stylesheet, script, or
        // image can be used to fingerprint or track the user mid-consent.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('src=', $html);
        self::assertStringNotContainsString('<link', $html);
    }

    public function testTheErrorPageStatesThatNothingWasShared(): void
    {
        $html = ConsentScreen::error('Unknown application', 'It is not registered with this hub.');

        self::assertStringContainsString('Unknown application', $html);
        self::assertStringContainsString('It is not registered with this hub.', $html);
        self::assertStringContainsString('Nothing has been shared', $html);

        // An error page must never carry a consent form — that would be a
        // refusal a user could submit their way past.
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString(ConsentScreen::FIELD_TICKET, $html);
    }

    public function testTheErrorPageEscapesItsInputs(): void
    {
        $html = ConsentScreen::error('<b>x</b>', '<i>y</i>');

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('&lt;i&gt;', $html);
    }

    public function testAllowAndDenyAreDistinctAndAllowIsNotAPrefixOfDeny(): void
    {
        // The controller compares the decision with hash_equals against
        // DECISION_ALLOW. If the two constants ever collided or one became a
        // prefix of the other, "deny" could authorise.
        self::assertNotSame(ConsentScreen::DECISION_ALLOW, ConsentScreen::DECISION_DENY);
        self::assertStringStartsNotWith(ConsentScreen::DECISION_ALLOW, ConsentScreen::DECISION_DENY);
        self::assertStringStartsNotWith(ConsentScreen::DECISION_DENY, ConsentScreen::DECISION_ALLOW);
    }

    public function testTheDocumentIsWellFormedHtml(): void
    {
        $html = ConsentScreen::render(self::client(), OAuthScopes::all(), 'tk', '/oauth/authorize');

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringEndsWith('</html>', $html);
        self::assertSame(
            substr_count($html, '<li>'),
            substr_count($html, '</li>'),
            'the scope list must be balanced',
        );
        self::assertSame(count(OAuthScopes::all()), substr_count($html, '<li>'));
    }
}
