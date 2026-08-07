<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use LogicException;

use function implode;
use function strtr;

/**
 * The ONLY builder of Alexa response envelopes in this codebase (S91).
 *
 * ## The structural half of the honesty criterion
 *
 * **There is deliberately no way to emit `directives`, `AudioPlayer` or
 * `VideoApp` through this class.** No method takes them, no parameter carries
 * them, no field holds them, and no code path below writes a key by any name a
 * caller supplies. The response body this class produces has a fixed shape:
 * `version`, and a `response` object containing at most `outputSpeech`,
 * `reprompt`, `card` and `shouldEndSession`. `card` is built by
 * {@see simpleCard()} / {@see linkAccount()} and can only ever be Amazon's
 * `Simple` or `LinkAccount` type.
 *
 * That is not a stylistic preference; it is the load-bearing guarantee. Alexa's
 * `AudioPlayer.Play` and `VideoApp.Launch` directives are how a skill actually
 * starts media on a device. The Phlix skill cannot do that — playback is a later
 * step and casting is not built at all — so the way to make sure the skill never
 * CLAIMS to is to make the claim unrepresentable, rather than to rely on nobody
 * adding one. A future step that genuinely implements playback must change this
 * class deliberately, and will trip the response-shape assertion in the suite
 * when it does; it cannot happen by a controller quietly appending a key.
 *
 * The lexical half of the criterion lives in {@see AlexaHonesty} and is applied
 * by {@see render()}.
 *
 * ## Why `render()` checks the template and not the result
 *
 * `render()` runs {@see AlexaHonesty::violations()} over `$template` BEFORE
 * substituting, and throws {@see LogicException} on any hit. Checking after
 * substitution would mean a user whose library contains a film called *Roku*
 * could not ask about it: the interpolated sentence would trip the detector and
 * the request would 400. The claim being policed belongs to the skill, and the
 * skill's claim is the template.
 *
 * A `LogicException` is the right failure mode because a dirty template is a
 * programming error that must never reach production, not a runtime condition:
 * every template is a compile-time constant in {@see AlexaPhrases}, so the suite
 * sweeps them all and the throw can only fire for a phrase somebody built by
 * hand. {@see \Phlix\Hub\Http\Controllers\AlexaSkillController} does not catch
 * it; the worker's request-level handler turns it into a 500, which is the
 * correct outcome for "the skill was about to lie".
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaSpeech
{
    /** The response envelope version Amazon expects. */
    public const VERSION = '1.0';

    /** The only `outputSpeech` type this skill emits. */
    public const SPEECH_TYPE = 'PlainText';

    /** Amazon's plain title+body home-card type. */
    public const CARD_SIMPLE = 'Simple';

    /** Amazon's "prompt the user to link their account" card type. */
    public const CARD_LINK_ACCOUNT = 'LinkAccount';

    /**
     * Interpolate a template after proving it makes no device-control claim.
     *
     * @param string                    $template One of the {@see AlexaPhrases}
     *        constants. Checked by {@see AlexaHonesty::violations()} first.
     * @param array<string, string|int> $values   `placeholder => value`, without
     *        the braces. Values are NOT checked against the honesty rules — see
     *        the class docblock.
     *
     * @throws LogicException When `$template` contains a banned term.
     */
    public static function render(string $template, array $values = []): string
    {
        $violations = AlexaHonesty::violations($template);
        if ($violations !== []) {
            throw new LogicException(
                'Alexa speech template claims device control this skill does not have: '
                . implode(', ', $violations),
            );
        }

        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * A statement that ends the session.
     *
     * @param string                                                 $text Already-rendered speech.
     * @param array{type: string, title?: string, content?: string}|null $card Optional home card.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    public static function tell(string $text, ?array $card = null): array
    {
        return self::envelope($text, null, $card, true);
    }

    /**
     * A question that keeps the session open.
     *
     * @param string $text     Already-rendered speech.
     * @param string $reprompt Already-rendered re-prompt.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    public static function ask(string $text, string $reprompt): array
    {
        return self::envelope($text, $reprompt, null, false);
    }

    /**
     * A statement plus Amazon's account-linking card.
     *
     * The card has no title and no content by Amazon's definition — the Alexa app
     * renders the skill's own linking prompt — so there is nothing here for a
     * caller to inject text into.
     *
     * @param string $text Already-rendered speech.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    public static function linkAccount(string $text): array
    {
        return self::envelope($text, null, ['type' => self::CARD_LINK_ACCOUNT], true);
    }

    /**
     * The empty envelope for `SessionEndedRequest`.
     *
     * Amazon forbids a response body with speech on that request type, so this
     * carries the version and `shouldEndSession` and nothing else. Returning the
     * capability statement here instead would make the skill talk after the user
     * has already gone.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    public static function silent(): array
    {
        return [
            'version' => self::VERSION,
            'response' => ['shouldEndSession' => true],
        ];
    }

    /**
     * Amazon's `Simple` home card.
     *
     * @param string $title   Already-rendered card title.
     * @param string $content Already-rendered card body.
     *
     * @return array{type: string, title: string, content: string}
     */
    public static function simpleCard(string $title, string $content): array
    {
        return [
            'type' => self::CARD_SIMPLE,
            'title' => $title,
            'content' => $content,
        ];
    }

    /**
     * Assemble the envelope.
     *
     * The single place a `response` object is built, so the set of keys it can
     * contain is readable in one screen — which is what makes the "no directives"
     * claim in the class docblock checkable by reading rather than by trusting.
     *
     * @param string                                                     $text
     * @param string|null                                                $reprompt
     * @param array{type: string, title?: string, content?: string}|null $card
     * @param bool                                                       $endSession
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private static function envelope(string $text, ?string $reprompt, ?array $card, bool $endSession): array
    {
        $response = [
            'outputSpeech' => [
                'type' => self::SPEECH_TYPE,
                'text' => $text,
            ],
        ];

        if ($reprompt !== null) {
            $response['reprompt'] = [
                'outputSpeech' => [
                    'type' => self::SPEECH_TYPE,
                    'text' => $reprompt,
                ],
            ];
        }

        if ($card !== null) {
            $response['card'] = $card;
        }

        $response['shouldEndSession'] = $endSession;

        return [
            'version' => self::VERSION,
            'response' => $response,
        ];
    }
}
