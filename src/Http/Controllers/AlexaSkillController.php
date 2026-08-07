<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaEnvelope;
use Phlix\Hub\Alexa\AlexaMediaGateway;
use Phlix\Hub\Alexa\AlexaPhrases;
use Phlix\Hub\Alexa\AlexaSpeech;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

use function array_combine;
use function array_keys;
use function array_map;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function rtrim;
use function strlen;
use function strtolower;
use function trim;

/**
 * The Alexa custom skill endpoint — `POST /alexa/skill` (S91).
 *
 * ## What this skill is, and what it is honest about not being
 *
 * It is a self-hosted HTTPS custom skill (not a Lambda, not a Smart Home skill).
 * It answers questions about the titles in a linked user's library — "how long is
 * Inception" — and it hands back a LINK when asked to play something. It cannot
 * start, stop or route playback on any device, and it cannot cast; that is a
 * later step, and casting is not built at all.
 *
 * The honesty criterion is enforced in three independent places, because a rule
 * stated only in prose is a rule that lapses:
 *
 *  1. **Structurally** — every response is built by {@see AlexaSpeech}, which has
 *     no parameter, field or code path capable of emitting `directives`,
 *     `AudioPlayer` or `VideoApp`. A device-control DIRECTIVE is unrepresentable.
 *  2. **Lexically** — every string this controller can speak is a constant in
 *     {@see AlexaPhrases}, swept by `AlexaHonesty` at render time and by the
 *     suite. **This class contains no speech literal of its own**; one inline
 *     string here would be invisible to that sweep, which is exactly why the rule
 *     is absolute rather than a preference.
 *  3. **By enumeration** — {@see SUPPORTED_INTENTS} pins the dispatch table, so
 *     an intent added to the interaction model but never handled here shows up as
 *     a failing floor rather than as a silent fallback.
 *
 * ## Authentication and authorisation, neither of which lives here
 *
 * The request's authenticity is Amazon's signature, proven by
 * {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware} before this
 * controller is reached; there is no second check here and there must not be one.
 * The caller's IDENTITY comes from {@see AlexaAccountLink}, and every library read
 * goes through {@see AlexaMediaGateway}, which calls the production
 * {@see ServerProxyController} / {@see ServerListController} and therefore
 * inherits their ownership, quota, traversal and browse-scope gates unchanged.
 * Nothing was added to the proxy's allowlist for this skill.
 *
 * ## Failure is spoken, never faked
 *
 * No linked account, no claimed server, no connected server, no search hit, an
 * unreadable payload: each has its own sentence. The one thing the skill never
 * does is answer a library question it could not actually look up.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 *
 * @psalm-type AlexaResponse = array{version: string, response: array<string, mixed>}
 */
final class AlexaSkillController
{
    /**
     * Every intent name this controller dispatches.
     *
     * An ANTI-VACUITY FLOOR, not documentation: the suite asserts both that each
     * entry is reachable and that the list has not shrunk, so deleting an arm of
     * {@see dispatchIntent()} — which would silently route that intent to the
     * fallback refusal, a change no status-code assertion would notice — fails
     * the build.
     *
     * @var list<string>
     */
    public const SUPPORTED_INTENTS = [
        'PhlixTitleRuntimeIntent',
        'PhlixPlayLinkIntent',
        'AMAZON.HelpIntent',
        'AMAZON.StopIntent',
        'AMAZON.CancelIntent',
        'AMAZON.FallbackIntent',
    ];

    /** The free-text title slot both library intents carry. */
    public const SLOT_TITLE = 'Title';

    /** The optional "which fact did you want" slot on the Q&A intent. */
    public const SLOT_DETAIL = 'Detail';

    /** SPA route the play link points at; `SearchPage` reads `?q=` on mount. */
    public const SEARCH_ROUTE = '/app/search';

    /**
     * Facts the Q&A intent can speak, and the spoken words that select one.
     *
     * A `Detail` slot that maps to an entry here narrows the answer to that one
     * fact; anything else (including an absent slot) speaks every fact the server
     * actually returned. Synonyms are listed because the slot is free text from
     * speech recognition, not a catalogue value.
     *
     * @var array<string, list<string>>
     */
    private const DETAIL_SYNONYMS = [
        'runtime' => ['runtime', 'run time', 'length', 'long', 'duration'],
        'year' => ['year', 'released', 'release year', 'came out'],
        'rating' => ['rating', 'rated', 'certificate', 'age rating'],
        'summary' => ['summary', 'plot', 'about', 'overview', 'synopsis'],
    ];

    /**
     * Results asked of the server's search endpoint.
     *
     * Small on purpose: only the first match is ever spoken, and a spoken
     * interface has no way to present a list.
     */
    private const SEARCH_LIMIT = 5;

    /**
     * Ceiling on the spoken summary, in characters.
     *
     * A server overview is a paragraph; Alexa reads the whole thing aloud with no
     * way for the user to interrupt except by saying stop. Truncating at a
     * sentence-ish length keeps the answer usable.
     */
    private const SUMMARY_MAX_CHARS = 320;

    /**
     * @param AlexaAccountLink      $accountLink Resolves Amazon's linked-account
     *        token to a hub user id. The ONLY source of caller identity here.
     * @param ServerProxyController $proxy       Production relay proxy controller,
     *        handed to {@see AlexaMediaGateway} so its gates are inherited.
     * @param ServerListController  $serverList  Production server-list controller.
     * @param StructuredLogger      $logger      Malformed envelopes and failed
     *        lookups are recorded here; nothing is ever spoken about them.
     * @param string                $hubBaseUrl  Public hub origin the play link is
     *        built against, e.g. `https://hub.phlix.media`.
     */
    public function __construct(
        private readonly AlexaAccountLink $accountLink,
        private readonly ServerProxyController $proxy,
        private readonly ServerListController $serverList,
        private readonly StructuredLogger $logger,
        private readonly string $hubBaseUrl,
    ) {
    }

    /**
     * `POST /alexa/skill`.
     *
     * @param Request               $request The already-signature-verified request.
     * @param array<string, string> $params  Route parameters (none; the path is literal).
     *
     * @return Response A 200 carrying an Alexa response envelope, or a 400 with no
     *         speech when the body is not a usable envelope at all.
     */
    public function handle(Request $request, array $params = []): Response
    {
        $envelope = AlexaEnvelope::fromRawBody($request->rawBody);
        if ($envelope === null) {
            $this->logger->warning('Alexa request body is not a usable envelope', [
                'bytes' => strlen($request->rawBody),
                'params' => implode(',', array_keys($params)),
            ]);

            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'alexa.malformed_envelope',
            ]);
        }

        return match ($envelope->type()) {
            // Amazon forbids a spoken response to a session-ended notification.
            AlexaEnvelope::TYPE_SESSION_ENDED => $this->ok(AlexaSpeech::silent()),
            AlexaEnvelope::TYPE_LAUNCH => $this->ok($this->capability()),
            AlexaEnvelope::TYPE_INTENT => $this->ok($this->dispatchIntent($request, $envelope)),
            default => $this->ok($this->refusal()),
        };
    }

    /**
     * Route an `IntentRequest` to its handler.
     *
     * Every arm of this match is named in {@see SUPPORTED_INTENTS}; the default
     * arm is the honest refusal, which is also where `AMAZON.FallbackIntent`
     * lands.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function dispatchIntent(Request $request, AlexaEnvelope $envelope): array
    {
        return match ($envelope->intentName()) {
            'PhlixTitleRuntimeIntent' => $this->titleFacts($request, $envelope),
            'PhlixPlayLinkIntent' => $this->playLink($request, $envelope),
            'AMAZON.HelpIntent' => $this->capability(),
            'AMAZON.StopIntent', 'AMAZON.CancelIntent' => AlexaSpeech::tell(
                AlexaSpeech::render(AlexaPhrases::GOODBYE),
            ),
            default => $this->refusal(),
        };
    }

    /**
     * `PhlixTitleRuntimeIntent` — answer a question about one title.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function titleFacts(Request $request, AlexaEnvelope $envelope): array
    {
        $match = $this->resolveTitle($request, $envelope);
        if ($match['refusal'] !== null) {
            return $match['refusal'];
        }

        $item = $this->detail(
            $this->gateway($request, $match['userId']),
            $match['serverId'],
            $match['mediaId'],
        );
        if ($item === null) {
            return $this->say(AlexaPhrases::LOOKUP_FAILED);
        }

        $facts = $this->facts($item, $envelope->slot(self::SLOT_DETAIL));
        if ($facts === []) {
            return $this->say(AlexaPhrases::TITLE_NO_DETAILS, ['title' => $match['title']]);
        }

        return $this->say(AlexaPhrases::TITLE_FACTS, [
            'title' => $match['title'],
            'facts' => implode(' ', $facts),
        ]);
    }

    /**
     * `PhlixPlayLinkIntent` — hand back a LINK, and say plainly that it is a link.
     *
     * The URL is `<hub>/app/search?q=<matched title>`, a REAL hub SPA route whose
     * `SearchPage` reads `route.query.q` on mount. That is what makes the answer
     * resolvable rather than invented — an intent that spoke a plausible-looking
     * URL nothing serves would be the same dishonesty as claiming playback.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function playLink(Request $request, AlexaEnvelope $envelope): array
    {
        $match = $this->resolveTitle($request, $envelope);
        if ($match['refusal'] !== null) {
            return $match['refusal'];
        }

        $title = $match['title'];
        $link = rtrim($this->hubBaseUrl, '/')
            . self::SEARCH_ROUTE
            . '?'
            . http_build_query(['q' => $title]);

        return AlexaSpeech::tell(
            AlexaSpeech::render(AlexaPhrases::PLAY_LINK_SPEECH, ['title' => $title]),
            AlexaSpeech::simpleCard(
                AlexaSpeech::render(AlexaPhrases::PLAY_LINK_CARD_TITLE, ['title' => $title]),
                AlexaSpeech::render(AlexaPhrases::PLAY_LINK_CARD_TEXT, [
                    'title' => $title,
                    'link' => $link,
                ]),
            ),
        );
    }

    /**
     * The shared front half of both library intents: identity, a reachable
     * server, and one search hit.
     *
     * `refusal` non-null means "speak this instead"; the remaining fields are
     * then empty and must not be read. A discriminated struct rather than two
     * copies of the same six refusals, because every one of them must be
     * IDENTICAL between the two intents — a user who is not linked must not learn
     * that they are linked from one intent and not the other.
     *
     * @return array{
     *     refusal: array{version: string, response: array<string, mixed>}|null,
     *     userId: string,
     *     serverId: string,
     *     mediaId: string,
     *     title: string
     * }
     */
    private function resolveTitle(Request $request, AlexaEnvelope $envelope): array
    {
        $userId = $this->accountLink->resolve($envelope->accessToken());
        if ($userId === null) {
            return self::refuse(
                AlexaSpeech::linkAccount(AlexaSpeech::render(AlexaPhrases::LINK_ACCOUNT)),
            );
        }

        $spokenTitle = $envelope->slot(self::SLOT_TITLE);
        $title = $spokenTitle === null ? '' : trim($spokenTitle);
        if ($title === '') {
            return self::refuse(AlexaSpeech::ask(
                AlexaSpeech::render(AlexaPhrases::MISSING_TITLE),
                AlexaSpeech::render(AlexaPhrases::CAPABILITY_REPROMPT),
            ));
        }

        $gateway = $this->gateway($request, $userId);

        $server = $this->firstConnectedServerId($gateway);
        if ($server['refusal'] !== null) {
            return self::refuse($server['refusal']);
        }
        $serverId = $server['serverId'];

        $search = $gateway->search($serverId, $title, self::SEARCH_LIMIT);
        if ($search['status'] !== 200) {
            $this->logger->warning('Alexa media search failed', [
                'status' => $search['status'],
                'server_id' => $serverId,
            ]);

            return self::refuse($this->say(AlexaPhrases::LOOKUP_FAILED));
        }

        $items = self::rows($search['payload'], 'items');
        if ($items === []) {
            return self::refuse($this->say(AlexaPhrases::TITLE_NOT_FOUND, ['title' => $title]));
        }

        $top = $items[0];
        $mediaId = self::stringField($top, 'id');
        $name = self::stringField($top, 'name');
        if ($mediaId === null || $name === null) {
            $this->logger->warning('Alexa media search returned an unusable row', [
                'server_id' => $serverId,
                'keys' => implode(',', array_keys($top)),
            ]);

            return self::refuse($this->say(AlexaPhrases::LOOKUP_FAILED));
        }

        return [
            'refusal' => null,
            'userId' => $userId,
            'serverId' => $serverId,
            'mediaId' => $mediaId,
            'title' => $name,
        ];
    }

    /**
     * The id of the first owned server with a live relay tunnel.
     *
     * A server that is claimed but offline cannot answer anything, so it is
     * excluded here rather than allowed to produce a 503 the user would hear as
     * "I could not reach your library" — true, but less useful than "none of your
     * servers is connected".
     *
     * @return array{refusal: array{version: string, response: array<string, mixed>}|null, serverId: string}
     */
    private function firstConnectedServerId(AlexaMediaGateway $gateway): array
    {
        $servers = $gateway->servers();
        if ($servers['status'] !== 200) {
            $this->logger->warning('Alexa server list failed', ['status' => $servers['status']]);

            return ['refusal' => $this->say(AlexaPhrases::LOOKUP_FAILED), 'serverId' => ''];
        }

        $rows = self::rows($servers['payload'], 'servers');
        if ($rows === []) {
            return ['refusal' => $this->say(AlexaPhrases::NO_SERVERS), 'serverId' => ''];
        }

        foreach ($rows as $row) {
            if (($row['relayActive'] ?? null) !== true) {
                continue;
            }
            $id = self::stringField($row, 'serverId');
            if ($id !== null) {
                return ['refusal' => null, 'serverId' => $id];
            }
        }

        return ['refusal' => $this->say(AlexaPhrases::NO_CONNECTED_SERVER), 'serverId' => ''];
    }

    /**
     * `GET /api/v1/media/{id}` for the matched row, unwrapped to the `item` object.
     *
     * @return array<string, mixed>|null Null when the read failed or the payload
     *         carried no `item` object.
     */
    private function detail(AlexaMediaGateway $gateway, string $serverId, string $mediaId): ?array
    {
        $result = $gateway->media($serverId, $mediaId);
        if ($result['status'] !== 200) {
            $this->logger->warning('Alexa media detail failed', [
                'status' => $result['status'],
                'server_id' => $serverId,
            ]);

            return null;
        }

        /** @var mixed $item */
        $item = $result['payload']['item'] ?? null;
        if (!is_array($item)) {
            return null;
        }

        return self::stringKeyed($item);
    }

    /**
     * Build the spoken fact fragments for one item.
     *
     * Only fields the server ACTUALLY returned become sentences; an absent
     * runtime is silence, never "unknown minutes". When `$detailSlot` names one
     * of {@see DETAIL_SYNONYMS}, only that fact is spoken — a user who asked how
     * long a film is does not want its plot read to them.
     *
     * @param array<string, mixed> $item       The server's shaped media item.
     * @param string|null          $detailSlot Raw `Detail` slot value, if any.
     *
     * @return list<string>
     */
    private function facts(array $item, ?string $detailSlot): array
    {
        $wanted = self::detailKey($detailSlot);

        $facts = [];

        $runtime = self::intField($item, 'runtime');
        if ($runtime !== null && $runtime > 0 && ($wanted === null || $wanted === 'runtime')) {
            $facts[] = AlexaSpeech::render(AlexaPhrases::FACT_RUNTIME, ['minutes' => $runtime]);
        }

        $year = self::intField($item, 'year');
        if ($year !== null && $year > 0 && ($wanted === null || $wanted === 'year')) {
            $facts[] = AlexaSpeech::render(AlexaPhrases::FACT_YEAR, ['year' => $year]);
        }

        $rating = self::stringField($item, 'rating');
        if ($rating !== null && ($wanted === null || $wanted === 'rating')) {
            $facts[] = AlexaSpeech::render(AlexaPhrases::FACT_RATING, ['rating' => $rating]);
        }

        $overview = self::stringField($item, 'overview');
        if ($overview !== null && ($wanted === null || $wanted === 'summary')) {
            $facts[] = AlexaSpeech::render(AlexaPhrases::FACT_SUMMARY, [
                'summary' => self::clamp($overview, self::SUMMARY_MAX_CHARS),
            ]);
        }

        return $facts;
    }

    /**
     * Map a spoken `Detail` slot value onto a fact key.
     *
     * @return string|null Null when the slot is absent or says nothing this
     *         controller recognises — in which case every fact is spoken.
     */
    private static function detailKey(?string $detailSlot): ?string
    {
        if ($detailSlot === null) {
            return null;
        }

        $spoken = strtolower(trim($detailSlot));
        if ($spoken === '') {
            return null;
        }

        foreach (self::DETAIL_SYNONYMS as $key => $synonyms) {
            if (in_array($spoken, $synonyms, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The capability statement, as a session-continuing question.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function capability(): array
    {
        return AlexaSpeech::ask(
            AlexaSpeech::render(AlexaPhrases::CAPABILITY),
            AlexaSpeech::render(AlexaPhrases::CAPABILITY_REPROMPT),
        );
    }

    /**
     * The honest refusal, for `AMAZON.FallbackIntent`, an unknown intent, and an
     * unknown request type.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function refusal(): array
    {
        return $this->say(AlexaPhrases::UNSUPPORTED_REQUEST);
    }

    /**
     * Render one template and wrap it in a session-ending statement.
     *
     * @param string                    $template One of the {@see AlexaPhrases} constants.
     * @param array<string, string|int> $values   Placeholder substitutions.
     *
     * @return array{version: string, response: array<string, mixed>}
     */
    private function say(string $template, array $values = []): array
    {
        return AlexaSpeech::tell(AlexaSpeech::render($template, $values));
    }

    /**
     * The short-circuit shape {@see resolveTitle()} returns.
     *
     * @param array{version: string, response: array<string, mixed>} $envelope
     *
     * @return array{
     *     refusal: array{version: string, response: array<string, mixed>},
     *     userId: string,
     *     serverId: string,
     *     mediaId: string,
     *     title: string
     * }
     */
    private static function refuse(array $envelope): array
    {
        return [
            'refusal' => $envelope,
            'userId' => '',
            'serverId' => '',
            'mediaId' => '',
            'title' => '',
        ];
    }

    /**
     * The capability surface for one resolved user.
     *
     * @param string $userId Hub user id from {@see AlexaAccountLink}.
     */
    private function gateway(Request $request, string $userId): AlexaMediaGateway
    {
        return new AlexaMediaGateway(
            $this->proxy,
            $this->serverList,
            $userId,
            $request->getTrustedClientIp(),
        );
    }

    /**
     * 200 with an Alexa response envelope.
     *
     * @param array{version: string, response: array<string, mixed>} $envelope
     */
    private function ok(array $envelope): Response
    {
        return (new Response())->json($envelope);
    }

    /**
     * The rows under `$key`, as `string`-keyed arrays.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $payload, string $key): array
    {
        /** @var mixed $raw */
        $raw = $payload[$key] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        /** @var mixed $row */
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = self::stringKeyed($row);
        }

        return $rows;
    }

    /**
     * Re-key an arbitrary decoded array so its keys are provably `string`.
     *
     * Built with {@see array_combine()} rather than a
     * `foreach ($source as $k => $v) { $typed[(string) $k] = $v; }` loop, and the
     * distinction is not cosmetic. The loop binds each decoded value to a `mixed`
     * variable, which Psalm's errorLevel 1 refuses (`MixedAssignment`) — PHPStan
     * level 9 does NOT, so the loop passed one analyser and failed the other.
     * `array_combine()` never names the values at all, and the key type is
     * DERIVED from the mapper's `: string` return rather than merely asserted.
     * {@see \Phlix\Hub\Mcp\McpToolContext::decode()} solves the identical problem
     * the identical way; this is that idiom, not a new one.
     *
     * A JSON object's numeric-looking key (`{"0": …}`) decodes to an `int` and is
     * CAST here rather than dropped, so no field of the server's payload is lost
     * on the way to {@see stringField()}.
     *
     * @param array<array-key, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $source): array
    {
        return array_combine(
            array_map(static fn (int|string $key): string => (string) $key, array_keys($source)),
            $source,
        );
    }

    /**
     * A non-empty, trimmed string field.
     *
     * @param array<string, mixed> $source
     */
    private static function stringField(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * An integer field, accepting the numeric-string spelling a JSON round trip
     * through the relay can produce.
     *
     * @param array<string, mixed> $source
     */
    private static function intField(array $source, string $key): ?int
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Trim `$text` to at most `$max` characters, adding an ellipsis when cut.
     */
    private static function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)) . '…';
    }
}
