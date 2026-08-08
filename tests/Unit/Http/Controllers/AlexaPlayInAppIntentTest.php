<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaPhrases;
use Phlix\Hub\Alexa\AlexaSpeech;
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
use Phlix\Hub\Tests\Support\Alexa\AlexaEnvelopeHonesty;
use Phlix\Hub\Tests\Support\SyncPlay\RecordingPendingCommandPusher;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function str_replace;

/**
 * S93 — `PhlixPlayInAppIntent`: the confirmation is gated on a REAL delivered count.
 *
 * ## The defect this suite exists to catch
 *
 * The skill can now ask a Phlix app the user ALREADY has open to start a title.
 * The push crosses a process boundary and returns the number of live sockets the
 * frame was actually written to. **A confirmation spoken on a count of 0 would be
 * a lie the user cannot detect**: they are told a screen was told to start
 * something, and their only way to find out otherwise is to watch a screen that
 * never changes. Every status-code assertion in the estate would pass on that
 * envelope — it is a perfectly valid 200 carrying a cheerful sentence.
 *
 * So {@see testNoOpenAppIsSaidPlainlyRatherThanConfirmingSomethingNobodyReceived()}
 * is the headline acceptance criterion of this step, and it asserts the spoken
 * text both POSITIVELY (it is exactly the "no open app" sentence) and NEGATIVELY
 * (it is not the confirmation), because an implementation that spoke some third
 * string would otherwise satisfy only half of the requirement.
 *
 * ## The other things a green here is standing for
 *
 *  - **Nothing is pushed for a request that never resolved.** A push made before
 *    identity/server/title resolution would carry an empty or wrong user id, and
 *    a frame addressed to `''` is a frame addressed to whoever the delivery side
 *    happens to match. Asserted by call COUNT on the recording pusher, across
 *    every refusal path, so "no speech changed" cannot hide a stray push.
 *  - **The four pushed arguments are the RESOLVED ones**, including the MATCHED
 *    title rather than the raw slot value — the fixture deliberately makes the
 *    two different so the assertion can tell them apart.
 *  - **No device-control directive on either branch.** The intent that genuinely
 *    starts something is the one most likely to acquire an `AudioPlayer` block,
 *    so the S91 walker is run over both envelopes, with the same anti-vacuity
 *    control that proves the walker can fire.
 *  - **`playLink()` was not repurposed.** The S93 trap is quietly turning the
 *    link intent into the play intent; the two answers are asserted to differ,
 *    and only one of them carries a URL.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class AlexaPlayInAppIntentTest extends TestCase
{
    private const SECRET = 'S93-alexa-play-in-app-intent-secret-0123456789';

    private const USER_ID = 'user-linked-1';

    private const SERVER_ID = 'srv-alexa-1';

    private const MEDIA_ID = 'm-77';

    private const HUB_BASE_URL = 'https://hub.phlix.test/';

    /**
     * The title the LIBRARY holds. Deliberately different from every slot value
     * spoken below, so an assertion about "the matched title" cannot be satisfied
     * by the raw microphone text.
     */
    private const MATCHED_TITLE = 'Inception';

    /** What the user says. The search fixture answers it with MATCHED_TITLE. */
    private const SPOKEN_TITLE = 'inception the movie';

    private const INTENT = 'PhlixPlayInAppIntent';

    private const TOKEN_PLACEHOLDER = '{{ACCESS_TOKEN}}';

    /**
     * The constraint both spoken branches must carry, quoted from
     * {@see AlexaPhrases::PLAY_IN_APP_SENT} / {@see AlexaPhrases::PLAY_IN_APP_NO_OPEN_APP}
     * rather than invented here.
     */
    private const CONSTRAINT_PHRASE = 'Phlix can only start something in an app that is already open';

    private JwtHandler $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new JwtHandler(self::SECRET);
    }

    // ==================================================================
    // 1–2. The gate
    // ==================================================================

    /**
     * Delivered >= 1 ⇒ the confirmation, and EXACTLY the confirmation.
     *
     * The negative half matters as much as the positive one: an implementation
     * that spoke the "no open app" sentence on a real delivery would be the same
     * defect pointed the other way — a user with an app open is told nothing
     * happened while their screen starts playing.
     */
    public function testAConfirmationIsSpokenOnlyWhenTheRelayReportsARealDelivery(): void
    {
        $pusher = new RecordingPendingCommandPusher(1);
        $response = $this->workingController($pusher)->handle($this->playInAppRequest());

        self::assertSame(200, $response->statusCode, 'the play-in-app intent must answer with an Alexa envelope');

        $speech = self::speechOf(self::decode($response));

        self::assertSame(
            AlexaSpeech::render(AlexaPhrases::PLAY_IN_APP_SENT, ['title' => self::MATCHED_TITLE]),
            $speech,
            'a delivered count of 1 must speak AlexaPhrases::PLAY_IN_APP_SENT verbatim — the relay '
            . 'reported the frame reached a live socket, so the confirmation is the true answer',
        );
        self::assertNotSame(
            AlexaSpeech::render(AlexaPhrases::PLAY_IN_APP_NO_OPEN_APP, ['title' => self::MATCHED_TITLE]),
            $speech,
            'a real delivery was answered with the "you have no app open" sentence: the gate is '
            . 'inverted, and a user whose screen just started playing is told nothing happened',
        );
    }

    /**
     * ⚠ THE HEADLINE ACCEPTANCE CRITERION OF S93.
     *
     * Nothing reached a socket, so nothing may be confirmed. `-1` is covered
     * beside `0` because the production gate is `< 1`, not `=== 0`: a delivery
     * side that ever reported a negative count must still take the honest branch
     * rather than falling through to the confirmation.
     */
    #[DataProvider('countsThatMeanNobodyReceivedIt')]
    public function testNoOpenAppIsSaidPlainlyRatherThanConfirmingSomethingNobodyReceived(int $delivered): void
    {
        $pusher = new RecordingPendingCommandPusher($delivered);
        $response = $this->workingController($pusher)->handle($this->playInAppRequest());

        self::assertSame(200, $response->statusCode);

        $speech = self::speechOf(self::decode($response));

        self::assertSame(
            AlexaSpeech::render(AlexaPhrases::PLAY_IN_APP_NO_OPEN_APP, ['title' => self::MATCHED_TITLE]),
            $speech,
            'HEADLINE S93 CRITERION: a delivered count of ' . $delivered . ' means the frame reached '
            . 'NO socket, so the skill must say plainly that it started nothing',
        );
        self::assertNotSame(
            AlexaSpeech::render(AlexaPhrases::PLAY_IN_APP_SENT, ['title' => self::MATCHED_TITLE]),
            $speech,
            'HEADLINE S93 CRITERION VIOLATED: the skill confirmed a command NOBODY received '
            . '(delivered = ' . $delivered . '). The user is told a screen was asked to start '
            . 'something and their only way to discover otherwise is to watch a screen that never '
            . 'changes. This is the exact dishonesty the delivered count exists to prevent.',
        );

        // The push itself still happened — the honest branch is reached by
        // MEASURING delivery, not by declining to try.
        self::assertSame(
            1,
            $pusher->callCount(),
            'the honest answer must come from a push that was made and reported 0, not from a '
            . 'controller that quietly stopped pushing at all',
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function countsThatMeanNobodyReceivedIt(): iterable
    {
        yield 'no socket was written to' => [0];
        yield 'a negative count must not slip past a "< 1" gate' => [-1];
    }

    // ==================================================================
    // 3. The constraint stays in the SPOKEN text, on both branches
    // ==================================================================

    /**
     * The user hearing this sentence is the only person who can act on it: they
     * need to know that what happened was "a screen you already had open was told
     * to start something", not "Phlix turned a television on". A reword that drops
     * the qualifier from either branch turns an honest sentence into an
     * overstatement, and no other assertion in the estate would notice.
     */
    public function testTheSpokenTextStatesTheAppMustBeOpenConstraintOnBothBranches(): void
    {
        // Anti-vacuity FIRST: a substring that matched everything would make each
        // assertion below trivially true. GOODBYE and UNSUPPORTED_REQUEST are
        // unrelated phrases that must NOT contain the constraint wording.
        self::assertStringNotContainsString(
            self::CONSTRAINT_PHRASE,
            AlexaPhrases::GOODBYE,
            'control: the constraint phrase matches an unrelated phrase, so finding it in the '
            . 'play-in-app answers proves nothing',
        );
        self::assertStringNotContainsString(
            'already open',
            AlexaPhrases::GOODBYE,
            'control: "already open" matches an unrelated phrase, so finding it below proves nothing',
        );
        self::assertStringNotContainsString(
            'already open',
            AlexaPhrases::UNSUPPORTED_REQUEST,
            'control: "already open" matches the fallback refusal too — the substring is too weak '
            . 'to be evidence about the play-in-app wording',
        );

        $confirmed = self::speechOf(self::decode(
            $this->workingController(new RecordingPendingCommandPusher(1))->handle($this->playInAppRequest()),
        ));
        $refused = self::speechOf(self::decode(
            $this->workingController(new RecordingPendingCommandPusher(0))->handle($this->playInAppRequest()),
        ));

        foreach (['confirmation' => $confirmed, 'no-open-app' => $refused] as $branch => $speech) {
            self::assertStringContainsString(
                self::CONSTRAINT_PHRASE,
                $speech,
                'the ' . $branch . ' branch no longer states that Phlix can only start something in an '
                . 'app that is ALREADY OPEN — without it the sentence overstates what the skill did',
            );
            self::assertStringContainsString(
                'already open',
                $speech,
                'the ' . $branch . ' branch dropped the "already open" qualifier',
            );
        }

        // Each branch keeps its own half of the meaning as well.
        self::assertStringContainsString(
            'I sent ' . self::MATCHED_TITLE . ' to the Phlix app you have open',
            $confirmed,
            'the confirmation must name what it actually did: sent the title to an app already open',
        );
        self::assertStringContainsString(
            'you do not have the Phlix app open anywhere right now',
            $refused,
            'the no-open-app answer must say WHY nothing started, in words the user can act on',
        );
        self::assertStringContainsString(
            'open Phlix on the screen you want to watch',
            $refused,
            'the no-open-app answer must tell the user what to do instead of leaving them waiting',
        );
        self::assertStringContainsString(
            'I did not start ' . self::MATCHED_TITLE,
            $refused,
            'the no-open-app answer must state plainly that NOTHING was started',
        );
    }

    // ==================================================================
    // 4. What actually gets pushed
    // ==================================================================

    /**
     * The four arguments are an identity. `userId` decides whose apps receive the
     * frame and `serverId` decides which of that user's servers it is scoped to;
     * either one wrong is a spoken intent landing on the wrong socket. The title
     * must be the MATCHED one — the raw slot value is what a microphone heard, and
     * the app has to display something the library actually holds.
     */
    public function testTheResolvedIdentityAndMediaIdAreWhatGetsPushed(): void
    {
        $pusher = new RecordingPendingCommandPusher(1);

        $this->workingController($pusher)->handle($this->playInAppRequest());

        self::assertCount(
            1,
            $pusher->calls,
            'one utterance must produce exactly one push — a second one would deliver the command twice',
        );

        // Control: the spoken slot and the matched title really are different, so
        // the title assertion below can distinguish them.
        self::assertNotSame(
            self::SPOKEN_TITLE,
            self::MATCHED_TITLE,
            'control: the fixture must make the spoken slot differ from the matched title',
        );

        self::assertSame(
            [
                'userId' => self::USER_ID,
                'serverId' => self::SERVER_ID,
                'mediaId' => self::MEDIA_ID,
                'title' => self::MATCHED_TITLE,
            ],
            $pusher->calls[0],
            'the push must carry the RESOLVED identity (the account-linked user id and the connected '
            . 'server id), the media id from the search hit, and the MATCHED title — not the raw slot '
            . 'value the microphone produced',
        );
    }

    // ==================================================================
    // 5. Nothing is pushed for a request that never resolved
    // ==================================================================

    /**
     * A push for an unresolved request would carry an empty user id, and a frame
     * addressed to `''` is a frame addressed to whoever the delivery side happens
     * to match — one user's spoken intent on another user's socket.
     *
     * The refusal WORDING is asserted alongside the zero, so a controller that
     * stopped pushing by also stopping answering would fail here.
     */
    #[DataProvider('refusalPaths')]
    public function testNothingIsPushedWhenTheRequestCannotBeResolved(string $case, string $expectedSpeech): void
    {
        $pusher = new RecordingPendingCommandPusher(1);
        [$controller, $body] = $this->refusalScenario($case, $pusher);

        $response = $controller->handle($this->request($body));

        self::assertSame(200, $response->statusCode, $case . ': a refusal is still a spoken 200');
        self::assertSame(
            $expectedSpeech,
            self::speechOf(self::decode($response)),
            $case . ': the refusal must be the same sentence every other library intent uses',
        );
        self::assertSame(
            0,
            $pusher->callCount(),
            $case . ': a pending command was pushed for a request that never resolved. The frame '
            . 'would carry an empty or unowned identity, which is one user\'s spoken intent landing '
            . 'on another user\'s socket.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusalPaths(): iterable
    {
        yield 'unlinked account' => [
            'unlinked',
            AlexaSpeech::render(AlexaPhrases::LINK_ACCOUNT),
        ];
        yield 'missing Title slot' => [
            'missing_title',
            AlexaSpeech::render(AlexaPhrases::MISSING_TITLE),
        ];
        yield 'no claimed servers' => [
            'no_servers',
            AlexaSpeech::render(AlexaPhrases::NO_SERVERS),
        ];
        yield 'no connected server' => [
            'no_connected_server',
            AlexaSpeech::render(AlexaPhrases::NO_CONNECTED_SERVER),
        ];
        yield 'title not found' => [
            'title_not_found',
            AlexaSpeech::render(AlexaPhrases::TITLE_NOT_FOUND, ['title' => 'Solaris']),
        ];
    }

    // ==================================================================
    // 6. No device-control directive on either branch
    // ==================================================================

    /**
     * The intent that genuinely starts something is the one most likely to
     * acquire an `AudioPlayer`/`VideoApp` block, so both of its envelopes are
     * walked. The control beside them proves the walker can still fire.
     */
    public function testThePlayInAppEnvelopeCarriesNoDeviceControlDirective(): void
    {
        foreach ([1 => 'delivered', 0 => 'not delivered'] as $delivered => $label) {
            $envelope = self::decode(
                $this->workingController(new RecordingPendingCommandPusher($delivered))
                    ->handle($this->playInAppRequest()),
            );

            self::assertSame(
                [],
                AlexaEnvelopeHonesty::violations($envelope),
                'the play-in-app envelope (' . $label . ') claims device control the skill does not '
                . 'have. Starting a title in an app that is already open is NOT the same capability '
                . 'as driving a device, and an Amazon directive here would claim the latter.',
            );
        }

        // ANTI-VACUITY: the same walker, on an envelope that DOES claim device
        // control, must report it. Without this, the two empty lists above read
        // identically whether the envelopes are honest or the walker is broken.
        $lying = [
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => 'Now playing Inception on your TV.'],
                'directives' => [['type' => 'AudioPlayer.Play', 'playBehavior' => 'REPLACE_ALL']],
                'shouldEndSession' => true,
            ],
        ];
        $found = AlexaEnvelopeHonesty::violations($lying);
        self::assertNotEmpty(
            $found,
            'control: the honesty walker cannot see a directives key or a spoken device-control '
            . 'claim, so the two empty results above measured nothing',
        );
        self::assertStringContainsString('directives', implode(' | ', $found));
        self::assertStringContainsString('now playing', implode(' | ', $found));
        self::assertStringContainsString('on your tv', implode(' | ', $found));
    }

    // ==================================================================
    // 7. The S93 trap: playLink() must not have been repurposed
    // ==================================================================

    /**
     * `PhlixPlayLinkIntent` and `PhlixPlayInAppIntent` are two different answers
     * to two different questions. The cheap way to "ship" S93 is to make the link
     * intent start playback instead — which would silently delete a working
     * feature and leave the interaction model describing something that no longer
     * exists.
     */
    public function testPlayLinkAndPlayInAppAreDifferentAnswers(): void
    {
        $controller = $this->workingController(new RecordingPendingCommandPusher(1));

        $linkEnvelope = self::decode($controller->handle($this->request(
            self::intentBody('PhlixPlayLinkIntent', ['Title' => self::SPOKEN_TITLE]),
        )));
        $inAppEnvelope = self::decode($controller->handle($this->playInAppRequest()));

        self::assertNotSame(
            self::speechOf($linkEnvelope),
            self::speechOf($inAppEnvelope),
            'the link intent and the play-in-app intent gave the SAME answer — one of them has been '
            . 'repurposed into the other, and the capability it used to provide is gone',
        );

        // JSON_UNESCAPED_SLASHES so the assertions below read the URL the user is
        // actually handed rather than PHP's `\/`-escaped spelling of it.
        $linkJson = (string) json_encode($linkEnvelope, JSON_UNESCAPED_SLASHES);
        $inAppJson = (string) json_encode($inAppEnvelope, JSON_UNESCAPED_SLASHES);

        // Control: the escaping choice is load-bearing for the two substring
        // assertions below, so it is proved rather than assumed.
        self::assertStringContainsString(
            'https://hub.phlix.test',
            $linkJson,
            'control: the encoded link envelope does not carry an unescaped URL, so a "no URL here" '
            . 'assertion against the play-in-app envelope would be measuring the escaping, not the answer',
        );

        self::assertStringContainsString(
            AlexaSkillController::SEARCH_ROUTE . '?q=',
            $linkJson,
            'the play-link answer no longer carries a resolvable hub search link — the one thing '
            . 'that intent exists to hand back',
        );
        self::assertStringNotContainsString(
            AlexaSkillController::SEARCH_ROUTE,
            $inAppJson,
            'the play-in-app answer carries a search link: it has been turned back into the link '
            . 'intent instead of starting anything',
        );
        self::assertStringNotContainsString(
            '://',
            $inAppJson,
            'the play-in-app answer carries a URL. It is spoken aloud, and a URL read out by Alexa '
            . 'is not an answer a user can act on — that is what the link intent and its card are for.',
        );
    }

    // ==================================================================
    // Scenario builders
    // ==================================================================

    /**
     * @return array{0: AlexaSkillController, 1: string}
     */
    private function refusalScenario(string $case, RecordingPendingCommandPusher $pusher): array
    {
        $withTitle = self::intentBody(self::INTENT, ['Title' => self::SPOKEN_TITLE]);

        return match ($case) {
            'unlinked' => [$this->unlinkedController($pusher), $withTitle],
            'missing_title' => [$this->workingController($pusher), self::intentBody(self::INTENT)],
            'no_servers' => [
                $this->controllerWith([], static fn (): array => [200, '{}'], $pusher),
                $withTitle,
            ],
            'no_connected_server' => [
                $this->controllerWith(
                    [$this->dto(self::SERVER_ID, false)],
                    static fn (): array => [200, '{}'],
                    $pusher,
                ),
                $withTitle,
            ],
            'title_not_found' => [
                $this->controllerWith(
                    [$this->dto(self::SERVER_ID, true)],
                    static fn (): array => [200, '{"items":[]}'],
                    $pusher,
                ),
                self::intentBody(self::INTENT, ['Title' => 'Solaris']),
            ],
            default => self::fail('unknown refusal scenario: ' . $case),
        };
    }

    private function playInAppRequest(): Request
    {
        return $this->request(self::intentBody(self::INTENT, ['Title' => self::SPOKEN_TITLE]));
    }

    /** A linked user, one connected server, one matching title. */
    private function workingController(RecordingPendingCommandPusher $pusher): AlexaSkillController
    {
        return $this->controllerWith(
            [$this->dto(self::SERVER_ID, true)],
            self::libraryReplies(),
            $pusher,
        );
    }

    private function unlinkedController(RecordingPendingCommandPusher $pusher): AlexaSkillController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(false);

        return $this->build($users, [$this->dto(self::SERVER_ID, true)], self::libraryReplies(), $pusher);
    }

    /**
     * @param list<ServerInfoDto>                        $servers
     * @param callable(string): array{0: int, 1: string} $reply
     */
    private function controllerWith(
        array $servers,
        callable $reply,
        RecordingPendingCommandPusher $pusher,
    ): AlexaSkillController {
        $users = $this->createMock(UserRepository::class);
        $users->method('userExists')->willReturn(true);

        return $this->build($users, $servers, $reply, $pusher);
    }

    /**
     * The relay's answers. The fixture data is deliberately free of banned words
     * — the honesty walker inspects string leaves, so a *Roku*-titled fixture
     * would make it flag the USER's data rather than the skill's words.
     *
     * @return callable(string): array{0: int, 1: string}
     */
    private static function libraryReplies(): callable
    {
        return static function (string $path): array {
            if ($path === '/api/v1/media/search') {
                return [200, (string) json_encode([
                    'items' => [['id' => self::MEDIA_ID, 'name' => self::MATCHED_TITLE]],
                ])];
            }

            return [200, (string) json_encode([
                'item' => ['id' => self::MEDIA_ID, 'runtime' => 148, 'year' => 2010],
            ])];
        };
    }

    /**
     * The REAL controller over a REAL {@see RelayProxyBridge} whose publisher
     * answers synchronously — the same idiom `AlexaSkillControllerTest::build()`
     * uses, so the proxy's own gates are exercised rather than stubbed away.
     *
     * @param list<ServerInfoDto>                        $servers
     * @param callable(string): array{0: int, 1: string} $reply
     */
    private function build(
        UserRepository $users,
        array $servers,
        callable $reply,
        RecordingPendingCommandPusher $pusher,
    ): AlexaSkillController {
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
        $bridge = new RelayProxyBridge(new StructuredLogger('alexa-play-in-app-test', []), $publisher);

        $proxy = new ServerProxyController(
            $info,
            $bridge,
            new StructuredLogger('alexa-play-in-app-test', []),
            $sessions,
            new RateLimiter(60, 100000, 1000),
        );

        return new AlexaSkillController(
            new AlexaAccountLink($this->jwt, $users),
            $proxy,
            new ServerListController($info),
            $pusher,
            new StructuredLogger('alexa-play-in-app-test', []),
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
    // Bodies / decoding
    // ==================================================================

    /**
     * @param array<string, string> $slots
     */
    private static function intentBody(string $intentName, array $slots = []): string
    {
        $encoded = [];
        foreach ($slots as $name => $value) {
            $encoded[$name] = ['name' => $name, 'value' => $value];
        }

        return (string) json_encode([
            'version' => '1.0',
            'session' => ['user' => ['accessToken' => self::TOKEN_PLACEHOLDER]],
            'request' => [
                'type' => 'IntentRequest',
                'requestId' => 'amzn1.echo-api.request.s93',
                'locale' => 'en-GB',
                'intent' => ['name' => $intentName, 'slots' => $encoded],
            ],
        ]);
    }

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
     * @param array<string, mixed> $envelope
     */
    private static function speechOf(array $envelope): string
    {
        /** @var mixed $text */
        $text = $envelope['response']['outputSpeech']['text'] ?? null;
        self::assertIsString($text, 'the envelope carries no spoken text');

        return $text;
    }
}
