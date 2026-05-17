<?php

declare(strict_types=1);

namespace Phlex\Hub\Http\Middleware;

use Phlex\Hub\Http\Request;
use Phlex\Hub\Http\Response;

/**
 * Validates the `Accept-Phlex-Protocol: v1` header on all server-claim
 * and server routes.
 *
 * Returns 400 HUB_PROTOCOL_UNSUPPORTED if the header is missing or
 * not exactly "v1".
 *
 * @package Phlex\Hub\Http\Middleware
 * @since 0.3.0
 */
final class HubProtocolMiddleware
{
    public const REQUIRED_VERSION = 'v1';
    public const HEADER_NAME = 'Accept-Phlex-Protocol';

    /**
     * Run the middleware. Returns null to continue routing, or a
     * {@see Response} to short-circuit with 400.
     */
    public function __invoke(Request $request): ?Response
    {
        $header = $request->getHeader(self::HEADER_NAME);
        if ($header === null || $header !== self::REQUIRED_VERSION) {
            return (new Response())->status(400)->json([
                'error' => 'HUB_PROTOCOL_UNSUPPORTED',
                'message' => 'Accept-Phlex-Protocol: v1 required',
            ]);
        }
        return null;
    }
}
