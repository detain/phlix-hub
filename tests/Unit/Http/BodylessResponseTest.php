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
 * @covers \Phlix\Hub\Http\BodylessResponse
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
}
