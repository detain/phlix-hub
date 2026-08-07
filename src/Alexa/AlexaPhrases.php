<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use ReflectionClass;

use function is_string;

/**
 * Every word the Alexa skill is capable of saying (S91).
 *
 * ## Why one class holds all of it
 *
 * The honesty criterion — the skill must never claim device control it does not
 * have — is only checkable if the set of things the skill can say is FINITE and
 * ENUMERABLE. A string literal inline in a controller is neither: it is invisible
 * to {@see all()}, so the suite's "every phrase is clean" assertion would pass
 * over it silently, and the vacuity would be undetectable because the assertion
 * would still be examining a non-empty list. Hence the rule, enforced by review
 * and by {@see \Phlix\Hub\Http\Controllers\AlexaSkillController} carrying no
 * speech literal of its own: **every emittable string is a constant here**.
 *
 * ## Templates, not sentences
 *
 * Each constant is a template with `{placeholder}` markers, interpolated by
 * {@see AlexaSpeech::render()}. `render()` runs {@see AlexaHonesty::violations()}
 * against the TEMPLATE and never against the substituted values — see
 * {@see AlexaHonesty} for why a library containing a film called *Roku* must not
 * be able to trip the detector.
 *
 * ## What the wording is careful about
 *
 * Several phrases state a NEGATIVE capability out loud ("Phlix cannot start
 * playback on a device for you"). That is deliberate and is the honest half of
 * the feature: a user who asks for a title to be played gets a link plus a plain
 * statement of what the skill will not do, rather than a cheerful acknowledgement
 * followed by silence from the television.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaPhrases
{
    /**
     * The capability statement. Spoken on `LaunchRequest` and on
     * `AMAZON.HelpIntent` — the two moments a user is asking "what is this?" —
     * and it answers with both halves, the can and the cannot.
     */
    public const CAPABILITY = 'Phlix can answer questions about the titles in your library, and it can '
        . 'send you a link to open one. It cannot start or control playback on any device, and it cannot '
        . 'send video to a screen for you. Try asking, how long is Inception.';

    /** Follow-up prompt after the capability statement. */
    public const CAPABILITY_REPROMPT = 'What would you like to know about your library?';

    /** `AMAZON.StopIntent` / `AMAZON.CancelIntent`. */
    public const GOODBYE = 'Goodbye.';

    /**
     * `AMAZON.FallbackIntent` and any intent this skill does not dispatch.
     * States the boundary rather than apologising vaguely, so a user learns what
     * to ask instead of retrying the same unsupported phrasing.
     */
    public const UNSUPPORTED_REQUEST = 'I cannot do that. Phlix can answer questions about the titles in '
        . 'your library and send you a link to open one. Say help to hear an example.';

    /** No linked account, or a linked account whose token no longer resolves. */
    public const LINK_ACCOUNT = 'Your Phlix account is not linked yet. Open the Alexa app and link your '
        . 'Phlix account, then ask me again.';

    /** The linked user owns no servers at all. */
    public const NO_SERVERS = 'There are no Phlix servers on your account yet. Claim one in the Phlix hub, '
        . 'then ask me again.';

    /** The user owns servers, but none has a live relay tunnel. */
    public const NO_CONNECTED_SERVER = 'None of your Phlix servers is connected to the hub right now, so I '
        . 'cannot look anything up. Try again once it is back online.';

    /** The proxied lookup failed — a non-200 from the server, or an unreadable payload. */
    public const LOOKUP_FAILED = 'I could not reach your Phlix library just now. Please try again in a moment.';

    /** The intent matched but its `Title` slot carried nothing. */
    public const MISSING_TITLE = 'I did not catch which title you meant. Ask again and say the name of the title.';

    /** Search returned no rows for the requested title. */
    public const TITLE_NOT_FOUND = 'I could not find {title} in your library.';

    /** Lead-in for the facts sentence; `{facts}` is the joined fragments below. */
    public const TITLE_FACTS = 'Here is what I have for {title}. {facts}';

    /** The item exists but carries none of the fields worth speaking. */
    public const TITLE_NO_DETAILS = 'I found {title} in your library, but I do not have any details for it.';

    /** Runtime fragment, spoken only when the server actually reported one. */
    public const FACT_RUNTIME = 'It runs {minutes} minutes.';

    /** Release-year fragment. */
    public const FACT_YEAR = 'It came out in {year}.';

    /** Content-rating fragment. */
    public const FACT_RATING = 'It is rated {rating}.';

    /** Summary fragment — the server's own overview text, passed through. */
    public const FACT_SUMMARY = '{summary}';

    /**
     * The "get me a link" answer. Says where the link is AND, in the same breath,
     * that the skill will not start anything — the sentence a user needs in order
     * not to stand waiting for a screen to light up.
     */
    public const PLAY_LINK_SPEECH = 'I have put a link to {title} in the Alexa app. Phlix cannot start '
        . 'playback on a device for you, so open the link where you want to watch.';

    /** Title of the `Simple` card carrying the link. */
    public const PLAY_LINK_CARD_TITLE = 'Phlix link for {title}';

    /** Body of the `Simple` card carrying the link. */
    public const PLAY_LINK_CARD_TEXT = "Open this link to find {title} in Phlix and start it yourself. "
        . "This skill cannot start playback for you.\n\n{link}";

    /**
     * Every template this class declares.
     *
     * Derived by reflection rather than hand-listed, on purpose. A hand-written
     * list is the one thing that can go stale in exactly the direction that
     * matters: a new phrase added without being listed would be invisible to the
     * suite's honesty sweep, which is precisely the phrase most likely to be the
     * dishonest one. Derivation cannot omit an entry. The suite pairs it with a
     * FLOOR on the count, so an `all()` that silently returned nothing reports as
     * "nothing was measured" rather than as a clean sweep.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $constants = (new ReflectionClass(self::class))->getConstants();

        $templates = [];
        /** @var mixed $value */
        foreach ($constants as $value) {
            if (is_string($value)) {
                $templates[] = $value;
            }
        }

        return $templates;
    }
}
