<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaHonesty;
use Phlix\Hub\Alexa\AlexaPhrases;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\AlexaSkillController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\SyncPlay\PendingCommandPusherInterface;
use Phlix\Hub\Tests\Support\Alexa\AlexaEnvelopeHonesty;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_unique;
use function count;
use function implode;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function sprintf;
use function str_repeat;

/**
 * S91 — the HONESTY PIN for the Alexa skill, plus the intent dispatch table.
 *
 * ## The defect this suite exists to catch
 *
 * The skill must never claim device-control capability it does not have. It
 * cannot start, stop or route playback and it cannot cast. A response that says
 * otherwise — or that carries one of Amazon's `directives` / `AudioPlayer` /
 * `VideoApp` keys, which is how a skill actually starts media on a device — is
 * telling a user that a button exists which does not, and their only way to
 * discover the truth is to try it and watch nothing happen. No status-code
 * assertion can see that: the dishonest envelope is a perfectly valid 200.
 *
 * So {@see honestyViolations()} walks EVERY decoded envelope this controller
 * produces, at every depth, and reports:
 *
 *  - any key named `directives`, `AudioPlayer` or `VideoApp` (case-insensitively,
 *    because Amazon's own spelling is mixed case and `Directives` would slip past
 *    an exact-match check); and
 *  - any string leaf that trips {@see AlexaHonesty::violations()}.
 *
 * ## Why there is an anti-vacuity control, and why it asserts rather than eyeballs
 *
 * A detector that can never fire is the recorded failure mode in this programme:
 * a sweep over N envelopes finding zero violations reads identically whether the
 * envelopes are honest or the walker is broken. {@see
 * testTheHonestyDetectorFiresOnEnvelopesThatClaimDeviceControl()} therefore feeds
 * the SAME helper hand-built envelopes that DO claim device control — one with a
 * `directives` key, one whose speech says "Now playing Inception on your TV" —
 * and asserts the returned list is non-empty and names the right thing. The clean
 * control sits beside them so "fires on everything" fails too.
 *
 * ⚠ Note the deliberate scope: the walker inspects string LEAVES, so it would
 * also flag a user's own film called *Roku*. The library fixtures here are
 * therefore deliberately clean, so the sweep measures the SKILL's words. That the
 * user's title is NOT policed is asserted separately, in
 * {@see testATitleContainingABannedWordIsAnsweredRatherThanRefused()}.
 *
 * ## The floor
 *
 * {@see AlexaSkillController::SUPPORTED_INTENTS} is pinned at >= 7 entries, and
 * every entry is driven for real. Deleting an arm of the dispatch match would
 * silently route that intent to the fallback refusal — still a 200, still honest,
 * and invisible to everything except an assertion that each intent produces a
 * DISTINCT, non-fallback answer. Stop and Cancel legitimately share one, and that
 * sharing is asserted rather than tolerated.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class AlexaSkillControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private const SECRET = 'S91-alexa-skill-controller-secret-0123456789';

    private const USER_ID = 'user-linked-1';

    private const SERVER_ID = 'srv-alexa-1';

    private const HUB_BASE_URL = 'https://hub.phlix.test/';

    /**
     * A placeholder the request builder swaps for a freshly minted token.
     *
     * Bodies are built statically (they are shared by the sweep), but the token
     * has to come from this test's own {@see JwtHandler} instance, so the swap
     * happens at request-build time.
     */
    private const TOKEN_PLACEHOLDER = '{{ACCESS_TOKEN}}';

    private JwtHandler $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JwtHandler(self::SECRET);
    }

    // ==================================================================
    // A. The honesty pin
    // ==================================================================

    /**
     * ANTI-VACUITY CONTROL. The walker must be able to FAIL.
     *
     * Each case asserts a non-empty violation list — collected, not eyeballed —
     * and the clean case asserts an empty one, so a walker that flagged
     * everything would fail here too.
     */
    public function testTheHonestyDetectorFiresOnEnvelopesThatClaimDeviceControl(): void
    {
        $clean = [
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'I could not find Dune in your library.'],
                'shouldEndSession' => true,
            ],
        ];
        self::assertSame([], self::honestyViolations($clean), 'control: an honest envelope must be clean');

        $withDirectives = $clean;
        $withDirectives['response']['directives'] = [
            ['type' => 'AudioPlayer.Play', 'playBehavior' => 'REPLACE_ALL'],
        ];
        $found = self::honestyViolations($withDirectives);
        self::assertNotEmpty($found, 'the walker failed to see a directives key — it can never fire');
        self::assertStringContainsString('directives', implode(' | ', $found));

        // Mixed case, nested two levels down: exactly the spellings an
        // exact-match, top-level-only check would miss.
        $withAudioPlayer = $clean;
        $withAudioPlayer['response']['card'] = ['type' => 'Simple', 'AudioPlayer' => ['token' => 'x']];
        $found = self::honestyViolations($withAudioPlayer);
        self::assertNotEmpty($found, 'the walker failed to see a nested AudioPlayer key');
        self::assertStringContainsString('audioplayer', implode(' | ', $found));

        $withVideoApp = $clean;
        $withVideoApp['response']['VideoApp'] = ['Launch' => []];
        self::assertNotEmpty(self::honestyViolations($withVideoApp), 'the walker failed to see a VideoApp key');

        $lying = $clean;
        $lying['response']['outputSpeech']['text'] = 'Now playing Inception on your TV.';
        $found = self::honestyViolations($lying);
        self::assertNotEmpty($found, 'the walker failed to see a spoken device-control claim');
        self::assertStringContainsString('now playing', implode(' | ', $found));
        self::assertStringContainsString('on your tv', implode(' | ', $found));
    }

    /**
     * The sweep. Every dispatched request type and intent, driven through the
     * REAL controller, in BOTH the unlinked and the fully-working states — so
     * the refusal wording and the answer wording are both examined.
     */
    public function testEveryDispatchedRequestProducesAnHonestEnvelope(): void
    {
        $swept = 0;

        foreach (['unlinked', 'linked'] as $state) {
            $controller = $state === 'linked' ? $this->workingController() : $this->unlinkedController();

            foreach (self::everyDispatchedBody() as $label => $body) {
                $response = $controller->handle($this->request($body));

                self::assertSame(200, $response->statusCode, $label . ' must answer with an Alexa envelope');

                $envelope = self::decode($response);
                self::assertSame(
                    [],
                    self::honestyViolations($envelope),
                    sprintf('%s (%s) claims device control the skill does not have', $label, $state),
                );
                $swept++;
            }
        }

        // Non-vacuity on the sweep itself: 2 states x (7 intents + 4 others).
        // 7 since S93 added PhlixPlayInAppIntent.
        self::assertSame(22, $swept, 'the honesty sweep examined fewer envelopes than it was given');
    }

    /**
     * The floor, and the proof that each entry is genuinely reached.
     */
    public function testTheSupportedIntentTableMeetsItsFloorAndEveryEntryIsReached(): void
    {
        $intents = AlexaSkillController::SUPPORTED_INTENTS;

        self::assertGreaterThanOrEqual(
            7,
            count($intents),
            'SUPPORTED_INTENTS has shrunk below the set S91 shipped; an intent was dropped from the '
            . 'dispatch table and now falls through to the fallback refusal',
        );
        self::assertSame(
            $intents,
            array_values(array_unique($intents)),
            'a duplicated intent name means one arm of the dispatch match is unreachable',
        );

        $controller = $this->workingController();

        $speech = [];
        foreach ($intents as $intent) {
            $response = $controller->handle($this->request(self::intentBody($intent, ['Title' => 'Inception'])));
            self::assertSame(200, $response->statusCode);
            $speech[$intent] = self::speechOf(self::decode($response));
        }

        $fallback = $speech['AMAZON.FallbackIntent'];
        self::assertSame(AlexaPhrases::UNSUPPORTED_REQUEST, $fallback);

        // Stop and Cancel legitimately share one answer; that is the design, so
        // it is asserted rather than merely allowed for.
        self::assertSame(
            $speech['AMAZON.StopIntent'],
            $speech['AMAZON.CancelIntent'],
            'Stop and Cancel are the same user intention and must say the same thing',
        );

        $mustBeTheirOwnAnswer = [
            'PhlixTitleRuntimeIntent',
            'PhlixPlayLinkIntent',
            'PhlixPlayInAppIntent',
            'AMAZON.HelpIntent',
            'AMAZON.StopIntent',
        ];

        foreach ($mustBeTheirOwnAnswer as $named) {
            self::assertNotSame(
                $fallback,
                $speech[$named],
                $named . ' fell through to the fallback refusal — its dispatch arm is gone',
            );
        }

        self::assertCount(
            6,
            array_unique(array_values($speech)),
            'the seven supported intents must produce six distinct answers (Stop == Cancel)',
        );
    }

    /**
     * S93 — the EXACT pin, beside (not instead of) the floor above.
     *
     * ## Why a floor is not enough for this particular list
     *
     * A `>=` floor catches deletion. It cannot catch ADDITION, and addition is
     * the change that matters here: every entry in this table is a capability the
     * skill claims OUT LOUD, in {@see AlexaPhrases::CAPABILITY} ("Phlix can
     * answer questions…, send you a link…, and start one in a Phlix app you
     * already have open") and again in {@see AlexaPhrases::UNSUPPORTED_REQUEST}.
     * An intent added here without that decision leaves the capability statement
     * describing a skill that no longer exists — an understatement, which is the
     * same class of defect as an overstatement: both describe a product the user
     * is not holding.
     *
     * The list is hard-coded here rather than derived, precisely so that changing
     * production requires changing this literal — the moment a reader has to
     * decide what the skill now claims it can do.
     */
    public function testTheSupportedIntentTableIsPinnedExactly(): void
    {
        self::assertSame(
            [
                'PhlixTitleRuntimeIntent',
                'PhlixPlayLinkIntent',
                'PhlixPlayInAppIntent',
                'AMAZON.HelpIntent',
                'AMAZON.StopIntent',
                'AMAZON.CancelIntent',
                'AMAZON.FallbackIntent',
            ],
            AlexaSkillController::SUPPORTED_INTENTS,
            'SUPPORTED_INTENTS no longer matches the pinned list, in order. Adding or removing an '
            . 'intent is a decision about WHAT THE SKILL CLAIMS IT CAN DO, not a routing detail, and '
            . 'it must be made deliberately: update this literal AND re-check both capability '
            . 'sentences (AlexaPhrases::CAPABILITY and AlexaPhrases::UNSUPPORTED_REQUEST) so the '
            . 'skill still describes itself accurately. A removal additionally routes that intent to '
            . 'the fallback refusal, which is still a valid 200 and invisible to every status check.',
        );
    }

    /**
     * An unknown intent and an unknown REQUEST TYPE both land on the honest
     * refusal — and it is the same sentence, so a user cannot infer which of the
     * two the skill thought it received.
     */
    public function testAnUnknownIntentAndAnUnknownRequestTypeBothGetTheHonestRefusal(): void
    {
        $controller = $this->workingController();

        $unknownIntent = self::speechOf(self::decode(
            $controller->handle($this->request(self::intentBody('SomeIntentNobodyWrote'))),
        ));
        $unknownType = self::speechOf(self::decode(
            $controller->handle($this->request(self::typedBody('CanFulfillIntentRequest'))),
        ));

        self::assertSame(AlexaPhrases::UNSUPPORTED_REQUEST, $unknownIntent);
        self::assertSame(AlexaPhrases::UNSUPPORTED_REQUEST, $unknownType);
    }

    /**
     * The distinction {@see AlexaHonesty} exists to make: the SKILL's words are
     * policed, the USER's data is not. A film called *Roku & Friends* must be
     * answerable — a `LogicException` here would be a 500 inside a resident
     * worker for owning the wrong media.
     */
    public function testATitleContainingABannedWordIsAnsweredRatherThanRefused(): void
    {
        $controller = $this->workingController('Roku & Friends');

        $response = $controller->handle($this->request(
            self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Roku & Friends']),
        ));

        self::assertSame(200, $response->statusCode);
        $speech = self::speechOf(self::decode($response));
        self::assertStringContainsString('Roku & Friends', $speech);
        // And the walker WOULD have flagged it — which is exactly why the sweep
        // above uses clean fixtures. Stated as an assertion so the boundary
        // between the two rules is pinned rather than described.
        self::assertNotEmpty(AlexaHonesty::violations($speech));
    }

    // ==================================================================
    // B. Request handling
    // ==================================================================

    public function testAnUnparseableBodyIsA400WithNoSpeech(): void
    {
        $response = $this->unlinkedController()->handle($this->request('{nope'), ['ignored' => 'x']);

        self::assertSame(400, $response->statusCode);
        self::assertSame(
            ['error' => 'Bad Request', 'code' => 'alexa.malformed_envelope'],
            self::decode($response),
        );
    }

    public function testASessionEndedRequestIsAnsweredWithSilence(): void
    {
        $response = $this->workingController()->handle($this->request(self::typedBody('SessionEndedRequest')));

        self::assertSame(200, $response->statusCode);
        self::assertSame(
            ['version' => '1.0', 'response' => ['shouldEndSession' => true]],
            self::decode($response),
            'Amazon forbids speech on a session-ended notification',
        );
    }

    public function testALaunchRequestSpeaksTheCapabilityStatementAndKeepsTheSessionOpen(): void
    {
        $envelope = self::decode(
            $this->workingController()->handle($this->request(self::typedBody('LaunchRequest'))),
        );

        self::assertSame(AlexaPhrases::CAPABILITY, self::speechOf($envelope));
        self::assertFalse(self::responseOf($envelope)['shouldEndSession']);
        self::assertSame(
            ['outputSpeech', 'reprompt', 'shouldEndSession'],
            array_keys(self::responseOf($envelope)),
        );
    }

    // ==================================================================
    // C. The refusals, each with its own sentence
    // ==================================================================

    public function testAnUnlinkedAccountGetsTheLinkAccountCard(): void
    {
        $envelope = self::decode($this->unlinkedController()->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::LINK_ACCOUNT, self::speechOf($envelope));
        self::assertSame(['type' => 'LinkAccount'], self::responseOf($envelope)['card']);
    }

    /**
     * Both library intents must refuse an unlinked caller IDENTICALLY. A user
     * who is not linked must not learn that they are linked from one intent and
     * not the other.
     */
    public function testBothLibraryIntentsRefuseAnUnlinkedCallerWithTheSameEnvelope(): void
    {
        $controller = $this->unlinkedController();

        $runtime = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));
        $link = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixPlayLinkIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame($runtime, $link);
    }

    public function testAMissingTitleSlotRepromptsRatherThanGuessing(): void
    {
        $envelope = self::decode($this->workingController()->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent')),
        ));

        self::assertSame(AlexaPhrases::MISSING_TITLE, self::speechOf($envelope));
        self::assertFalse(self::responseOf($envelope)['shouldEndSession'], 'a re-prompt must keep the session open');
    }

    public function testAWhitespaceOnlyTitleSlotIsTreatedAsMissing(): void
    {
        $envelope = self::decode($this->workingController()->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => '   '])),
        ));

        self::assertSame(AlexaPhrases::MISSING_TITLE, self::speechOf($envelope));
    }

    public function testNoClaimedServerGetsItsOwnSentence(): void
    {
        $controller = $this->controllerWith([], static fn (): array => [200, '{}']);

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::NO_SERVERS, self::speechOf($envelope));
    }

    public function testAClaimedButDisconnectedServerGetsItsOwnSentence(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, false)],
            static fn (): array => [200, '{}'],
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::NO_CONNECTED_SERVER, self::speechOf($envelope));
    }

    public function testTheFirstRelayActiveServerIsChosenEvenWhenItIsNotFirstInTheList(): void
    {
        $controller = $this->controllerWith(
            [$this->dto('srv-offline', false), $this->dto(self::SERVER_ID, true)],
            self::libraryReplies('Inception'),
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertStringContainsString('Inception', self::speechOf($envelope));
    }

    public function testASearchThatFailsIsSpokenAsAFailureNotAsAnEmptyLibrary(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static fn (): array => [500, '{"error":"boom"}'],
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::LOOKUP_FAILED, self::speechOf($envelope));
    }

    public function testASearchWithNoHitsSaysSoAndNamesTheTitle(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static fn (): array => [200, '{"items":[]}'],
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Solaris'])),
        ));

        self::assertSame('I could not find Solaris in your library.', self::speechOf($envelope));
    }

    public function testASearchRowWithoutAnIdOrNameIsARefusalRatherThanAGuess(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static fn (): array => [200, '{"items":[{"title":"no id, no name"}]}'],
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::LOOKUP_FAILED, self::speechOf($envelope));
    }

    public function testADetailReadThatCarriesNoItemIsARefusal(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static function (string $path): array {
                return $path === '/api/v1/media/search'
                    ? [200, '{"items":[{"id":"m-1","name":"Inception"}]}']
                    : [200, '{"nothing":true}'];
            },
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::LOOKUP_FAILED, self::speechOf($envelope));
    }

    public function testADetailReadThatFailsOutrightIsARefusal(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static function (string $path): array {
                return $path === '/api/v1/media/search'
                    ? [200, '{"items":[{"id":"m-1","name":"Inception"}]}']
                    : [504, '{"error":"Gateway Timeout"}'];
            },
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(AlexaPhrases::LOOKUP_FAILED, self::speechOf($envelope));
    }

    /**
     * A payload whose `items` is not a list at all, and one whose entries are
     * not objects. Both are "no rows", not a crash and not a guess — the relay
     * hands back whatever a paired server sent, which is not this hub's schema
     * to guarantee.
     */
    public function testAMisshapenSearchPayloadIsTreatedAsNoResults(): void
    {
        foreach (['{"items":"nope"}', '{"items":["a string, not a row"]}', '{}'] as $payload) {
            $controller = $this->controllerWith(
                [$this->dto(self::SERVER_ID, true)],
                static fn (): array => [200, $payload],
            );

            $envelope = self::decode($controller->handle(
                $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Solaris'])),
            ));

            self::assertSame(
                'I could not find Solaris in your library.',
                self::speechOf($envelope),
                'payload: ' . $payload,
            );
        }
    }

    /**
     * A `Detail` slot that is present but says nothing (whitespace) must behave
     * like an absent one. The envelope keeps whitespace-only slot values —
     * only EMPTY ones are dropped — so this branch is genuinely reachable.
     */
    public function testAWhitespaceOnlyDetailSlotSpeaksEveryFact(): void
    {
        $envelope = self::decode($this->workingController()->handle($this->request(
            self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception', 'Detail' => '   ']),
        )));

        self::assertStringContainsString('It runs 148 minutes.', self::speechOf($envelope));
        self::assertStringContainsString('It is rated PG-13.', self::speechOf($envelope));
    }

    /**
     * A JSON round trip through the relay can turn an integer into its numeric
     * STRING spelling. Rejecting `"148"` would silently drop the runtime — the
     * single most-asked fact — with no error anywhere.
     */
    public function testNumericStringFieldsFromTheRelayAreStillSpoken(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static function (string $path): array {
                return $path === '/api/v1/media/search'
                    ? [200, '{"items":[{"id":"m-1","name":"Inception"}]}']
                    : [200, '{"item":{"runtime":"148","year":"2010"}}'];
            },
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(
            'Here is what I have for Inception. It runs 148 minutes. It came out in 2010.',
            self::speechOf($envelope),
        );
    }

    /**
     * A server overview is a paragraph and Alexa reads the whole thing aloud
     * with no way to interrupt except by saying stop.
     */
    public function testALongOverviewIsClampedWithAnEllipsis(): void
    {
        $overview = str_repeat('word ', 200);
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static function (string $path) use ($overview): array {
                return $path === '/api/v1/media/search'
                    ? [200, '{"items":[{"id":"m-1","name":"Inception"}]}']
                    : [200, (string) json_encode(['item' => ['overview' => $overview]])];
            },
        );

        $speech = self::speechOf(self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        )));

        self::assertStringEndsWith('…', $speech);
        // "Here is what I have for Inception. " (35) + 320 clamped chars, with the
        // trailing space trimmed before the ellipsis is appended.
        self::assertSame(355, mb_strlen($speech));
        self::assertLessThan(mb_strlen($overview), mb_strlen($speech));
    }

    public function testAnItemWithNoSpeakableFieldsSaysSoRatherThanInventingFacts(): void
    {
        $controller = $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            static function (string $path): array {
                return $path === '/api/v1/media/search'
                    ? [200, '{"items":[{"id":"m-1","name":"Inception"}]}']
                    : [200, '{"item":{"id":"m-1","runtime":0,"year":null,"rating":"","overview":"  "}}'];
            },
        );

        $envelope = self::decode($controller->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(
            'I found Inception in your library, but I do not have any details for it.',
            self::speechOf($envelope),
        );
    }

    /**
     * The "server list failed" arm of `firstConnectedServerId()` is UNREACHABLE
     * through this controller — proved from the two runtime facts that make it
     * so, rather than left as an unexplained hole in the coverage report.
     *
     *  1. {@see ServerListController::listServers()} has exactly two outcomes: a
     *     401 when `Request::$userId` is empty, and a 200 otherwise. Asserted
     *     below against the real controller.
     *  2. {@see AlexaMediaGateway} sets that `userId` from the value
     *     {@see AlexaAccountLink} resolved, and `resolve()` never returns the
     *     empty string — it returns null instead, which the caller answers with
     *     the account-linking prompt long before any gateway exists.
     *
     * If either fact ever stops holding, the arm becomes live and this test is
     * the thing that says so.
     */
    public function testTheServerListCanOnlyFailForAnEmptyUserIdWhichTheAccountLinkNeverProduces(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServersForUser')->willReturn([]);
        $list = new ServerListController($info);

        // Fact 1, both halves.
        $anonymous = new Request();
        $anonymous->userId = '';
        self::assertSame(401, $list->listServers($anonymous)->statusCode);

        $identified = new Request();
        $identified->userId = self::USER_ID;
        self::assertSame(200, $list->listServers($identified)->statusCode);

        // Fact 2: a token whose subject is the empty string resolves to NULL,
        // so an empty user id can never reach the gateway.
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(true);
        self::assertNull(
            (new AlexaAccountLink($this->jwt, $users))->resolve($this->jwt->createAccessToken('')),
        );
    }

    // ==================================================================
    // D. The two library answers
    // ==================================================================

    public function testTheQaIntentSpeaksOnlyTheFieldsTheServerActuallyReturned(): void
    {
        $envelope = self::decode($this->workingController()->handle(
            $this->request(self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception'])),
        ));

        self::assertSame(
            'Here is what I have for Inception. It runs 148 minutes. It came out in 2010. '
            . 'It is rated PG-13. A thief who steals corporate secrets.',
            self::speechOf($envelope),
        );
    }

    public function testTheDetailSlotNarrowsTheAnswerToOneFact(): void
    {
        $controller = $this->workingController();

        foreach (
            [
                'how long' => ['long', 'Here is what I have for Inception. It runs 148 minutes.'],
                'runtime' => ['runtime', 'Here is what I have for Inception. It runs 148 minutes.'],
                'year' => ['released', 'Here is what I have for Inception. It came out in 2010.'],
                'rating' => ['certificate', 'Here is what I have for Inception. It is rated PG-13.'],
                'summary' => ['plot', 'Here is what I have for Inception. A thief who steals corporate secrets.'],
            ] as $label => [$slot, $expected]
        ) {
            $envelope = self::decode($controller->handle($this->request(
                self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception', 'Detail' => $slot]),
            )));

            self::assertSame($expected, self::speechOf($envelope), 'Detail slot "' . $label . '"');
        }
    }

    public function testAnUnrecognisedDetailSlotSpeaksEveryFactRatherThanNothing(): void
    {
        $envelope = self::decode($this->workingController()->handle($this->request(
            self::intentBody('PhlixTitleRuntimeIntent', ['Title' => 'Inception', 'Detail' => 'vibes']),
        )));

        self::assertStringContainsString('It runs 148 minutes.', self::speechOf($envelope));
        self::assertStringContainsString('It came out in 2010.', self::speechOf($envelope));
    }

    /**
     * The play link is a LINK, it is spoken as a link, and the URL is the real
     * hub SPA search route — an intent that spoke a plausible-looking URL nothing
     * serves would be the same dishonesty as claiming playback.
     */
    public function testThePlayLinkIntentReturnsARealResolvableLinkAndSaysItCannotStartPlayback(): void
    {
        $envelope = self::decode($this->workingController()->handle(
            $this->request(self::intentBody('PhlixPlayLinkIntent', ['Title' => 'Inception'])),
        ));

        // S93 qualified the wording ("on a device that does not already have
        // Phlix open") because PhlixPlayInAppIntent now genuinely can start a
        // title in an already-open app. The BEHAVIOUR of this intent is
        // unchanged — still a link, still no playback started from here.
        self::assertSame(
            'I have put a link to Inception in the Alexa app. Phlix cannot start playback on a '
            . 'device that does not already have Phlix open, so open the link where you want to watch.',
            self::speechOf($envelope),
        );

        self::assertSame(
            [
                'type' => 'Simple',
                'title' => 'Phlix link for Inception',
                'content' => "Open this link to find Inception in Phlix and start it yourself. "
                    . "This skill cannot start playback on a device that does not already have "
                    . "Phlix open.\n\n"
                    . 'https://hub.phlix.test/app/search?q=Inception',
            ],
            self::responseOf($envelope)['card'],
            'the card must carry the hub SPA search route with the MATCHED title, single-slashed',
        );
    }

    public function testThePlayLinkUsesTheMatchedTitleAndQueryEncodesIt(): void
    {
        $controller = $this->workingController('Blade Runner 2049 & Friends');

        $envelope = self::decode($controller->handle(
            // The spoken title differs from the matched one; the LINK must use
            // what the library actually holds, not what the microphone heard.
            $this->request(self::intentBody('PhlixPlayLinkIntent', ['Title' => 'blade runner'])),
        ));

        self::assertStringContainsString(
            'https://hub.phlix.test/app/search?q=Blade+Runner+2049+%26+Friends',
            self::stringNode(self::arrayNode(self::responseOf($envelope)['card'])['content']),
        );
    }

    // ==================================================================
    // The recursive honesty walker
    // ==================================================================

    /**
     * Every honesty problem in `$value`, as human-readable strings.
     *
     * ⚠ S93 moved the walker itself to {@see AlexaEnvelopeHonesty} so the
     * `PhlixPlayInAppIntent` suite applies the IDENTICAL rule rather than a
     * second copy of it that could be narrowed on its own. The logic is
     * unchanged, this method is the same seam it always was, and
     * {@see testTheHonestyDetectorFiresOnEnvelopesThatClaimDeviceControl()}
     * still proves — through this method — that the walker can fail.
     *
     * @param array<array-key, mixed> $value
     * @param string                  $path  Dotted path, for the failure message.
     *
     * @return list<string> Empty means the envelope is honest.
     */
    private static function honestyViolations(array $value, string $path = '$'): array
    {
        return AlexaEnvelopeHonesty::violations($value, $path);
    }

    // ==================================================================
    // Bodies
    // ==================================================================

    /**
     * Every request shape the controller dispatches: the seven supported intents,
     * a launch, a session end, an unknown intent and an unknown type.
     *
     * @return array<string, string>
     */
    private static function everyDispatchedBody(): array
    {
        $bodies = [];
        foreach (AlexaSkillController::SUPPORTED_INTENTS as $intent) {
            $bodies['IntentRequest ' . $intent] = self::intentBody($intent, ['Title' => 'Inception']);
        }
        $bodies['LaunchRequest'] = self::typedBody('LaunchRequest');
        $bodies['SessionEndedRequest'] = self::typedBody('SessionEndedRequest');
        $bodies['unknown intent'] = self::intentBody('SomeIntentNobodyWrote');
        $bodies['unknown request type'] = self::typedBody('CanFulfillIntentRequest');

        return $bodies;
    }

    /**
     * @param array<string, string> $slots
     */
    private static function intentBody(string $intentName, array $slots = []): string
    {
        $encoded = [];
        foreach ($slots as $name => $value) {
            $encoded[$name] = ['name' => $name, 'value' => $value];
        }

        return self::envelope([
            'type' => 'IntentRequest',
            'requestId' => 'amzn1.echo-api.request.s91',
            'locale' => 'en-GB',
            'intent' => ['name' => $intentName, 'slots' => $encoded],
        ]);
    }

    private static function typedBody(string $type): string
    {
        return self::envelope(['type' => $type, 'requestId' => 'amzn1.echo-api.request.s91']);
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function envelope(array $request): string
    {
        return (string) json_encode([
            'version' => '1.0',
            'session' => ['user' => ['accessToken' => self::TOKEN_PLACEHOLDER]],
            'request' => $request,
        ]);
    }

    /**
     * A POST to `/alexa/skill` carrying `$rawBody`, with the placeholder token
     * replaced by one this test's own {@see JwtHandler} minted.
     */
    private function request(string $rawBody): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/alexa/skill';
        $request->headers = ['CONTENT-TYPE' => 'application/json'];
        $request->rawBody = str_replace(
            self::TOKEN_PLACEHOLDER,
            $this->jwt->createAccessToken(self::USER_ID),
            $rawBody,
        );

        return $request;
    }

    // ==================================================================
    // Controllers
    // ==================================================================

    /** A controller whose account link never resolves (nobody is linked). */
    private function unlinkedController(): AlexaSkillController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(false);

        return $this->build($users, [], static fn (): array => [200, '{}']);
    }

    /** A linked user, one connected server, one matching title with full detail. */
    private function workingController(string $matchedTitle = 'Inception'): AlexaSkillController
    {
        return $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            self::libraryReplies($matchedTitle),
        );
    }

    /**
     * @param list<ServerInfoDto>                $servers
     * @param callable(string): array{0: int, 1: string} $reply
     */
    private function controllerWith(array $servers, callable $reply): AlexaSkillController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(true);

        return $this->build($users, $servers, $reply);
    }

    /**
     * A relay that answers the search and the detail read for `$matchedTitle`.
     *
     * The fixture data is deliberately free of banned words — see the class
     * docblock: the honesty sweep inspects string leaves, so a *Roku*-titled
     * fixture would make it flag the USER's data rather than the skill's words.
     *
     * @return callable(string): array{0: int, 1: string}
     */
    private static function libraryReplies(string $matchedTitle): callable
    {
        return static function (string $path) use ($matchedTitle): array {
            if ($path === '/api/v1/media/search') {
                return [200, (string) json_encode([
                    'items' => [['id' => 'm-1', 'name' => $matchedTitle]],
                ])];
            }

            return [200, (string) json_encode([
                'item' => [
                    'id' => 'm-1',
                    'runtime' => 148,
                    'year' => 2010,
                    'rating' => 'PG-13',
                    'overview' => 'A thief who steals corporate secrets.',
                ],
            ])];
        };
    }

    /**
     * @param list<ServerInfoDto>                        $servers
     * @param callable(string): array{0: int, 1: string} $reply
     */
    private function build(UserRepository $users, array $servers, callable $reply): AlexaSkillController
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServersForUser')->willReturn($servers);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => self::USER_ID, 'status' => ServerInfoDto::STATUS_ONLINE, 'relayActive' => true],
        );

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
        );

        $bridge = null;
        $publisher = static function (string $event, array $data) use (&$bridge, $reply): void {
            /** @var array<string, mixed> $data */
            $path = $data['path'];
            /** @var string $path */
            [$status, $body] = $reply($path);
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => $status,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
            ]);
        };
        $bridge = new RelayProxyBridge(new StructuredLogger('alexa-skill-test', []), $publisher);

        $proxy = new ServerProxyController(
            $info,
            $bridge,
            new StructuredLogger('alexa-skill-test', []),
            $sessions,
            new RateLimiter(60, 100000, 1000),
        );

        return new AlexaSkillController(
            new AlexaAccountLink($this->jwt, $users),
            $proxy,
            new ServerListController($info),
            // S93: a pusher that reports NOTHING was delivered — which is also
            // what production reports today, since no client consumes :8804. It
            // keeps every pre-S93 expectation in this suite unchanged; the real
            // delivered-count coverage lands with the S93 tests.
            new class implements PendingCommandPusherInterface {
                public function pushPlayMedia(
                    string $userId,
                    string $serverId,
                    string $mediaId,
                    string $title,
                ): int {
                    return 0;
                }
            },
            new StructuredLogger('alexa-skill-test', []),
            self::HUB_BASE_URL,
        );
    }

    private function dto(string $serverId, bool $relayActive): ServerInfoDto
    {
        return new ServerInfoDto(
            $serverId,
            self::USER_ID,
            'Alexa Test Server',
            '1.0.0',
            null,
            ServerInfoDto::STATUS_ONLINE,
            [],
            $relayActive,
        );
    }

    // ==================================================================
    // Decoding
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'the controller must always answer with a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The `response` object of an Alexa envelope, asserted to be an array.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<array-key, mixed>
     */
    private static function responseOf(array $envelope): array
    {
        return self::arrayNode($envelope['response']);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function speechOf(array $envelope): string
    {
        $outputSpeech = self::arrayNode(self::responseOf($envelope)['outputSpeech']);
        $text = $outputSpeech['text'] ?? null;
        self::assertIsString($text, 'the envelope carries no spoken text');

        return $text;
    }
}
