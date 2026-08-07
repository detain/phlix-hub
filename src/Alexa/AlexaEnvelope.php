<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use function is_array;
use function is_string;
use function json_decode;

/**
 * A parsed Alexa skill request envelope (S91).
 *
 * ## What this class is, and what it deliberately is not
 *
 * It is a READER of bytes that {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware}
 * has ALREADY proven came from Amazon. It performs no authentication of its own
 * and must never be used to make a trust decision: by the time
 * {@see fromRawBody()} is called, the signature over these exact bytes has been
 * verified against a chain anchored in the system trust store, and the replay
 * window has been enforced. Parsing an unverified body with this class would be
 * a security defect, not merely a design smell.
 *
 * It is NOT a validator of intent semantics. `PhlixTitleRuntimeIntent` with an
 * empty `Title` slot parses perfectly well here and is refused, in words, by
 * {@see \Phlix\Hub\Http\Controllers\AlexaSkillController}. The split is
 * deliberate: this class answers "is this an Alexa envelope at all?", the
 * controller answers "can I do what it asks?".
 *
 * ## Why `fromRawBody()` returns null rather than throwing
 *
 * A caller that reaches this point has already been authenticated as Amazon, so
 * a body this class cannot read is a protocol surprise, not an attack. The
 * controller answers such a body with a 400 and no speech. A thrown exception
 * would have to be caught at exactly one call site to produce the same result,
 * and an uncaught one inside a resident Workerman worker is strictly worse.
 *
 * ## The access token is read from BOTH documented places
 *
 * Amazon publishes the linked-account token at `session.user.accessToken` and,
 * for out-of-session requests, at `context.System.user.accessToken`. Reading
 * only one of them makes account linking work for some request shapes and
 * silently not for others, which presents to a user as an intermittent "please
 * link your account". `context` is preferred because it is present on every
 * request type, including ones that carry no `session` object at all.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaEnvelope
{
    /** Amazon's request type for "the user opened the skill by name". */
    public const TYPE_LAUNCH = 'LaunchRequest';

    /** Amazon's request type for a recognised intent. */
    public const TYPE_INTENT = 'IntentRequest';

    /** Amazon's request type for "the session ended"; a response body is forbidden. */
    public const TYPE_SESSION_ENDED = 'SessionEndedRequest';

    /**
     * @param string                $type        One of the `TYPE_*` constants, or
     *        any other type string Amazon may send (the controller refuses those).
     * @param string|null           $intentName  Intent name, only ever non-null for
     *        {@see TYPE_INTENT}.
     * @param array<string, string> $slots       Slot name => resolved value. Only
     *        slots that actually carried a non-empty string value are present, so
     *        `slot()` returning null means "absent OR empty", which is the only
     *        distinction any caller here needs.
     * @param string|null           $accessToken Linked-account bearer token.
     * @param string|null           $locale      e.g. `en-GB`.
     * @param string|null           $requestId   Amazon's per-request id, logged so a
     *        rejection can be correlated with a developer-console entry.
     */
    private function __construct(
        private readonly string $type,
        private readonly ?string $intentName,
        private readonly array $slots,
        private readonly ?string $accessToken,
        private readonly ?string $locale,
        private readonly ?string $requestId,
    ) {
    }

    /**
     * Parse an already-signature-verified request body.
     *
     * @param string $rawBody The exact bytes the signature was verified over.
     *
     * @return self|null Null when the payload is not a usable Alexa envelope:
     *         not a JSON object, no `request` object, no `request.type` string,
     *         or an `IntentRequest` with no `intent.name`.
     */
    public static function fromRawBody(string $rawBody): ?self
    {
        if ($rawBody === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        $request = self::subArray($decoded, 'request');
        if ($request === null) {
            return null;
        }

        $type = self::nonEmptyString($request, 'type');
        if ($type === null) {
            return null;
        }

        $intentName = null;
        $slots = [];
        if ($type === self::TYPE_INTENT) {
            $intent = self::subArray($request, 'intent');
            if ($intent === null) {
                return null;
            }
            $intentName = self::nonEmptyString($intent, 'name');
            if ($intentName === null) {
                return null;
            }
            $slots = self::readSlots($intent);
        }

        return new self(
            $type,
            $intentName,
            $slots,
            self::readAccessToken($decoded),
            self::nonEmptyString($request, 'locale'),
            self::nonEmptyString($request, 'requestId'),
        );
    }

    /** The envelope's `request.type`. */
    public function type(): string
    {
        return $this->type;
    }

    /** The intent name, or null when this is not an {@see TYPE_INTENT}. */
    public function intentName(): ?string
    {
        return $this->intentName;
    }

    /**
     * A slot's resolved value.
     *
     * @param string $name Slot name as declared in the interaction model.
     *
     * @return string|null Null when the slot is absent or carried no value.
     */
    public function slot(string $name): ?string
    {
        return $this->slots[$name] ?? null;
    }

    /** The linked-account bearer token, or null when the account is not linked. */
    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    /** The request locale (e.g. `en-GB`), or null. */
    public function locale(): ?string
    {
        return $this->locale;
    }

    /** Amazon's per-request id, or null. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * `session.user.accessToken` / `context.System.user.accessToken`.
     *
     * @param array<array-key, mixed> $envelope Decoded body.
     */
    private static function readAccessToken(array $envelope): ?string
    {
        $context = self::subArray($envelope, 'context');
        if ($context !== null) {
            $system = self::subArray($context, 'System');
            if ($system !== null) {
                $user = self::subArray($system, 'user');
                if ($user !== null) {
                    $token = self::nonEmptyString($user, 'accessToken');
                    if ($token !== null) {
                        return $token;
                    }
                }
            }
        }

        $session = self::subArray($envelope, 'session');
        if ($session === null) {
            return null;
        }
        $user = self::subArray($session, 'user');
        if ($user === null) {
            return null;
        }

        return self::nonEmptyString($user, 'accessToken');
    }

    /**
     * Read `intent.slots` into a flat `name => value` map.
     *
     * Only string values survive. Amazon also ships `resolutions` (the entity
     * resolution result) alongside `value`; this reads the raw `value` because
     * the Phlix interaction model uses `AMAZON.SearchQuery`-style free text,
     * where there is no catalogue to resolve against and `resolutions` is absent.
     *
     * @param array<array-key, mixed> $intent
     *
     * @return array<string, string>
     */
    private static function readSlots(array $intent): array
    {
        $slots = self::subArray($intent, 'slots');
        if ($slots === null) {
            return [];
        }

        $out = [];
        /** @var mixed $slot */
        foreach ($slots as $name => $slot) {
            if (!is_string($name) || !is_array($slot)) {
                continue;
            }
            $value = self::nonEmptyString($slot, 'value');
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * A nested array member, or null when absent / not an array.
     *
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>|null
     */
    private static function subArray(array $source, string $key): ?array
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * A non-empty string member, or null.
     *
     * @param array<array-key, mixed> $source
     */
    private static function nonEmptyString(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
