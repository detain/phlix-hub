<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\ViteAssets;

/**
 * Serves the HTML shell for the shared Vue 3 SPA (`@phlix/ui`) at `/app/*`.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   0.11.0 (Phase C)
 */
final class SharedUiController
{
    private const SHELL_RELATIVE_PATH = '/assets/app/index.html';

    /**
     * @param string $publicRoot Absolute path to the hub's `public/`
     *                           directory. The built SPA shell is read from
     *                           `$publicRoot . self::SHELL_RELATIVE_PATH`.
     */
    public function __construct(private readonly string $publicRoot)
    {
    }

    /**
     * Return the SPA HTML shell.
     *
     * Reads `public/assets/app/index.html`. If the bundle is absent,
     * returns a 503 with an actionable message.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 200 HTML shell, or 503 when the bundle is missing.
     *
     * @since 0.11.0 (Phase C)
     */
    public function shell(Request $request, array $params = []): Response
    {
        unset($request, $params);

        // Validate build exists via ViteAssets manifest check.
        try {
            $viteAssets = new ViteAssets($this->publicRoot);
            $viteAssets->getEntryJsPath();
        } catch (\RuntimeException) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Shared UI not built</h1>'
                    . '<p>The Vue SPA bundle is missing. '
                    . 'Run <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        $shellPath = $this->publicRoot . self::SHELL_RELATIVE_PATH;
        $real = realpath($shellPath);

        if ($real === false) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Shared UI not built</h1>'
                    . '<p>The Vue SPA bundle is missing. '
                    . 'Run <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        /** @var string $realPath */
        $realPath = $real;

        if (! str_starts_with($realPath, $this->publicRoot . DIRECTORY_SEPARATOR)) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Shared UI not built</h1>'
                    . '<p>The Vue SPA bundle is missing. '
                    . 'Run <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        if (! is_file($realPath)) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Shared UI not built</h1>'
                    . '<p>The Vue SPA bundle is missing. '
                    . 'Run <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        $html = file_get_contents($realPath);
        if ($html === false) {
            return (new Response())
                ->status(503)
                ->html('<h1>503 — Shared UI could not be read</h1>');
        }

        // Inject window.__PHLIX__ config
        $config = [
            'app' => 'hub',
            // apiBase: empty string = relative URLs (same-origin). Cross-origin would set actual URL.
            'apiBase' => '',
            'routerBase' => '/app',
            'menu' => [],
            'extraRoutes' => [],
            'features' => (object) [],
        ];

        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($configJson === false) {
            $configJson = '{}';
        }
        $configScript = '<script>window.__PHLIX__ = ' . $configJson . '</script>' . "\n";
        /** @var string $htmlContent */
        $htmlContent = $html;
        $htmlContent = preg_replace('/<head>/i', "<head>\n" . $configScript, $htmlContent, 1) ?? $htmlContent;

        return (new Response())->html($htmlContent);
    }
}
