<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\BodylessResponse;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * S302 — pin the WIRING between `Response::$headOnly` and the encoder that
 * implements S247's fix, in whole wire bytes.
 *
 * ## What S302 was raised for, and what measurement actually showed
 *
 * codecov reported `src/Http/Response.php` patch coverage `0.00%` on #230 with
 * six lines missing — exactly the `$headOnly` property, the `headOnly()` setter,
 * and the three lines of the ternary in `toWorkermanResponse()` that selects
 * {@see BodylessResponse}. The step inferred from that number that the selector
 * could be deleted with the suite staying green.
 *
 * **It could not, and the number was a measurement artifact.** Replacing the
 * ternary with an unconditional `new WorkermanResponse(...)` at `b1d5948` failed
 * two tests already in the tree. What was wrong is the *attribution*:
 * `BodylessResponseTest` named only `BodylessResponse` in its class-level
 * `covers` annotation and `ServerProxyControllerTest` names only
 * `ServerProxyController` in its own, and that annotation DISCARDS every executed
 * line outside the units it names. Both files ran these lines; neither was
 * allowed to credit them, so the report said zero while the code was in fact
 * executed twice per run.
 *
 * ⚠ Do not write that annotation's name inside prose in a docblock, even in
 * backticks: PHPUnit's parser reads it wherever it appears and the surrounding
 * punctuation lands in the value, which makes it invalid — and an invalid entry
 * discards the file's coverage entirely, reproducing the very defect this test
 * documents. That happened once while writing this file.
 *
 * ## Why this file exists anyway
 *
 * The pre-existing selector test asserts `instanceof` plus a COUNT of
 * `Content-Length` fields. A count is satisfied by more than one wire shape, and
 * `instanceof` is a statement about the object rather than about the bytes the
 * socket receives. Every assertion below is `assertSame()` against the ENTIRE
 * rendered string — status line, every field, field order, CRLFs and the head
 * terminator — because the defect S247 closed was a property of the byte stream
 * and nothing else. (The estate has been burned by decoded-structure assertions
 * before: an MCP defect here was invisible to every test that decoded the body,
 * since `json_decode('{}')` and `json_decode('[]')` are both `[]` in PHP; only a
 * raw-string assertion caught it.)
 *
 * The GET row is not decoration. A test that only ever drives the TRUE arm
 * cannot tell the ternary apart from its own true branch: rendering
 * `BodylessResponse` unconditionally would keep every HEAD assertion green. The
 * GET expectation below contains Workerman's appended `Content-Length: 0`
 * verbatim, so that mutation reds here and the HEAD mutation reds above it.
 */
final class ResponseHeadWiringTest extends TestCase
{
    /**
     * The complete, correct HEAD reply for a direct-play probe: the paired
     * server's `Content-Length` and nothing after the head terminator.
     */
    private const HEAD_WIRE = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: video/x-matroska\r\n"
        . "Content-Length: 362807\r\n"
        . "Accept-Ranges: bytes\r\n"
        . "Connection: keep-alive\r\n"
        . "\r\n";

    /**
     * The SAME builder state without the flag, rendered by the stock encoder.
     *
     * The trailing `Content-Length: 0` is Workerman's, appended unconditionally
     * and last. On a GET it is correct — `$body` really is the entity, and it
     * really is empty. Reproducing it here is what makes the false arm of the
     * ternary observable: this string changes the moment the selector stops
     * choosing per-flag.
     */
    private const GET_WIRE = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: video/x-matroska\r\n"
        . "Content-Length: 362807\r\n"
        . "Accept-Ranges: bytes\r\n"
        . "Connection: keep-alive\r\n"
        . "Content-Length: 0\r\n"
        . "\r\n";

    /**
     * Build the exact shape a relayed direct-play probe produces.
     */
    private static function probeReply(): Response
    {
        return (new Response())
            ->status(200)
            ->header('Content-Type', 'video/x-matroska')
            ->header('Content-Length', '362807')
            ->header('Accept-Ranges', 'bytes');
    }

    /**
     * TRUE arm, whole bytes: `headOnly()` reaches the socket as a single
     * `Content-Length`, the server's, with no body.
     */
    public function testAHeadOnlyBuilderRendersTheExactHeadWireBytes(): void
    {
        $rendered = (string) self::probeReply()->headOnly()->toWorkermanResponse();

        $this->assertSame(
            self::HEAD_WIRE,
            $rendered,
            'the HEAD reply must reach the socket byte-for-byte as RFC 9110 §9.3.2 requires: the '
            . "GET's Content-Length, no second one, and nothing after the head terminator",
        );
    }

    /**
     * FALSE arm, whole bytes, and the control for the row above.
     *
     * Identical builder state, flag not set, and the stock encoder's duplicate
     * `Content-Length: 0` is expected verbatim — proving the two arms really do
     * produce different bytes and that the HEAD row is measuring the selector
     * rather than an encoder that behaves the same either way.
     */
    public function testADefaultBuilderStillRendersTheStockEncodersExactBytes(): void
    {
        $rendered = (string) self::probeReply()->toWorkermanResponse();

        $this->assertSame(
            self::GET_WIRE,
            $rendered,
            'CONTROL: an ordinary response must be untouched by S247 — it still goes through the '
            . 'stock encoder, appended Content-Length: 0 and all',
        );
        $this->assertNotSame(
            self::HEAD_WIRE,
            $rendered,
            'CONTROL: the two arms must differ on the wire, or the HEAD assertion above proves nothing',
        );
    }

    /**
     * The two arms of the selector, asserted against each other from one
     * builder — the flag is the ONLY difference between the two byte strings.
     */
    public function testTheFlagIsTheOnlyDifferenceBetweenTheTwoRenderings(): void
    {
        $response = self::probeReply();

        $asGet = (string) $response->toWorkermanResponse();
        $asHead = (string) $response->headOnly()->toWorkermanResponse();
        $backToGet = (string) $response->headOnly(false)->toWorkermanResponse();

        $this->assertSame(self::GET_WIRE, $asGet);
        $this->assertSame(self::HEAD_WIRE, $asHead);
        $this->assertSame(
            $asGet,
            $backToGet,
            'headOnly(false) must return the response to the stock encoding exactly, not approximately',
        );
    }

    /**
     * The setter itself: default argument, explicit `false`, and fluency.
     *
     * `headOnly()` with no argument must mean `true` — the whole call site in
     * `ServerProxyController::buildResponse()` is the bare `->headOnly()`.
     */
    public function testTheHeadOnlySetterDefaultsToTrueAndIsFluentAndReversible(): void
    {
        $response = new Response();
        $this->assertFalse($response->headOnly, 'a fresh response is never head-only');

        $returned = $response->headOnly();
        $this->assertSame($response, $returned, 'headOnly() must return the same builder for chaining');
        $this->assertTrue($response->headOnly, 'the bare headOnly() call must mean true');

        $this->assertSame($response, $response->headOnly(false));
        $this->assertFalse($response->headOnly);

        $this->assertSame($response, $response->headOnly(true));
        $this->assertTrue($response->headOnly);
    }

    /**
     * The flag selects the class, and the class is the subclass — asserted
     * separately from the bytes so a failure says which half broke.
     */
    public function testTheFlagSelectsTheEncoderClass(): void
    {
        $head = self::probeReply()->headOnly()->toWorkermanResponse();
        $get = self::probeReply()->toWorkermanResponse();

        $this->assertInstanceOf(BodylessResponse::class, $head);
        $this->assertNotInstanceOf(BodylessResponse::class, $get);
        $this->assertInstanceOf(
            WorkermanResponse::class,
            $get,
            'CONTROL: the false arm is still a Workerman response — the assertion above must be '
            . 'failing on the SUBCLASS, not on the type',
        );
    }

    /**
     * Cookies queued on the builder are emitted by whichever encoder was
     * selected, so the selection must not silently drop them.
     *
     * This also drives `BodylessResponse`'s array-valued header branch:
     * `WorkermanResponse::cookie()` appends into `$headers['Set-Cookie'][]`, so a
     * second cookie turns that field into an array and the subclass has to emit
     * one line per entry exactly as the parent does. Both cookies pass `secure`
     * explicitly so the rendering cannot depend on `HUB_COOKIE_INSECURE`.
     */
    public function testQueuedCookiesSurviveTheHeadEncoderSelection(): void
    {
        $rendered = (string) (new Response())
            ->status(200)
            ->header('Content-Length', '4096')
            ->header('Content-Type', 'video/mp4')
            ->cookie('sid', 'abc', 3600, '/', true, true, 'Lax')
            ->cookie('t', 'z', 0, '/', false, false, 'Strict')
            ->headOnly()
            ->toWorkermanResponse();

        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Length: 4096\r\n"
            . "Content-Type: video/mp4\r\n"
            . "Set-Cookie: sid=abc; Max-Age=3600; Path=/; Secure; HttpOnly; SameSite=Lax\r\n"
            . "Set-Cookie: t=z; Max-Age=0; Path=/; SameSite=Strict\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $rendered,
            'a head-only reply must still emit every queued cookie, one Set-Cookie line each',
        );
    }

    /**
     * A HEAD whose upstream declared NO length is left exactly as the framework
     * renders it, flag or no flag.
     *
     * This is the inner guard seen from the builder: `BodylessResponse` only
     * narrows the shape the parent renders invalidly, so setting the flag on any
     * other shape is a no-op on the wire. Without this row the selector could be
     * widened to "always use the subclass" without any test noticing.
     */
    public function testAHeadWithNoDeclaredLengthIsByteIdenticalToTheStockEncoding(): void
    {
        $builder = (new Response())->status(200)->header('Content-Type', 'video/mp4');

        $stock = (string) $builder->toWorkermanResponse();
        $flagged = (string) $builder->headOnly()->toWorkermanResponse();

        $this->assertSame(
            $stock,
            $flagged,
            'with no caller Content-Length there is nothing to preserve, so the flag must change '
            . "nothing — the framework's own Content-Length: 0 is the truthful value here",
        );
        $this->assertStringContainsString('Content-Length: 0', $flagged);
    }
}
