<?php

declare(strict_types=1);

/**
 * Smoke test for the JwtHandler → JwtClaims round-trip wiring.
 *
 * Mints an access token using the hub's `JwtHandler`, validates it back
 * through the same handler, and prints the decoded claims. Exits 0 on
 * success, 1 on any mismatch. Run from the project root:
 *
 *     php scripts/smoke-jwt-roundtrip.php
 *
 * This script is the post-condition smoke for Step B.7 — it proves the
 * cross-repo `Phlex\Shared\Auth\JwtClaims` wire actually works.
 *
 * @package Phlex\Hub
 * @since 0.2.0
 */

use Phlex\Hub\Auth\JwtHandler;
use Phlex\Shared\Auth\JwtClaims;

require_once __DIR__ . '/../vendor/autoload.php';

$handler = new JwtHandler(str_repeat('s', 64));
$userId = '8a7f0e2c-1234-4abc-89ef-fedcba012345';
$scope = ['library:read', 'playback:write'];
$serverId = 'server-001';

$token = $handler->createAccessToken($userId, $scope, $serverId);
$claims = $handler->validateAccessToken($token);

if (!$claims instanceof JwtClaims) {
    fwrite(STDERR, "FAIL: validateAccessToken did not return JwtClaims\n");
    exit(1);
}

$expected = [
    'iss' => JwtClaims::ISS_PHLEX_HUB,
    'aud' => JwtClaims::AUD_HUB,
    'sub' => $userId,
    'type' => JwtClaims::TYPE_ACCESS,
    'scope' => $scope,
    'serverId' => $serverId,
];

$actual = [
    'iss' => $claims->iss,
    'aud' => $claims->aud,
    'sub' => $claims->sub,
    'type' => $claims->type,
    'scope' => $claims->scope,
    'serverId' => $claims->serverId,
];

foreach ($expected as $key => $value) {
    if ($actual[$key] !== $value) {
        fwrite(STDERR, sprintf("FAIL: %s mismatch: expected %s, got %s\n",
            $key, json_encode($value), json_encode($actual[$key])));
        exit(1);
    }
}

echo "OK: JWT round-trip succeeded\n";
echo "  Token (first 40 chars): " . substr($token, 0, 40) . "...\n";
echo "  Decoded claim class:    " . get_class($claims) . "\n";
echo "  iss=" . $claims->iss . " aud=" . $claims->aud . " sub=" . $claims->sub . "\n";
echo "  type=" . $claims->type . " scope=" . implode(',', $claims->scope) . " serverId=" . ($claims->serverId ?? 'null') . "\n";
echo "  iat=" . $claims->iat . " exp=" . $claims->exp . "\n";
exit(0);
