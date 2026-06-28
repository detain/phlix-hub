<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Http\Middleware\CsrfMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the double-submit-cookie {@see CsrfMiddleware} (step S3).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 *
 * @covers \Phlix\Hub\Http\Middleware\CsrfMiddleware
 */
final class CsrfMiddlewareTest extends TestCase
{
    public function testRejectsWhenCookieAndFieldMissing(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function testRejectsWhenFieldMissing(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->headers = ['COOKIE' => CsrfMiddleware::COOKIE_CSRF . '=cookie-token'];

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function testRejectsWhenCookieMissing(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->body = [CsrfMiddleware::FIELD => 'submitted-token'];

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function testRejectsOnMismatch(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->headers = ['COOKIE' => CsrfMiddleware::COOKIE_CSRF . '=cookie-token'];
        $request->body = [CsrfMiddleware::FIELD => 'a-different-token'];

        $response = $mw($request);
        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
    }

    public function testPassesWhenCookieAndFieldMatch(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/login';
        $request->headers = ['COOKIE' => CsrfMiddleware::COOKIE_CSRF . '=matching-token; other=1'];
        $request->body = [CsrfMiddleware::FIELD => 'matching-token'];

        self::assertNull($mw($request), 'matching double-submit token must pass');
    }

    public function testIssueSetsCookieAndReplacesPlaceholder(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/login';

        $response = (new Response())->html(
            '<form><input type="hidden" name="_csrf" value="' . CsrfMiddleware::PLACEHOLDER . '"></form>'
        );

        $issued = $mw->issue($request, $response);

        // A CSRF cookie was queued.
        $csrfCookie = null;
        foreach ($issued->cookies as $cookie) {
            if ($cookie['name'] === CsrfMiddleware::COOKIE_CSRF) {
                $csrfCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($csrfCookie);
        self::assertNotSame('', $csrfCookie['value']);
        self::assertTrue($csrfCookie['secure']);
        self::assertSame('Strict', $csrfCookie['same_site']);

        // The placeholder was replaced with that same token in the body.
        self::assertStringNotContainsString(CsrfMiddleware::PLACEHOLDER, $issued->body);
        self::assertStringContainsString('value="' . $csrfCookie['value'] . '"', $issued->body);
    }

    public function testIssueReusesExistingCookieToken(): void
    {
        $mw = new CsrfMiddleware();
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/login';
        $request->headers = ['COOKIE' => CsrfMiddleware::COOKIE_CSRF . '=existing-token'];

        $response = (new Response())->html('<input value="' . CsrfMiddleware::PLACEHOLDER . '">');
        $issued = $mw->issue($request, $response);

        self::assertStringContainsString('value="existing-token"', $issued->body);
    }

    public function testIssueThenValidateRoundTrips(): void
    {
        $mw = new CsrfMiddleware();

        // 1. Render a form page, issuing the token.
        $getRequest = new Request();
        $getRequest->method = 'GET';
        $getRequest->path = '/login';
        $page = $mw->issue(
            $getRequest,
            (new Response())->html('<input name="_csrf" value="' . CsrfMiddleware::PLACEHOLDER . '">'),
        );

        $token = null;
        foreach ($page->cookies as $cookie) {
            if ($cookie['name'] === CsrfMiddleware::COOKIE_CSRF) {
                $token = $cookie['value'];
            }
        }
        self::assertIsString($token);

        // 2. Submit the form, echoing the cookie + field.
        $postRequest = new Request();
        $postRequest->method = 'POST';
        $postRequest->path = '/login';
        $postRequest->headers = ['COOKIE' => CsrfMiddleware::COOKIE_CSRF . '=' . $token];
        $postRequest->body = [CsrfMiddleware::FIELD => $token];

        self::assertNull($mw($postRequest), 'a token issued by issue() must validate on the matching POST');
    }
}
