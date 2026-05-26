<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\WebPortal;

use Smarty;

/**
 * Thin wrapper around {@see Smarty} for the hub's portal templates.
 *
 * Ported from `phlix-server`'s
 * `\Phlix\Server\WebPortal\PageRenderer::renderTemplate()`. The hub has
 * no library / playback responsibilities (those are server-side concerns)
 * so this class is much narrower than the server counterpart.
 *
 * Convention: every variable that originates from user input MUST be
 * escaped in the template with `|escape:'html'`. We deliberately do NOT
 * use Smarty's global `escape_html` setting because it applies
 * inconsistently across plugin/function/modifier output.
 *
 * @package Phlix\Hub\Common\WebPortal
 */
class PageRenderer
{
    /**
     * @param string $templateDir Absolute path to the template root.
     * @param string $compileDir  Absolute path Smarty may use for compiled templates.
     * @param string $cacheDir    Absolute path Smarty may use for the template cache.
     */
    public function __construct(
        private readonly string $templateDir,
        private readonly string $compileDir,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * Render a Smarty template to a string.
     *
     * @param string               $template Path relative to {@see self::$templateDir}.
     * @param array<string, mixed> $vars     Template variables.
     */
    public function render(string $template, array $vars = []): string
    {
        $smarty = $this->newSmarty();
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($vars as $key => $value) {
            $smarty->assign($key, $value);
        }
        /** @psalm-suppress RedundantCastGivenDocblockType */
        return (string) $smarty->fetch($template);
    }

    /**
     * Build a fresh {@see Smarty} instance configured against this
     * renderer's template / compile / cache directories.
     */
    private function newSmarty(): Smarty
    {
        $smarty = new Smarty();
        $smarty->setTemplateDir($this->templateDir);
        if (!is_dir($this->compileDir)) {
            @mkdir($this->compileDir, 0o775, true);
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
        $smarty->setCompileDir($this->compileDir);
        $smarty->setCacheDir($this->cacheDir);
        return $smarty;
    }
}
