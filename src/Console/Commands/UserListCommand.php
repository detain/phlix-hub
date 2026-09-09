<?php

/**
 * Phlix hub component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\Concerns\JsonOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_bool;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * `user:list [--json]` — list hub accounts.
 *
 * The hub `users` table has no account-status column (that is a `phlix-server`
 * concept), so this command lists every account with no `--status` filter. The
 * backing {@see UserRepository::findAll()} projects only the public columns
 * (`id, username, email, is_admin, created_at, updated_at`) and never selects
 * `password_hash`, so nothing secret can leak through here — no local redaction
 * pass is required, unlike the server's `SELECT *` list command.
 *
 * With `--json` the rows are emitted under the shared
 * `{"ok":true,"data":[...]}` envelope; otherwise a fixed-width human table is
 * printed. The {@see UserRepository} is resolved lazily through the injected
 * factory so constructing this command never opens a database connection, which
 * keeps `php bin/phlix list` working with no database available.
 *
 * @package Phlix\Hub\Console\Commands
 */
#[AsCommand(name: 'user:list', description: 'List hub user accounts')]
final class UserListCommand extends Command
{
    use JsonOutput;

    /** @var callable(): UserRepository Lazy factory for the backing repository. */
    private $userRepositoryFactory;

    /**
     * @param callable(): UserRepository $userRepositoryFactory Lazy factory
     *        returning the backing {@see UserRepository}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $userRepositoryFactory)
    {
        $this->userRepositoryFactory = $userRepositoryFactory;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * Resolve every account and render it.
     *
     * @return int {@see Command::FAILURE} (1) when the repository throws; else
     *         {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $rows = ($this->userRepositoryFactory)()->findAll();
        } catch (Throwable $e) {
            $error = 'Failed to list users: ' . $e->getMessage();
            if ($this->isJsonMode($input)) {
                $this->emitJsonError($output, $error);

                return Command::FAILURE;
            }
            $output->writeln('<error>' . $error . '</error>');

            return Command::FAILURE;
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, $rows);

            return Command::SUCCESS;
        }

        if ($rows === []) {
            $output->writeln('No users found.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '%-38s %-20s %-40s %-6s %-20s',
            'ID',
            'USERNAME',
            'EMAIL',
            'ADMIN',
            'CREATED',
        ));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-38s %-20s %-40s %-6s %-20s',
                self::str($row['id'] ?? null),
                self::str($row['username'] ?? null),
                self::str($row['email'] ?? null),
                self::isTruth($row['is_admin'] ?? null) ? 'yes' : 'no',
                self::str($row['created_at'] ?? null),
            ));
        }

        return Command::SUCCESS;
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null || is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function isTruth(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return false;
    }
}
