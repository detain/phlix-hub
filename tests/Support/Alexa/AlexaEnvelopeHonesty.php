<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Alexa;

use Phlix\Hub\Alexa\AlexaHonesty;

use function in_array;
use function is_array;
use function is_string;
use function strtolower;

/**
 * The recursive honesty walker for a decoded Alexa response envelope.
 *
 * ## Why this is a shared support class rather than a private test method
 *
 * S91 put this walker inside `AlexaSkillControllerTest`. S93 added a second suite
 * (`AlexaPlayInAppIntentTest`) that must apply the IDENTICAL rule to the
 * `PhlixPlayInAppIntent` envelopes. Copying the walker would have created two
 * definitions of "honest", and the copy that got weakened would be the one nobody
 * was reading — a detector silently narrowed in one file while the other file's
 * anti-vacuity control kept reporting green. There is now one definition, and
 * every anti-vacuity control in the suite proves THAT definition can fire.
 *
 * ## What it looks for
 *
 *  - any key named `directives`, `AudioPlayer` or `VideoApp`, case-insensitively
 *    (Amazon's own spelling is mixed case, so an exact-match check would let
 *    `Directives` through), at ANY depth; and
 *  - any string leaf that trips {@see AlexaHonesty::violations()}.
 *
 * ⚠ Deliberate scope: it inspects string LEAVES, so it would also flag a user's
 * own film called *Roku*. Fixtures fed to it must therefore be clean, so that a
 * zero measures the SKILL's words rather than the library's. That the USER's data
 * is NOT policed is asserted separately, in
 * `AlexaSkillControllerTest::testATitleContainingABannedWordIsAnsweredRatherThanRefused()`.
 *
 * @package Phlix\Hub\Tests\Support\Alexa
 */
final class AlexaEnvelopeHonesty
{
    /** Keys Amazon uses to actually drive a device. None may ever appear. */
    public const DEVICE_CONTROL_KEYS = ['directives', 'audioplayer', 'videoapp'];

    /**
     * Every honesty problem in `$value`, as human-readable strings.
     *
     * @param array<array-key, mixed> $value
     * @param string                  $path  Dotted path, for the failure message.
     *
     * @return list<string> Empty means the envelope is honest.
     */
    public static function violations(array $value, string $path = '$'): array
    {
        $found = [];

        /** @var mixed $child */
        foreach ($value as $key => $child) {
            $here = $path . '.' . (string) $key;

            if (is_string($key) && in_array(strtolower($key), self::DEVICE_CONTROL_KEYS, true)) {
                $found[] = 'device-control key "' . strtolower($key) . '" at ' . $here;
            }

            if (is_array($child)) {
                foreach (self::violations($child, $here) as $nested) {
                    $found[] = $nested;
                }
                continue;
            }

            if (is_string($child)) {
                foreach (AlexaHonesty::violations($child) as $term) {
                    $found[] = 'banned term "' . $term . '" in the string at ' . $here;
                }
            }
        }

        return $found;
    }
}
