<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use function str_contains;
use function strtolower;

/**
 * The device-control claim detector (S91).
 *
 * ## The criterion this class enforces
 *
 * The Phlix Alexa skill can answer questions about a library and hand back a
 * LINK. It cannot start, stop, pause or route playback on any device, and it
 * cannot cast to one. Actual playback is a later step; casting
 * (Chromecast/Roku/AirPlay) is not built at all. A skill that says otherwise is
 * not merely imprecise — it is telling a user that a button exists which does
 * not, and the user's only way to discover the truth is to try it and watch
 * nothing happen.
 *
 * So the honesty criterion is enforced twice, in two different ways:
 *
 *  - **structurally**, by {@see AlexaSpeech} having no parameter, field or code
 *    path that can emit `directives`, `AudioPlayer` or `VideoApp`; and
 *  - **lexically**, by this class, which is run at RUNTIME by
 *    {@see AlexaSpeech::render()} over every speech TEMPLATE before it is
 *    interpolated, and by the test suite over {@see AlexaPhrases::all()}.
 *
 * Neither half subsumes the other. The structural half cannot stop a phrase
 * that merely SAYS "now playing on your TV" through a perfectly ordinary
 * `outputSpeech`; the lexical half cannot stop a `directives` array that
 * contains no English at all.
 *
 * ## Why the TEMPLATE and never the interpolated value
 *
 * {@see AlexaSpeech::render()} checks the template, then substitutes. That
 * ordering is the whole design. A user's library legitimately contains a film
 * called *Roku*, a band called *AirPlay*, an episode titled *Now Playing*; if
 * the check ran after interpolation, saying the runtime of such a title would
 * throw a `LogicException` inside a resident worker and the user would get a
 * 400 for owning the wrong media. The claim being policed is one the SKILL
 * makes, and the skill's words are exactly the template's words.
 *
 * ## Why these terms, and why no others
 *
 * Every entry is a phrase a genuine device-control claim would actually use AND
 * one that can actually occur in this codebase's output — the suite asserts each
 * term fires on a crafted string, so a decorative entry that could never match
 * would be a red build rather than free safety. Terms that are merely
 * *adjacent* to the topic ("play", "watch", "stream", "device") are deliberately
 * ABSENT: they appear in honest sentences this skill must be able to say
 * ("Phlix cannot start playback on a device"), and a rule that fires on honest
 * output is a rule that gets deleted the first time it blocks a release.
 *
 * Matching is a case-insensitive SUBSTRING test, not a word-boundary one, so
 * "Casting" inside a longer sentence is caught and no clever spacing evades it.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaHonesty
{
    /**
     * Phrases that assert device control this skill does not have.
     *
     * @var list<string>
     */
    public const BANNED_TERMS = [
        // Claims playback is under way somewhere.
        'now playing',
        'playing on',
        'started playback',
        // Claims the skill can route media to a device.
        'casting',
        'cast to',
        'chromecast',
        'roku',
        'airplay',
        // Claims a specific screen is the target.
        'on your tv',
        'on your television',
    ];

    /**
     * Which banned terms `$template` contains.
     *
     * @param string $template A speech/card TEMPLATE — never an interpolated
     *        string, and never user data. See the class docblock.
     *
     * @return list<string> The matching entries of {@see BANNED_TERMS}, in the
     *         order they are declared. Empty means the template is clean.
     */
    public static function violations(string $template): array
    {
        $haystack = strtolower($template);

        $found = [];
        foreach (self::BANNED_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                $found[] = $term;
            }
        }

        return $found;
    }
}
