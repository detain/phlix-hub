<?php

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands;

use Phlix\Hub\Common\Database\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `migrate` — apply the hub database migrations under `migrations/*.sql`.
 *
 * Thin console wrapper around {@see MigrationRunner}: it runs the same
 * tracking-table-gated apply loop that `scripts/run-migrations.php` uses,
 * renders a human summary, and maps the result to a process exit code.
 *
 * The runner is built lazily through an injected factory so constructing
 * this command — e.g. for `bin/phlix list` — never opens a database
 * connection. The factory (and therefore the connection) is only invoked
 * inside {@see self::execute()}.
 *
 * @package Phlix\Hub\Console\Commands
 *
 * @since 0.6.0
 */
#[AsCommand(name: 'migrate', description: 'Apply database migrations (migrations/*.sql)')]
final class MigrateCommand extends Command
{
    /** @var callable(): MigrationRunner */
    private $runnerFactory;

    /**
     * @param callable(): MigrationRunner $runnerFactory Lazy factory producing a configured
     *                                                   {@see MigrationRunner}. Invoked only
     *                                                   inside {@see self::execute()} so that
     *                                                   constructing the command (and `list`)
     *                                                   opens no database connection.
     */
    public function __construct(callable $runnerFactory)
    {
        parent::__construct();
        $this->runnerFactory = $runnerFactory;
    }

    /**
     * Apply pending migrations and render a summary.
     *
     * Mirrors `scripts/run-migrations.php`:
     * - empty migrations directory → "No SQL migrations found…", exit 0;
     * - a runner failure → "<error>Migration failed: …</error>", exit 1;
     * - nothing pending → "All migrations already applied…", exit 0;
     * - otherwise one "Applied: <file>" line per newly applied migration
     *   followed by "Migrations complete (N applied).", exit 0.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = ($this->runnerFactory)();

        $files = $runner->discoverFiles();
        if ($files === []) {
            $output->writeln('No SQL migrations found.');

            return Command::SUCCESS;
        }

        try {
            $ran = $runner->run();
        } catch (Throwable $e) {
            $output->writeln('<error>Migration failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($ran === []) {
            $output->writeln('All migrations already applied. Nothing to do.');

            return Command::SUCCESS;
        }

        foreach ($ran as $filename) {
            $output->writeln('Applied: ' . $filename);
        }
        $output->writeln(sprintf('Migrations complete (%d applied).', count($ran)));

        return Command::SUCCESS;
    }
}
