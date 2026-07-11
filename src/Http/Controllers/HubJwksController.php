<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Serves the hub's signing JWKS at GET /.well-known/jwks.json.
 *
 * This is the public endpoint servers use to fetch the hub's Ed25519
 * public key(s) for validating enrollment JWTs. During a key-rotation overlap
 * window it publishes BOTH the current key and the retained previous key, so
 * 7-day enrollment JWTs minted before the rotation keep validating until they
 * naturally expire; once the previous key's overlap lapses only the current
 * key is published.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class HubJwksController
{
    /**
     * @param Ed25519KeyManager    $keyManager   Hub's key manager.
     * @param RateLimiterInterface $rateLimiter  Bounded, TTL-windowed rate limiter keyed by client IP.
     */
    public function __construct(
        private readonly Ed25519KeyManager $keyManager,
        private readonly RateLimiterInterface $rateLimiter,
    ) {
    }

    /**
     * `GET /.well-known/jwks.json` — serve the hub's JWKS document.
     */
    public function __invoke(Request $request): Response
    {
        // HB-4.6: rate limit by client IP since JWKS is unauthenticated
        // and a flood of requests could be a DoS vector.
        $ip = $request->remoteIp !== '' ? $request->remoteIp : 'unknown';
        $state = $this->rateLimiter->hit('jwks:' . $ip);
        if ($state->limited) {
            throw new RateLimitException(
                resetAt: $state->resetAt,
                remaining: 0,
            );
        }

        return (new Response())
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600')
            ->json(['keys' => $this->keyManager->getPublicKeyJwks()]);
    }
}
