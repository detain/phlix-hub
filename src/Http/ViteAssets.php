<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use RuntimeException;

/**
 * Reads Vite's manifest.json to provide asset paths for the built SPA.
 *
 * @package Phlix\Hub\Http
 * @since   0.11.0 (Phase C)
 */
final class ViteAssets
{
    /**
     * The public URL base the SPA build is served under. Single source in this
     * class for BOTH facts it expresses: where the manifest lives inside
     * public/ and what prefix the entry URL needs. Must stay equal to
     * web-ui/vite.config.ts's `base` — the two ends of one wire.
     */
    private const string URL_BASE = '/assets/app/';

    private readonly string $manifestPath;

    /**
     * @param string $publicRoot Absolute path to the hub's public/ directory.
     */
    public function __construct(private readonly string $publicRoot)
    {
        $this->manifestPath = $this->publicRoot . self::URL_BASE . '.vite/manifest.json';
    }

    /**
     * Returns the PUBLIC URL of the entry JavaScript chunk from the Vite
     * manifest, composed with the build's base — i.e. a URL that actually
     * resolves when fetched (browser-side) and maps 1:1 onto the committed
     * file on disk (server-side: publicRoot . the returned URL).
     *
     * (The name used to be getEntryJsPath() and the value was '/' . file —
     * the manifest's `file` entries are base-RELATIVE ('assets/index-X.js'),
     * so that string was neither a filesystem path under public/ nor a
     * fetchable URL: it 404'd everywhere. ViteAssetsEntryUrlTest pins the
     * composition against the committed bundle.)
     *
     * @return string Absolute public URL, e.g. '/assets/app/assets/index-X.js'.
     *
     * @throws RuntimeException If the manifest file does not exist.
     */
    public function getEntryJsUrl(): string
    {
        if (! file_exists($this->manifestPath)) {
            throw new RuntimeException(
                "Vite manifest not found at {$this->manifestPath}. "
                . 'Run `cd web-ui && NPM_CONFIG_USERCONFIG=/dev/null npm ci && npm run build` first.'
            );
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            throw new RuntimeException("Could not read Vite manifest at {$this->manifestPath}.");
        }

        /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
        $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        foreach ($manifest as $entry) {
            if (($entry['isEntry'] ?? false) === true && isset($entry['file'])) {
                /** @var string $file */
                $file = $entry['file'];
                return self::URL_BASE . ltrim($file, '/');
            }
        }

        throw new RuntimeException(
            "No entry point found in Vite manifest at {$this->manifestPath}."
        );
    }

    /**
     * Returns the absolute path to the public root directory.
     */
    public function getPublicRoot(): string
    {
        return $this->publicRoot;
    }
}
