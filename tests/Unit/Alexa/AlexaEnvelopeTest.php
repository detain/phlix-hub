<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use Phlix\Hub\Alexa\AlexaEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * S91 — the defects this suite catches in {@see AlexaEnvelope}.
 *
 * **1. A half-read access token.** Amazon publishes the linked-account token in
 * TWO documented places: `session.user.accessToken` and
 * `context.System.user.accessToken`. A reader that consults only one of them
 * works for some request shapes and silently not for others, and the user
 * experiences that as an intermittent "please link your account" with no way to
 * tell why. Both spellings are driven here, in isolation, plus the case where
 * both are present (`context` wins) — that last one is what would go red if the
 * two lookups were swapped or if the second were treated as an override.
 *
 * **2. A malformed body that parses "successfully".** `fromRawBody()` documents
 * five null cases. Each is asserted individually rather than as one "garbage in,
 * null out" test, because they are five DIFFERENT branches and a single garbage
 * string only ever reaches the first of them. The complementary control — a
 * well-formed envelope that parses — sits in the same suite, so "returns null"
 * can be told apart from "returns null for everything".
 *
 * **3. Slot values that are not strings.** Alexa ships slots as objects with an
 * optional `value`; an integer, an object or an absent `value` must not become a
 * spoken title. `slot()` returning null must mean "absent OR empty", and nothing
 * else must reach the controller.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class AlexaEnvelopeTest extends TestCase
{
    // ------------------------------------------------------------------
    // The happy paths, one per request type
    // ------------------------------------------------------------------

    public function testAnIntentRequestExposesEveryTypedField(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.s91',
                'locale' => 'en-GB',
                'intent' => [
                    'name' => 'PhlixTitleRuntimeIntent',
                    'slots' => [
                        'Title' => ['name' => 'Title', 'value' => 'Inception'],
                        'Detail' => ['name' => 'Detail', 'value' => 'runtime'],
                    ],
                ],
            ],
        ]));

        self::assertNotNull($envelope);
        self::assertSame(AlexaEnvelope::TYPE_INTENT, $envelope->type());
        self::assertSame('PhlixTitleRuntimeIntent', $envelope->intentName());
        self::assertSame('Inception', $envelope->slot('Title'));
        self::assertSame('runtime', $envelope->slot('Detail'));
        self::assertSame('en-GB', $envelope->locale());
        self::assertSame('amzn1.echo-api.request.s91', $envelope->requestId());
    }

    public function testALaunchRequestCarriesNoIntentAndNoSlots(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => ['type' => 'LaunchRequest', 'requestId' => 'r-1', 'locale' => 'en-US'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame(AlexaEnvelope::TYPE_LAUNCH, $envelope->type());
        self::assertNull($envelope->intentName());
        self::assertNull($envelope->slot('Title'));
    }

    public function testASessionEndedRequestParses(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => ['type' => 'SessionEndedRequest', 'reason' => 'USER_INITIATED'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame(AlexaEnvelope::TYPE_SESSION_ENDED, $envelope->type());
        self::assertNull($envelope->intentName());
        self::assertNull($envelope->requestId());
        self::assertNull($envelope->locale());
    }

    /**
     * An unknown request type is NOT a parse failure.
     *
     * Amazon adds request types (`CanFulfillIntentRequest`, the various
     * `AudioPlayer.*` lifecycle events) without asking. Refusing to parse them
     * would turn a protocol addition into a 400; the controller answers them
     * with the honest refusal instead, and it can only do that if this class
     * hands the type through.
     */
    public function testAnUnknownRequestTypeStillParses(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => ['type' => 'CanFulfillIntentRequest', 'requestId' => 'r-9'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('CanFulfillIntentRequest', $envelope->type());
    }

    // ------------------------------------------------------------------
    // The documented null cases
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'empty string' => [''];
        yield 'not json at all' => ['{nope'];
        yield 'json null' => ['null'];
        yield 'json scalar' => ['"IntentRequest"'];
        yield 'json number' => ['42'];
        yield 'object without a request member' => ['{"version":"1.0"}'];
        yield 'request is not an object' => ['{"request":"IntentRequest"}'];
        yield 'request without a type' => ['{"request":{"requestId":"r-1"}}'];
        yield 'request type is empty' => ['{"request":{"type":""}}'];
        yield 'request type is not a string' => ['{"request":{"type":17}}'];
        yield 'intent request without an intent object' => ['{"request":{"type":"IntentRequest"}}'];
        yield 'intent request whose intent is not an object' => ['{"request":{"type":"IntentRequest","intent":"x"}}'];
        yield 'intent request without an intent name' => ['{"request":{"type":"IntentRequest","intent":{}}}'];
        yield 'intent request with an empty intent name' => [
            '{"request":{"type":"IntentRequest","intent":{"name":""}}}',
        ];
    }

    #[DataProvider('unusableBodies')]
    public function testAnUnusableBodyParsesToNull(string $rawBody): void
    {
        self::assertNull(AlexaEnvelope::fromRawBody($rawBody));
    }

    /**
     * Anti-vacuity for the provider above: the SAME shape, made valid by the one
     * field each case removes, must parse. Without this the provider would keep
     * passing against a `fromRawBody()` that returned null unconditionally.
     */
    public function testTheMinimalWellFormedEnvelopeParses(): void
    {
        self::assertNotNull(AlexaEnvelope::fromRawBody('{"request":{"type":"LaunchRequest"}}'));
        self::assertNotNull(
            AlexaEnvelope::fromRawBody('{"request":{"type":"IntentRequest","intent":{"name":"X"}}}'),
        );
    }

    // ------------------------------------------------------------------
    // The access token, from BOTH documented places
    // ------------------------------------------------------------------

    public function testTheAccessTokenIsReadFromTheSessionUser(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'session' => ['user' => ['userId' => 'amzn1.ask.account.x', 'accessToken' => 'session-token']],
            'request' => ['type' => 'LaunchRequest'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('session-token', $envelope->accessToken());
    }

    public function testTheAccessTokenIsReadFromTheContextSystemUser(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'context' => ['System' => ['user' => ['accessToken' => 'context-token']]],
            'request' => ['type' => 'LaunchRequest'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('context-token', $envelope->accessToken());
    }

    /**
     * `context` is preferred, because it is the one present on every request
     * type. This is the assertion that fails if the two lookups are reordered —
     * a reorder no single-source test above can see.
     */
    public function testTheContextTokenWinsWhenBothArePresent(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'context' => ['System' => ['user' => ['accessToken' => 'context-token']]],
            'session' => ['user' => ['accessToken' => 'session-token']],
            'request' => ['type' => 'LaunchRequest'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('context-token', $envelope->accessToken());
    }

    /**
     * An EMPTY `context` token must not shadow a usable `session` one: the empty
     * string is "absent", not "the answer".
     */
    public function testAnEmptyContextTokenFallsThroughToTheSessionToken(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'context' => ['System' => ['user' => ['accessToken' => '']]],
            'session' => ['user' => ['accessToken' => 'session-token']],
            'request' => ['type' => 'LaunchRequest'],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('session-token', $envelope->accessToken());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function tokenlessEnvelopes(): iterable
    {
        yield 'no session and no context' => [[]];
        yield 'context without System' => [['context' => []]];
        yield 'context System without user' => [['context' => ['System' => []]]];
        yield 'context user without token' => [['context' => ['System' => ['user' => []]]]];
        yield 'session without user' => [['session' => []]];
        yield 'session user without token' => [['session' => ['user' => []]]];
        yield 'session user with an empty token' => [['session' => ['user' => ['accessToken' => '']]]];
        yield 'session user with a non-string token' => [['session' => ['user' => ['accessToken' => 7]]]];
        yield 'session is not an object' => [['session' => 'anonymous']];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('tokenlessEnvelopes')]
    public function testAnAbsentOrUnusableAccessTokenIsNull(array $extra): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body(
            $extra + ['request' => ['type' => 'LaunchRequest']],
        ));

        self::assertNotNull($envelope);
        self::assertNull($envelope->accessToken());
    }

    // ------------------------------------------------------------------
    // Slots
    // ------------------------------------------------------------------

    public function testOnlyStringSlotValuesSurvive(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => [
                'type' => 'IntentRequest',
                'intent' => [
                    'name' => 'PhlixTitleRuntimeIntent',
                    'slots' => [
                        'Title' => ['value' => 'Arrival'],
                        'Empty' => ['value' => ''],
                        'Numeric' => ['value' => 2016],
                        'Objecty' => ['value' => ['deep' => 'no']],
                        'NoValue' => ['name' => 'NoValue'],
                        'NotAnObject' => 'Arrival',
                    ],
                ],
            ],
        ]));

        self::assertNotNull($envelope);
        // The control: a genuinely usable slot IS read, so the nulls below are
        // attributable to their own shapes rather than to slot reading being
        // broken outright.
        self::assertSame('Arrival', $envelope->slot('Title'));
        self::assertNull($envelope->slot('Empty'));
        self::assertNull($envelope->slot('Numeric'));
        self::assertNull($envelope->slot('Objecty'));
        self::assertNull($envelope->slot('NoValue'));
        self::assertNull($envelope->slot('NotAnObject'));
        self::assertNull($envelope->slot('NeverSent'));
    }

    public function testASlotsMemberThatIsNotAnObjectYieldsNoSlots(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => [
                'type' => 'IntentRequest',
                'intent' => ['name' => 'PhlixPlayLinkIntent', 'slots' => 'Title=Dune'],
            ],
        ]));

        self::assertNotNull($envelope);
        self::assertSame('PhlixPlayLinkIntent', $envelope->intentName());
        self::assertNull($envelope->slot('Title'));
    }

    public function testAnEmptyLocaleOrRequestIdIsNull(): void
    {
        $envelope = AlexaEnvelope::fromRawBody(self::body([
            'request' => ['type' => 'LaunchRequest', 'locale' => '', 'requestId' => ''],
        ]));

        self::assertNotNull($envelope);
        self::assertNull($envelope->locale());
        self::assertNull($envelope->requestId());
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function body(array $envelope): string
    {
        return (string) json_encode(['version' => '1.0'] + $envelope);
    }
}
