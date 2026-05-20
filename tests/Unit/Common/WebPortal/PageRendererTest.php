<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\WebPortal;

use Phlix\Hub\Common\WebPortal\PageRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PageRenderer}.
 *
 * Exercises Smarty integration end-to-end against a transient template
 * directory in `sys_get_temp_dir()`.
 *
 * @package Phlix\Hub\Tests\Unit\Common\WebPortal
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Common\WebPortal\PageRenderer
 */
final class PageRendererTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phlix-hub-pr-' . uniqid();
        mkdir($this->tmp . '/templates', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmp);
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testRenderInjectsVarsAndEscapesHtml(): void
    {
        file_put_contents($this->tmp . '/templates/test.tpl', 'Hi {$name|escape:"html"}!');

        $renderer = new PageRenderer(
            $this->tmp . '/templates',
            $this->tmp . '/compile',
            $this->tmp . '/cache',
        );

        $html = $renderer->render('test.tpl', ['name' => '<script>alert(1)</script>']);
        self::assertStringContainsString('Hi &lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testRenderCreatesCompileDirIfMissing(): void
    {
        file_put_contents($this->tmp . '/templates/empty.tpl', 'static');
        $compileDir = $this->tmp . '/new-compile';
        self::assertDirectoryDoesNotExist($compileDir);

        $renderer = new PageRenderer(
            $this->tmp . '/templates',
            $compileDir,
            $this->tmp . '/cache',
        );
        $renderer->render('empty.tpl');

        self::assertDirectoryExists($compileDir);
    }
}
