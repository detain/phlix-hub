<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use Phlix\Hub\Alexa\AlexaHonesty;
use Phlix\Hub\Alexa\AlexaPhrases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * S91 — the defects this suite catches in {@see AlexaHonesty} / {@see AlexaPhrases}.
 *
 * **1. A banned term that can never fire.** A detector list is free safety only
 * if every entry actually matches something. A typo (`'now  playing'` with two
 * spaces), a stray leading space, or an entry written in a case the matcher does
 * not fold would sit in `BANNED_TERMS` looking protective while matching
 * nothing, and "the sweep found no violations" would keep reporting green. So
 * there is **one control per term**: {@see CONTROLS} pairs each entry with a
 * sentence a dishonest skill would actually say, and
 * {@see testEveryBannedTermHasAControl()} pins the two lists in an exact
 * bijection so a term added without a control reds the build rather than
 * quietly joining the decorative set.
 *
 * **2. A sweep that measures nothing.** `AlexaPhrases::all()` is derived by
 * reflection, so it cannot go stale — but it CAN go empty (a refactor that moved
 * the constants, or a filter that rejected them all), and an empty list passes
 * "every phrase is clean" perfectly. The count floor is therefore asserted
 * BEFORE the cleanliness sweep, so "nothing was measured" reports as its own
 * failure.
 *
 * **3. A detector narrower than its rule.** The matcher is a case-insensitive
 * SUBSTRING test, not a word-boundary one. `"Casting"` inside a longer sentence,
 * mixed case, and a term glued to punctuation are all asserted, because a
 * word-boundary regex — the natural "improvement" — would stop catching
 * `"Chromecast-ing"` and the suite must notice.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class AlexaHonestyTest extends TestCase
{
    /**
     * One crafted sentence per banned term.
     *
     * Each sentence is written so it trips EXACTLY its own term and no other —
     * the assertion is `assertSame([$term], …)`, not "contains". That is what
     * makes each row evidence about its own entry: a "contains" assertion would
     * still pass if `'cast to'` never matched but `'casting'` happened to.
     *
     * @var array<string, string>
     */
    private const CONTROLS = [
        'now playing' => 'Now Playing your requested film.',
        'playing on' => 'It is playing on the big screen for you.',
        'started playback' => 'I started playback of that film.',
        'casting' => 'Casting it over to the lounge.',
        'cast to' => 'I will cast to the lounge speaker.',
        'chromecast' => 'Sent it to the Chromecast in the kitchen.',
        'roku' => 'Opening it on the Roku box.',
        'airplay' => 'Using AirPlay to send it across.',
        'on your tv' => 'It is showing on your TV.',
        'on your television' => 'It is showing on your television.',
    ];

    /** The floor from the step spec: `AlexaPhrases` shipped with 19 templates. */
    private const MINIMUM_PHRASES = 15;

    // ------------------------------------------------------------------
    // One control per banned term
    // ------------------------------------------------------------------

    /**
     * The bijection pin. A term added to `BANNED_TERMS` without a control here
     * is a term nobody proved can fire.
     */
    public function testEveryBannedTermHasAControl(): void
    {
        self::assertSame(
            AlexaHonesty::BANNED_TERMS,
            array_keys(self::CONTROLS),
            'AlexaHonesty::BANNED_TERMS and this suite\'s CONTROLS must stay in an exact, '
            . 'same-order bijection: a term with no crafted sentence has never been shown to '
            . 'match anything, and a control for a term that no longer exists measures nothing.',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bannedTermControls(): iterable
    {
        foreach (self::CONTROLS as $term => $sentence) {
            yield $term => [$term, $sentence];
        }
    }

    #[DataProvider('bannedTermControls')]
    public function testEachBannedTermFiresOnASentenceThatMakesItsClaim(string $term, string $sentence): void
    {
        // The sentence must genuinely contain the term, or this row would be
        // asserting that the detector hallucinates rather than that it fires.
        self::assertTrue(
            str_contains(strtolower($sentence), $term),
            'the crafted control sentence does not actually contain its own term',
        );

        self::assertSame(
            [$term],
            AlexaHonesty::violations($sentence),
            'this banned term never fires, so it protects nothing',
        );
    }

    /**
     * The succeeding control beside the ten failures: a sentence that says the
     * honest thing must produce NO violations. Without it, a `violations()` that
     * returned every term for every input would pass every row above.
     */
    public function testAnHonestSentenceProducesNoViolations(): void
    {
        self::assertSame([], AlexaHonesty::violations(
            'Phlix cannot start playback on a device for you, so open the link where you want to watch.',
        ));
        self::assertSame([], AlexaHonesty::violations(''));
    }

    /**
     * The adjacent words are deliberately NOT banned — the skill has to be able
     * to say "cannot start playback on a device". A rule that fired on the honest
     * sentence would be deleted the first time it blocked a release, so its
     * absence is pinned rather than left to chance.
     */
    public function testWordsMerelyAdjacentToTheTopicAreNotBanned(): void
    {
        foreach (['play', 'watch', 'stream', 'device', 'screen', 'playback'] as $adjacent) {
            self::assertNotContains(
                $adjacent,
                AlexaHonesty::BANNED_TERMS,
                'banning "' . $adjacent . '" would fire on the skill\'s own honest wording',
            );
        }
    }

    // ------------------------------------------------------------------
    // Matching semantics
    // ------------------------------------------------------------------

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertSame(['chromecast'], AlexaHonesty::violations('CHROMECAST'));
        self::assertSame(['chromecast'], AlexaHonesty::violations('ChRoMeCaSt'));
        self::assertSame(['chromecast'], AlexaHonesty::violations('chromecast'));
    }

    /**
     * Substring, not word boundary. `Chromecast-ing` and `Rokus` are exactly the
     * spellings a `\b`-anchored regex would start missing.
     */
    public function testMatchingIsASubstringTestAndNotWordBounded(): void
    {
        self::assertSame(['chromecast'], AlexaHonesty::violations('Chromecast-ing it now.'));
        self::assertSame(['roku'], AlexaHonesty::violations('Sent to both Rokus.'));
        self::assertSame(['casting'], AlexaHonesty::violations('Broadcasting to the lounge.'));
    }

    public function testViolationsAreReturnedInDeclarationOrderAndDeduplicatedByTerm(): void
    {
        self::assertSame(
            ['now playing', 'on your tv'],
            AlexaHonesty::violations('Now playing Inception on your TV.'),
            'the detector must report every distinct term it found, in BANNED_TERMS order',
        );
    }

    // ------------------------------------------------------------------
    // The sweep over every emittable phrase
    // ------------------------------------------------------------------

    /**
     * The floor, asserted before anything sweeps the list. An `all()` that
     * returned `[]` would make every cleanliness assertion trivially true.
     */
    public function testTheEmittablePhraseListMeetsItsFloor(): void
    {
        $all = AlexaPhrases::all();

        self::assertGreaterThanOrEqual(
            self::MINIMUM_PHRASES,
            count($all),
            'AlexaPhrases::all() returned fewer templates than the skill is known to have. '
            . 'Either phrases were deleted, or the reflection derivation stopped seeing them — '
            . 'in which case every honesty sweep below is measuring nothing.',
        );

        foreach ($all as $template) {
            self::assertIsString($template);
            self::assertNotSame('', $template, 'an empty template is a phrase the skill cannot say');
        }
    }

    public function testEveryEmittablePhraseIsHonest(): void
    {
        $dirty = [];
        foreach (AlexaPhrases::all() as $template) {
            $violations = AlexaHonesty::violations($template);
            if ($violations !== []) {
                $dirty[$template] = $violations;
            }
        }

        self::assertSame(
            [],
            $dirty,
            'a phrase the skill can speak claims device control it does not have',
        );
    }

    /**
     * `all()` must actually enumerate the class's own constants, not some
     * unrelated set. Pinning three by identity means a derivation that returned
     * (say) a hard-coded placeholder list would fail here rather than pass the
     * sweep above.
     */
    public function testAllEnumeratesTheDeclaredConstants(): void
    {
        $all = AlexaPhrases::all();

        self::assertContains(AlexaPhrases::CAPABILITY, $all);
        self::assertContains(AlexaPhrases::LINK_ACCOUNT, $all);
        self::assertContains(AlexaPhrases::PLAY_LINK_SPEECH, $all);

        $reflected = (new \ReflectionClass(AlexaPhrases::class))->getConstants();
        $stringConstants = 0;
        /** @var mixed $value */
        foreach ($reflected as $value) {
            if (is_string($value)) {
                $stringConstants++;
            }
        }

        self::assertSame(
            $stringConstants,
            count($all),
            'all() must return every string constant the class declares — no filtering, no omission',
        );
    }
}
