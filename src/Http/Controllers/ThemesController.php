<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * `GET /api/v1/themes` — the hub's theme catalogue (HUB-S48, owner Gate 8: REGISTER).
 *
 * The hub serves the shared `@phlix/ui` SPA, whose appearance picker fetches the
 * catalogue from `GET /api/v1/themes`. The hub has no theme-plugin loader, so the
 * catalogue is exactly the three host built-ins, in the SAME wire shape
 * phlix-server's `ThemesController` emits (`TokenTheme::toArray()` plus
 * `builtIn`): `{"themes": [{id, name, dark, extends, tokens, source, builtIn}]}`.
 * The built-in payloads mirror phlix-server's `BuiltInThemes::PAYLOADS`; the SPA
 * applies a built-in via `data-theme` (its real values live in the shipped CSS),
 * so the token maps here are informational parity. No plugin themes are served
 * (`source` is `null`, `builtIn` is `true` for every entry).
 *
 * Registered behind the `/api/v1` group's AuthMiddleware, matching the server's
 * auth posture. The client's gate-and-degrade fallback (built-ins from its own
 * bundled CSS) stays in place regardless.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ThemesController
{
    /** @var list<array{id: string, name: string, dark: bool, extends: null, tokens: array<string, string>}> */
    private const PAYLOADS = [
        [
            'id' => 'nocturne',
            'name' => 'Nocturne',
            'dark' => true,
            'extends' => null,
            'tokens' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#fbab36',
                '--accent-active' => '#d98209',
                '--accent-soft' => 'rgba(245, 165, 36, 0.14)',
                '--accent-ring' => 'rgba(245, 165, 36, 0.55)',
                '--accent-text' => '#f5a524',
                '--bg' => '#0b0a08',
                '--surface' => '#16130f',
                '--surface-2' => '#211c16',
                '--surface-3' => '#2c251c',
                '--surface-glass' => 'rgba(28, 23, 17, 0.62)',
                '--surface-glass-strong' => 'rgba(20, 16, 11, 0.82)',
                '--text' => '#f3ece1',
                '--text-muted' => '#b8ab98',
                '--text-subtle' => '#918370',
                '--text-faint' => '#544a3f',
                '--text-on-accent' => '#2a1804',
                '--border' => '#2b2419',
                '--border-subtle' => '#1d1810',
                '--border-strong' => '#3a3122',
                '--error' => '#ef5e57',
                '--error-bg' => 'rgba(239, 94, 87, 0.14)',
                '--success' => '#61c073',
                '--success-bg' => 'rgba(97, 192, 115, 0.14)',
                '--warning' => '#e8a33d',
                '--warning-bg' => 'rgba(232, 163, 61, 0.14)',
                '--info' => '#5aa9d6',
                '--info-bg' => 'rgba(90, 169, 214, 0.14)',
                '--grain-opacity' => '0.05',
                '--vignette' => 'rgba(0, 0, 0, 0.55)',
                '--ambient' => 'rgba(245, 165, 36, 0.22)',
                '--color-bg' => '#0b0a08',
                '--color-surface' => '#16130f',
                '--color-surface-hover' => '#211c16',
                '--color-surface-elevated' => '#211c16',
                '--color-surface-active' => '#2c251c',
                '--color-text' => '#f3ece1',
                '--color-text-secondary' => '#b8ab98',
                '--color-text-muted' => '#b8ab98',
                '--color-text-subtle' => '#918370',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#fbab36',
                '--color-primary-active' => '#d98209',
                '--color-border' => '#2b2419',
                '--color-border-subtle' => '#1d1810',
                '--color-error' => '#ef5e57',
                '--color-error-bg' => 'rgba(239, 94, 87, 0.14)',
                '--color-success' => '#61c073',
                '--color-success-bg' => 'rgba(97, 192, 115, 0.14)',
                '--color-warning' => '#e8a33d',
                '--color-warning-bg' => 'rgba(232, 163, 61, 0.14)',
                '--color-info' => '#5aa9d6',
                '--color-info-bg' => 'rgba(90, 169, 214, 0.14)',
            ],
        ],
        [
            'id' => 'daylight',
            'name' => 'Daylight',
            'dark' => false,
            'extends' => null,
            'tokens' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#d98209',
                '--accent-active' => '#b4610a',
                '--accent-soft' => 'rgba(217, 130, 9, 0.12)',
                '--accent-ring' => 'rgba(146, 77, 16, 0.85)',
                '--accent-text' => '#924d10',
                '--bg' => '#f7f1e6',
                '--surface' => '#fffdf8',
                '--surface-2' => '#f1e8d8',
                '--surface-3' => '#e8dcc6',
                '--surface-glass' => 'rgba(255, 253, 248, 0.70)',
                '--surface-glass-strong' => 'rgba(255, 253, 248, 0.90)',
                '--text' => '#2a2017',
                '--text-muted' => '#6b5d49',
                '--text-subtle' => '#746753',
                '--text-faint' => '#b7a98f',
                '--text-on-accent' => '#2a1804',
                '--border' => '#e3d6c0',
                '--border-subtle' => '#efe6d6',
                '--border-strong' => '#d3c2a4',
                '--error' => '#b53832',
                '--error-bg' => 'rgba(195, 60, 54, 0.10)',
                '--success' => '#26743b',
                '--success-bg' => 'rgba(47, 143, 73, 0.10)',
                '--warning' => '#9b5309',
                '--warning-bg' => 'rgba(180, 97, 10, 0.10)',
                '--info' => '#2d6a91',
                '--info-bg' => 'rgba(47, 111, 151, 0.10)',
                '--grain-opacity' => '0.025',
                '--vignette' => 'rgba(74, 55, 20, 0.10)',
                '--ambient' => 'rgba(245, 165, 36, 0.18)',
                '--color-bg' => '#f7f1e6',
                '--color-surface' => '#fffdf8',
                '--color-surface-hover' => '#f1e8d8',
                '--color-surface-elevated' => '#f1e8d8',
                '--color-surface-active' => '#e8dcc6',
                '--color-text' => '#2a2017',
                '--color-text-secondary' => '#6b5d49',
                '--color-text-muted' => '#6b5d49',
                '--color-text-subtle' => '#746753',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#d98209',
                '--color-primary-active' => '#b4610a',
                '--color-border' => '#e3d6c0',
                '--color-border-subtle' => '#efe6d6',
                '--color-error' => '#b53832',
                '--color-error-bg' => 'rgba(195, 60, 54, 0.10)',
                '--color-success' => '#26743b',
                '--color-success-bg' => 'rgba(47, 143, 73, 0.10)',
                '--color-warning' => '#9b5309',
                '--color-warning-bg' => 'rgba(180, 97, 10, 0.10)',
                '--color-info' => '#2d6a91',
                '--color-info-bg' => 'rgba(47, 111, 151, 0.10)',
            ],
        ],
        [
            'id' => 'midnight',
            'name' => 'Midnight',
            'dark' => true,
            'extends' => null,
            'tokens' => [
                '--accent' => '#f5a524',
                '--accent-hover' => '#fbab36',
                '--accent-active' => '#d98209',
                '--accent-soft' => 'rgba(245, 165, 36, 0.16)',
                '--accent-ring' => 'rgba(245, 165, 36, 0.60)',
                '--accent-text' => '#f5a524',
                '--bg' => '#000000',
                '--surface' => '#0a0807',
                '--surface-2' => '#141009',
                '--surface-3' => '#1d1710',
                '--surface-glass' => 'rgba(10, 8, 6, 0.70)',
                '--surface-glass-strong' => 'rgba(0, 0, 0, 0.85)',
                '--text' => '#ede6da',
                '--text-muted' => '#a99d8c',
                '--text-subtle' => '#897a68',
                '--text-faint' => '#463d33',
                '--text-on-accent' => '#2a1804',
                '--border' => '#211b12',
                '--border-subtle' => '#120e09',
                '--border-strong' => '#322a1c',
                '--error' => '#ef5e57',
                '--error-bg' => 'rgba(239, 94, 87, 0.16)',
                '--success' => '#61c073',
                '--success-bg' => 'rgba(97, 192, 115, 0.16)',
                '--warning' => '#e8a33d',
                '--warning-bg' => 'rgba(232, 163, 61, 0.16)',
                '--info' => '#5aa9d6',
                '--info-bg' => 'rgba(90, 169, 214, 0.16)',
                '--grain-opacity' => '0.06',
                '--vignette' => 'rgba(0, 0, 0, 0.70)',
                '--ambient' => 'rgba(245, 165, 36, 0.24)',
                '--color-bg' => '#000000',
                '--color-surface' => '#0a0807',
                '--color-surface-hover' => '#141009',
                '--color-surface-elevated' => '#141009',
                '--color-surface-active' => '#1d1710',
                '--color-text' => '#ede6da',
                '--color-text-secondary' => '#a99d8c',
                '--color-text-muted' => '#a99d8c',
                '--color-text-subtle' => '#897a68',
                '--color-primary' => '#f5a524',
                '--color-primary-hover' => '#fbab36',
                '--color-primary-active' => '#d98209',
                '--color-border' => '#211b12',
                '--color-border-subtle' => '#120e09',
                '--color-error' => '#ef5e57',
                '--color-error-bg' => 'rgba(239, 94, 87, 0.16)',
                '--color-success' => '#61c073',
                '--color-success-bg' => 'rgba(97, 192, 115, 0.16)',
                '--color-warning' => '#e8a33d',
                '--color-warning-bg' => 'rgba(232, 163, 61, 0.16)',
                '--color-info' => '#5aa9d6',
                '--color-info-bg' => 'rgba(90, 169, 214, 0.16)',
            ],
        ],
    ];

    /**
     * `GET /api/v1/themes` — the whole catalogue.
     *
     * @param Request               $request The HTTP request (unused).
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function index(Request $request, array $params = []): Response
    {
        $themes = [];
        foreach (self::PAYLOADS as $payload) {
            $themes[] = $payload + ['source' => null, 'builtIn' => true];
        }

        return (new Response())->json(['themes' => $themes]);
    }
}
