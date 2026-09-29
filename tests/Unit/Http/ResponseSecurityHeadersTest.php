<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http;

use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * F3 — baseline security headers on the shared Response emission path.
 *
 * Every hub-emitted response gets `X-Content-Type-Options: nosniff`; HTML
 * responses additionally get frame-protection headers (the OAuth consent
 * precedent). Explicitly-set values always win, case-insensitively.
 *
 * @package Phlix\Hub\Tests\Unit\Http
 */
final class ResponseSecurityHeadersTest extends TestCase
{
    public function testJsonResponseCarriesNosniff(): void
    {
        $rendered = (string) (new Response())->json(['ok' => true])->toWorkermanResponse();

        self::assertStringContainsString("X-Content-Type-Options: nosniff\r\n", $rendered);
    }

    public function testHtmlResponseCarriesFrameProtection(): void
    {
        $rendered = (string) (new Response())->html('<h1>hi</h1>')->toWorkermanResponse();

        self::assertStringContainsString("X-Content-Type-Options: nosniff\r\n", $rendered);
        self::assertStringContainsString("X-Frame-Options: DENY\r\n", $rendered);
        self::assertStringContainsString("Content-Security-Policy: frame-ancestors 'none'\r\n", $rendered);
    }

    public function testNonHtmlResponseDoesNotGetFrameProtection(): void
    {
        $rendered = (string) (new Response())
            ->header('Content-Type', 'application/octet-stream')
            ->toWorkermanResponse();

        self::assertStringNotContainsString('X-Frame-Options', $rendered);
        self::assertStringNotContainsString('frame-ancestors', $rendered);
    }

    public function testExplicitHeadersWinAndAreNeverDuplicated(): void
    {
        // Pre-set every baseline field (varying case, as upstream code does in
        // OAuthController::secure()) — the defaults must neither overwrite nor
        // duplicate them.
        $response = (new Response())
            ->html('<p>consent</p>')
            ->header('x-content-type-options', 'nosniff; embedded-checking')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Content-Security-Policy', "frame-ancestors 'self'");

        $rendered = (string) $response->toWorkermanResponse();
        $lower = strtolower($rendered);

        self::assertSame(1, substr_count($lower, 'x-content-type-options:'));
        self::assertSame(1, substr_count($lower, 'x-frame-options:'));
        self::assertSame(1, substr_count($lower, 'content-security-policy:'));
        self::assertStringContainsString('x-content-type-options: nosniff; embedded-checking', $lower);
        self::assertStringContainsString('sameorigin', $lower);
        self::assertStringNotContainsString("frame-ancestors 'none'", $lower);
    }

    public function testHeadEncoderPathAlsoCarriesTheBaseline(): void
    {
        // The bodyless (HEAD) arm builds its header array in the same method,
        // so the baseline must survive encoder selection too.
        $rendered = (string) (new Response())
            ->status(200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Length', '4096')
            ->headOnly()
            ->toWorkermanResponse();

        self::assertStringContainsString("X-Content-Type-Options: nosniff\r\n", $rendered);
        self::assertStringContainsString("X-Frame-Options: DENY\r\n", $rendered);
    }
}
