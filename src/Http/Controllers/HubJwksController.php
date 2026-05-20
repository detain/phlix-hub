<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Serves the hub's signing JWKS at GET /.well-known/jwks.json.
 *
 * This is the public endpoint servers use to fetch the hub's Ed25519
 * public key for validating enrollment JWTs.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.3.0
 */
final class HubJwksController
{
    /**
     * @param Ed25519KeyManager $keyManager Hub's key manager.
     */
    public function __construct(
        private readonly Ed25519KeyManager $keyManager,
    ) {
    }

    /**
     * `GET /.well-known/jwks.json` — serve the hub's JWKS document.
     */
    public function __invoke(Request $request): Response
    {
        return (new Response())
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600')
            ->json(['keys' => [$this->keyManager->getPublicKeyJwk()]]);
    }
}
