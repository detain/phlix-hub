<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use LogicException;
use Phlix\Hub\Alexa\AlexaPhrases;
use Phlix\Hub\Alexa\AlexaSpeech;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function str_contains;

/**
 * S91 — the defects this suite catches in {@see AlexaSpeech}.
 *
 * **1. The honesty check applied at the wrong moment.** `render()` must check
 * the TEMPLATE and then interpolate. Checking the RESULT instead is the obvious
 * "safer" ordering and it is wrong: a user whose library contains a film called
 * *Roku & Friends* would get a `LogicException` — a 500 inside a resident
 * Workerman worker — for owning the wrong media. Both halves are asserted in
 * this suite, and they are the two halves of one decision: a dirty template
 * throws, a dirty VALUE in a clean template does not, and the value survives
 * verbatim into the output. Either assertion alone would be satisfied by the
 * wrong ordering.
 *
 * **2. An envelope that grew a key.** The response shape is the STRUCTURAL half
 * of the honesty criterion — there is deliberately no way to emit `directives`,
 * `AudioPlayer` or `VideoApp` through this class. Key sets are therefore
 * asserted with `assertSame()` over `array_keys()`, never with
 * `assertArrayHasKey()`: a superset must fail. `assertArrayHasKey('outputSpeech')`
 * passes just as happily on an envelope that also carries a `directives` array,
 * which is precisely the change this suite exists to catch.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class AlexaSpeechTest extends TestCase
{
    // ------------------------------------------------------------------
    // render(): the template is checked, the values are not
    // ------------------------------------------------------------------

    public function testRenderThrowsOnATemplateThatClaimsDeviceControl(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('claims device control');

        AlexaSpeech::render('Now playing {title} on your TV.', ['title' => 'Inception']);
    }

    /**
     * Each banned term must be able to stop a template on its own, not merely
     * as part of one famous sentence.
     */
    public function testRenderThrowsOnEachIndividuallyDishonestTemplate(): void
    {
        $dishonest = [
            'I started playback of {title}.',
            'Casting {title} now.',
            'Sent {title} to the Chromecast.',
        ];

        foreach ($dishonest as $bad) {
            try {
                AlexaSpeech::render($bad, ['title' => 'Dune']);
                self::fail('a dishonest template rendered without throwing: ' . $bad);
            } catch (LogicException $e) {
                self::assertStringContainsString('claims device control', $e->getMessage());
            }
        }
    }

    /**
     * The complementary half: a VALUE containing a banned word is fine, and the
     * value reaches the output unchanged.
     *
     * A library legitimately contains a film called *Roku & Friends*. If the
     * check ran after interpolation, asking about it would 500.
     */
    public function testAValueContainingABannedWordIsInterpolatedRatherThanRefused(): void
    {
        $rendered = AlexaSpeech::render(AlexaPhrases::TITLE_NOT_FOUND, ['title' => 'Roku & Friends']);

        self::assertSame('I could not find Roku & Friends in your library.', $rendered);
        self::assertTrue(
            str_contains($rendered, 'Roku & Friends'),
            'the user\'s own title must survive into the spoken sentence verbatim',
        );
    }

    public function testEveryBannedWordIsAcceptableAsAValue(): void
    {
        foreach (['Chromecast', 'AirPlay', 'Now Playing', 'On Your TV'] as $awkwardTitle) {
            self::assertSame(
                'I could not find ' . $awkwardTitle . ' in your library.',
                AlexaSpeech::render(AlexaPhrases::TITLE_NOT_FOUND, ['title' => $awkwardTitle]),
            );
        }
    }

    public function testRenderSubstitutesEveryPlaceholderAndLeavesTheRestAlone(): void
    {
        self::assertSame(
            'Here is what I have for Dune. It runs 155 minutes.',
            AlexaSpeech::render(AlexaPhrases::TITLE_FACTS, [
                'title' => 'Dune',
                'facts' => AlexaSpeech::render(AlexaPhrases::FACT_RUNTIME, ['minutes' => 155]),
            ]),
        );

        // Integers are accepted and stringified; an unsupplied placeholder is
        // left as-is rather than becoming the word "null".
        self::assertSame('It came out in 2021.', AlexaSpeech::render(AlexaPhrases::FACT_YEAR, ['year' => 2021]));
        self::assertSame('It came out in {year}.', AlexaSpeech::render(AlexaPhrases::FACT_YEAR));
    }

    // ------------------------------------------------------------------
    // Envelope shapes — whole key sets, so a superset fails
    // ------------------------------------------------------------------

    public function testTellProducesExactlyTheStatementEnvelope(): void
    {
        $envelope = AlexaSpeech::tell('Goodbye.');

        self::assertSame(['version', 'response'], array_keys($envelope));
        self::assertSame('1.0', $envelope['version']);
        self::assertSame(['outputSpeech', 'shouldEndSession'], array_keys($envelope['response']));
        self::assertSame(
            ['type' => 'PlainText', 'text' => 'Goodbye.'],
            $envelope['response']['outputSpeech'],
        );
        self::assertTrue($envelope['response']['shouldEndSession']);
    }

    public function testTellWithACardCarriesExactlyTheCardKeys(): void
    {
        $envelope = AlexaSpeech::tell('Here.', AlexaSpeech::simpleCard('Title', 'Body'));

        self::assertSame(['outputSpeech', 'card', 'shouldEndSession'], array_keys($envelope['response']));
        self::assertSame(
            ['type' => 'Simple', 'title' => 'Title', 'content' => 'Body'],
            $envelope['response']['card'],
        );
    }

    public function testAskProducesExactlyTheQuestionEnvelope(): void
    {
        $envelope = AlexaSpeech::ask('What next?', 'Say a title.');

        self::assertSame(['version', 'response'], array_keys($envelope));
        self::assertSame(['outputSpeech', 'reprompt', 'shouldEndSession'], array_keys($envelope['response']));
        self::assertSame(
            ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Say a title.']],
            $envelope['response']['reprompt'],
        );
        self::assertFalse(
            $envelope['response']['shouldEndSession'],
            'a question that closes the session is a question the user cannot answer',
        );
    }

    public function testLinkAccountProducesExactlyTheLinkingEnvelope(): void
    {
        $envelope = AlexaSpeech::linkAccount('Link your account.');

        self::assertSame(['version', 'response'], array_keys($envelope));
        self::assertSame(['outputSpeech', 'card', 'shouldEndSession'], array_keys($envelope['response']));
        // Amazon defines the LinkAccount card as type-only; a title or content
        // here would be a text channel this class does not intend to have.
        self::assertSame(['type' => 'LinkAccount'], $envelope['response']['card']);
        self::assertTrue($envelope['response']['shouldEndSession']);
    }

    public function testSilentProducesTheEmptyEnvelopeWithNoSpeechAtAll(): void
    {
        $envelope = AlexaSpeech::silent();

        self::assertSame(['version', 'response'], array_keys($envelope));
        self::assertSame(['shouldEndSession'], array_keys($envelope['response']));
        self::assertTrue($envelope['response']['shouldEndSession']);
    }

    public function testSimpleCardProducesExactlyTheSimpleCardKeys(): void
    {
        self::assertSame(
            ['type', 'title', 'content'],
            array_keys(AlexaSpeech::simpleCard('T', 'C')),
        );
    }

    /**
     * The structural claim, asserted rather than trusted: no builder on this
     * class emits a device-control key at any depth. The recursive sweep itself
     * is exercised (and shown to be capable of failing) in
     * {@see \Phlix\Hub\Tests\Unit\Http\Controllers\AlexaSkillControllerTest}.
     */
    public function testNoBuilderEmitsADeviceControlKey(): void
    {
        $envelopes = [
            AlexaSpeech::tell('a'),
            AlexaSpeech::tell('a', AlexaSpeech::simpleCard('b', 'c')),
            AlexaSpeech::ask('a', 'b'),
            AlexaSpeech::linkAccount('a'),
            AlexaSpeech::silent(),
        ];

        foreach ($envelopes as $index => $envelope) {
            $keys = [];
            self::collectKeys($envelope, $keys);
            foreach (['directives', 'audioplayer', 'videoapp'] as $forbidden) {
                self::assertNotContains(
                    $forbidden,
                    $keys,
                    'builder #' . $index . ' emitted a device-control key',
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function collectKeys(array $value, array &$keys): void
    {
        /** @var mixed $child */
        foreach ($value as $key => $child) {
            $keys[] = \strtolower((string) $key);
            if (\is_array($child)) {
                self::collectKeys($child, $keys);
            }
        }
    }
}
