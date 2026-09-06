<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * S286 — the OAuth admin commands are REGISTERED on `bin/phlix`, not merely
 * written.
 *
 * ## Why a subprocess and not a unit test
 *
 * A command class can be complete, correct and fully covered, and still be
 * unreachable because nobody added `$cli->add(...)`. That is the same shape as
 * S41/S174 — a controller method with no route line — and no test that
 * instantiates the command can see it, because the registration lives in
 * `bin/phlix`, a script that ends in `exit($cli->run())` and therefore cannot be
 * `require`d.
 *
 * So this runs the real script the way an operator does and reads its own
 * `list` output. That also pins a second property the shim documents and that
 * nothing else checks: `list` must work with **no database available**, because
 * every command's dependencies are behind a lazy factory. If a factory were
 * invoked at registration time this test would fail with a connection error
 * rather than a missing-command message — which is the correct outcome, since
 * `php bin/phlix list` opening a socket would break `bin/phlix migrate` on a
 * host whose database is not up yet.
 *
 * @package Phlix\Hub\Tests\Unit\Console
 */
final class CliCommandRegistrationTest extends TestCase
{
    /**
     * Every command `bin/phlix` must offer. The two pre-existing entries are
     * here as the anti-vacuity control: if the extractor or the subprocess broke,
     * these would go missing too, so a failure naming only the OAuth commands is
     * a real registration failure rather than a broken test.
     *
     * @var list<string>
     */
    private const array EXPECTED_COMMANDS = [
        'migrate',
        'smoke:jwt',
        'oauth:client:register',
        'oauth:client:list',
        'oauth:client:disable',
    ];

    public function testBinPhlixRegistersEveryExpectedCommandWithoutADatabase(): void
    {
        $output = $this->runCli('list');

        // HUB_DB_* is deliberately left pointing nowhere by runCli(): reaching a
        // database here would mean a command factory ran at registration time.
        self::assertStringNotContainsString(
            'SQLSTATE',
            $output,
            'bin/phlix list opened a database connection. Every command must resolve its '
            . 'dependencies through a lazy factory invoked inside execute().',
        );

        $missing = [];
        foreach (self::EXPECTED_COMMANDS as $command) {
            if (!str_contains($output, $command)) {
                $missing[] = $command;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "bin/phlix does not register %d command(s):\n  %s\nFull output:\n%s",
                count($missing),
                implode("\n  ", $missing),
                $output,
            ),
        );
    }

    /**
     * Each OAuth command must describe its own arguments — which proves the
     * registered object is the real class rather than a stub with a matching
     * name, and that `configure()` ran.
     */
    public function testTheRegisterCommandExposesItsScopeAndRedirectOptions(): void
    {
        $help = $this->runCli('help oauth:client:register');

        foreach (['--redirect-uri', '--scope', '--confidential', 'client-id'] as $token) {
            self::assertStringContainsString($token, $help, $token . ' is missing from the command help');
        }
    }

    /**
     * Run `php bin/phlix <args>` and return stdout+stderr.
     *
     * `HUB_DB_HOST` is pointed at an unroutable port so that ANY attempt to
     * connect fails loudly and fast, instead of silently succeeding against a
     * developer's local database and hiding an eager factory.
     */
    private function runCli(string $args): string
    {
        $root = dirname(__DIR__, 3);

        $command = sprintf(
            'HUB_DB_HOST=127.0.0.1 HUB_DB_PORT=1 HUB_DB_USER=nobody HUB_DB_PASSWORD=nothing '
            . 'HUB_DB_NAME=nothing %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/phlix'),
            $args,
        );

        $lines = [];
        exec($command, $lines, $exitCode);

        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, 'bin/phlix ' . $args . ' exited ' . $exitCode . ":\n" . $output);

        return $output;
    }
}
