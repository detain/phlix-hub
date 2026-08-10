<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\BodylessResponse;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

use function preg_match_all;
use function str_ends_with;
use function substr_count;

/**
 * S247 — a relayed `HEAD` must put exactly ONE `Content-Length` on the wire, and
 * it must be the paired server's.
 *
 * Players probe the direct-play byte stream with `HEAD` before they open it. If
 * the reply carries a body, or the wrong `Content-Length`, the player breaks
 * rather than erroring — the worst failure shape, because nothing reports an
 * error anywhere. Workerman's own encoder appends `Content-Length: strlen($body)`
 * unconditionally and LAST, so relaying the server's real length with an empty
 * body produced two conflicting fields (RFC 9110 §8.6: the message is invalid).
 *
 * Every assertion here is on the RENDERED string — what actually goes down the
 * socket — never on the builder's header array, because the defect lived
 * entirely in the encoder.
 *
 * S302 — the second entry in the class-level annotation below, naming
 * `Phlix\Hub\Http\Response`, is deliberate. Two of the cases here drive the
 * builder (`Response::headOnly()` → `toWorkermanResponse()`), and that annotation
 * DISCARDS every executed line outside the units it names: with only the
 * `BodylessResponse` entry, those lines ran on every single run and were still
 * reported as `0.00%` covered, which is how codecov came to name six "missing"
 * lines that the suite in fact executes. Naming both classes credits what the
 * file really exercises.
 *
 * ⚠ Never write that annotation's name in prose, even in backticks — PHPUnit
 * reads it wherever it appears, the surrounding punctuation lands in the value,
 * and an invalid entry discards this file's coverage completely.
 *
 * @covers \Phlix\Hub\Http\BodylessResponse
 * @covers \Phlix\Hub\Http\Response
 */
final class BodylessResponseTest extends TestCase
{
    /**
     * The defect, stated as a control: the stock Workerman encoder really does
     * emit TWO `Content-Length` fields for this exact shape.
     *
     * Without this row the test below could pass because the shape never
     * produced a duplicate in the first place — i.e. it would be proving
     * nothing. This is the positive control for the whole file.
     */
    public function testTheStockEncoderEmitsTwoContentLengthFieldsForASizedBodylessReply(): void
    {
        $stock = (string) new WorkermanResponse(
            200,
            ['Content-Type' => 'video/x-matroska', 'Content-Length' => '362807', 'Accept-Ranges' => 'bytes'],
            '',
        );

        $this->assertSame(
            2,
            preg_match_all('/^Content-Length:/mi', $stock),
            'CONTROL: the stock Workerman encoder must emit two Content-Length fields here — '
            . 'if it ever stops, BodylessResponse is no longer needed and this suite is measuring nothing',
        );
        $this->assertStringContainsString('Content-Length: 362807', $stock);
        $this->assertStringContainsString('Content-Length: 0', $stock);
    }

    public function testASizedBodylessReplyEmitsExactlyOneContentLengthAndNoBody(): void
    {
        $rendered = (string) new BodylessResponse(
            200,
            ['Content-Type' => 'video/x-matroska', 'Content-Length' => '362807', 'Accept-Ranges' => 'bytes'],
            '',
        );

        $this->assertSame(
            1,
            preg_match_all('/^Content-Length:/mi', $rendered),
            'a HEAD reply must carry exactly one Content-Length',
        );
        $this->assertStringContainsString('Content-Length: 362807', $rendered);
        $this->assertStringNotContainsString('Content-Length: 0', $rendered);
        $this->assertStringContainsString('Accept-Ranges: bytes', $rendered);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $rendered);
        // Head terminated by the blank line and nothing after it.
        $this->assertTrue(str_ends_with($rendered, "\r\n\r\n"), 'a HEAD reply must end at the head terminator');
        $this->assertSame(1, substr_count($rendered, "\r\n\r\n"), 'nothing may follow the head terminator');
    }

    public function testDefaultsAreAddedExactlyAsTheParentAddsThem(): void
    {
        $rendered = (string) new BodylessResponse(200, ['Content-Length' => '10'], '');

        $this->assertStringContainsString('Connection: keep-alive', $rendered);
        $this->assertStringContainsString('Content-Type: text/html;charset=utf-8', $rendered);
    }

    public function testAnExplicitConnectionHeaderIsNotDuplicated(): void
    {
        $rendered = (string) new BodylessResponse(
            200,
            ['Content-Length' => '10', 'connection' => 'close', 'content-type' => 'video/mp4'],
            '',
        );

        $this->assertSame(1, preg_match_all('/^Connection:/mi', $rendered));
        $this->assertSame(1, preg_match_all('/^Content-Type:/mi', $rendered));
        $this->assertStringContainsString('connection: close', $rendered);
    }

    public function testCrlfInAHeaderValueIsDroppedExactlyAsTheParentDropsIt(): void
    {
        $rendered = (string) new BodylessResponse(
            200,
            ['Content-Length' => '10', 'X-Evil' => "a\r\nX-Injected: yes"],
            '',
        );

        $this->assertStringNotContainsString('X-Injected', $rendered);
        $this->assertStringNotContainsString('X-Evil', $rendered);
    }

    /**
     * Shapes the parent already renders correctly must be delegated UNCHANGED,
     * so this class is a safe drop-in. Each row is rendered by both encoders and
     * the two strings must be identical.
     *
     * @return iterable<string, array{0: int, 1: array<string, string>, 2: string}>
     */
    public static function delegatedShapeProvider(): iterable
    {
        yield 'no Content-Length at all' => [200, ['Content-Type' => 'video/mp4'], ''];
        yield 'a real body present' => [200, ['Content-Length' => '3'], 'abc'];
        yield 'no headers at all' => [204, [], ''];
        yield 'Transfer-Encoding present' => [
            200,
            ['Content-Length' => '10', 'Transfer-Encoding' => 'chunked'],
            '',
        ];
        yield 'server-sent events' => [
            200,
            ['Content-Length' => '10', 'Content-Type' => 'text/event-stream'],
            '',
        ];
        yield 'a redirect' => [302, ['Location' => '/app'], ''];
    }

    /**
     * @dataProvider delegatedShapeProvider
     *
     * @param array<string, string> $headers
     */
    public function testEveryOtherShapeIsByteIdenticalToTheParentEncoder(
        int $status,
        array $headers,
        string $body,
    ): void {
        $this->assertSame(
            (string) new WorkermanResponse($status, $headers, $body),
            (string) new BodylessResponse($status, $headers, $body),
            'BodylessResponse must narrow ONLY the sized-bodyless-reply case; every other shape '
            . 'has to render exactly as the framework renders it',
        );
    }

    /**
     * The OUTER guard: the builder selects this encoder only on the explicit
     * `headOnly` flag, never merely because a body happens to be empty.
     *
     * A GET with an empty body and a stale non-zero `Content-Length` is a
     * keep-alive framing desync, not a HEAD — treating it as authoritative would
     * swap one defect for a worse one.
     */
    public function testTheBuilderSelectsTheBodylessEncoderOnlyForAnExplicitHeadReply(): void
    {
        $get = (new Response())->status(200)->header('Content-Length', '362807');
        $this->assertFalse($get->headOnly);
        $this->assertNotInstanceOf(BodylessResponse::class, $get->toWorkermanResponse());
        $this->assertSame(
            2,
            preg_match_all('/^Content-Length:/mi', (string) $get->toWorkermanResponse()),
            'CONTROL: a plain GET is unchanged — it still goes through the stock encoder',
        );

        $head = (new Response())->status(200)->header('Content-Length', '362807')->headOnly();
        $this->assertTrue($head->headOnly);
        $this->assertInstanceOf(BodylessResponse::class, $head->toWorkermanResponse());
        $this->assertSame(
            1,
            preg_match_all('/^Content-Length:/mi', (string) $head->toWorkermanResponse()),
            'a HEAD reply must carry exactly one Content-Length once rendered',
        );
    }

    public function testHeadOnlyCanBeSwitchedBackOff(): void
    {
        $response = (new Response())->status(200)->header('Content-Length', '5')->headOnly()->headOnly(false);

        $this->assertFalse($response->headOnly);
        $this->assertNotInstanceOf(BodylessResponse::class, $response->toWorkermanResponse());
    }

    // -----------------------------------------------------------------------
    // S302 — the header-rendering branches the S247 suite never entered.
    //
    // Each row below closes a specific line of the re-implemented emission loop.
    // They matter because that loop is a HAND COPY of the parent's: any branch of
    // it that no test enters is a place the copy can drift from the original
    // without the drift being visible, and header emission is where a drift
    // becomes a header-injection hole rather than a wrong byte count.
    // -----------------------------------------------------------------------

    /**
     * A caller-set reason phrase is emitted instead of the standard one.
     *
     * The parent uses `?:`, which cannot distinguish an unset reason from `''`;
     * this class uses a strict test so that `''` still falls back to the standard
     * phrase. Only the "reason is present and non-empty" leg was unentered.
     */
    public function testACustomReasonPhraseIsEmittedInsteadOfTheStandardOne(): void
    {
        $rendered = (string) (new BodylessResponse(200, ['Content-Length' => '10'], ''))
            ->withStatus(200, 'Totally Fine');

        $this->assertSame(
            "HTTP/1.1 200 Totally Fine\r\n"
            . "Content-Length: 10\r\n"
            . "Connection: keep-alive\r\n"
            . "Content-Type: text/html;charset=utf-8\r\n"
            . "\r\n",
            $rendered,
        );
    }

    /**
     * An empty reason phrase falls back to the standard one, as an unset one
     * does — the reason the strict test exists at all.
     */
    public function testAnEmptyReasonPhraseFallsBackToTheStandardPhrase(): void
    {
        $rendered = (string) (new BodylessResponse(200, ['Content-Length' => '10'], ''))
            ->withStatus(200, '');

        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $rendered);
    }

    /**
     * A header NAME carrying a colon or CRLF is skipped, exactly as the parent
     * skips it. This is the field-name half of the injection guard; the value
     * half is covered above.
     */
    public function testAnUnsafeHeaderFieldNameIsSkippedExactlyAsTheParentSkipsIt(): void
    {
        $headers = ['Content-Length' => '10', 'X-Bad: name' => 'v', 'Content-Type' => 'video/mp4'];

        $rendered = (string) new BodylessResponse(200, $headers, '');
        $parent = (string) new WorkermanResponse(200, $headers, '');

        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Length: 10\r\n"
            . "Content-Type: video/mp4\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $rendered,
        );
        $this->assertStringNotContainsString(
            'X-Bad',
            $parent,
            'CONTROL: the parent drops this field name too — the subclass is matching it, not '
            . 'inventing a stricter rule of its own',
        );
    }

    /**
     * An array-valued header emits one field line per entry, in order — the
     * shape `WorkermanResponse::cookie()` produces for a second cookie.
     */
    public function testAnArrayValuedHeaderEmitsOneFieldLinePerEntry(): void
    {
        $rendered = (string) new BodylessResponse(
            200,
            ['Content-Length' => '10', 'Content-Type' => 'video/mp4', 'X-Multi' => ['one', 'two']],
            '',
        );

        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Length: 10\r\n"
            . "Content-Type: video/mp4\r\n"
            . "X-Multi: one\r\n"
            . "X-Multi: two\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $rendered,
        );
    }

    /**
     * Inside an array-valued header, an unsafe entry is dropped INDIVIDUALLY —
     * the safe siblings still go out. Dropping the whole field, or emitting the
     * unsafe entry, would both be wrong; only a per-entry test tells them apart.
     *
     * The `stdClass` entry additionally proves the subclass is SAFER than the
     * parent here: the parent casts each entry with `(string)`, which raises an
     * `Error` on an object with no `__toString()`, whereas this renderer returns
     * null and skips it.
     */
    public function testAnUnsafeEntryInsideAnArrayValuedHeaderIsDroppedIndividually(): void
    {
        $rendered = (string) new BodylessResponse(
            200,
            [
                'Content-Length' => '10',
                'Content-Type'   => 'video/mp4',
                'X-Multi'        => ['ok', "bad\r\nX-Injected: yes", new \stdClass()],
            ],
            '',
        );

        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Length: 10\r\n"
            . "Content-Type: video/mp4\r\n"
            . "X-Multi: ok\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $rendered,
        );
        $this->assertStringNotContainsString('X-Injected', $rendered);
    }

    /**
     * Non-string header values render: ints, floats, bools and `Stringable`.
     *
     * `Response::header()` only accepts strings, but the parent's header map is
     * untyped `array` and Workerman itself writes an int into it, so the renderer
     * has to handle the shapes the framework can produce, not only the ones the
     * hub's own builder produces.
     */
    public function testNonStringScalarAndStringableHeaderValuesAreRendered(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'sv';
            }
        };

        $rendered = (string) new BodylessResponse(
            200,
            [
                'Content-Length' => 10,
                'Content-Type'   => 'video/mp4',
                'X-Int'          => 7,
                'X-Bool'         => true,
                'X-Float'        => 1.5,
                'X-Str'          => $stringable,
            ],
            '',
        );

        $this->assertSame(
            "HTTP/1.1 200 OK\r\n"
            . "Content-Length: 10\r\n"
            . "Content-Type: video/mp4\r\n"
            . "X-Int: 7\r\n"
            . "X-Bool: 1\r\n"
            . "X-Float: 1.5\r\n"
            . "X-Str: sv\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
            $rendered,
            'an integer Content-Length must still be treated as authoritative and rendered once',
        );
    }
}
