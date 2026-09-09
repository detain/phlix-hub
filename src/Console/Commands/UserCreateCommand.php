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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function filter_var;
use function is_string;
use function preg_match;
use function strlen;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * `user:create {username} --email= --password= [--display-name=] [--json]` — add an account.
 *
 * Applies the same field rules the hub's public registration path enforces
 * (username 3–50 chars, letters/digits/underscore only; a valid email; a
 * non-empty password) before delegating to {@see UserRepository::create()},
 * which Argon2ID-hashes the supplied plain password. A username collision or a
 * duplicate email is refused as `Command::INVALID` rather than surfacing as a
 * database UNIQUE violation.
 *
 * The `display_name` key is ALWAYS passed to the repository (as `?string`) so
 * the array literal matches `create()`'s declared shape even when the operator
 * omitted the option — `create()` then falls back to the username internally.
 *
 * With `--json` the freshly created (non-secret) account summary is emitted
 * under the shared success envelope. The {@see UserRepository} is resolved
 * lazily through the injected factory so constructing this command never opens
 * a database connection.
 *
 * @package Phlix\Hub\Console\Commands
 */
#[AsCommand(name: 'user:create', description: 'Create a new hub user account')]
final class UserCreateCommand extends Command
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
        $this
            ->addArgument(
                'username',
                InputArgument::REQUIRED,
                'The new username (3-50 chars; letters, digits, underscore)',
            )
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The account email address (required)')
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'The plain password to set (required; hashed at rest)',
            )
            ->addOption(
                'display-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional display name (defaults to the username)',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable {"ok":true,"data":[...]} JSON');
    }

    /**
     * Validate, create and report.
     *
     * @return int {@see Command::INVALID} (2) for a bad field or a collision;
     *         {@see Command::FAILURE} (1) when the repository throws; else
     *         {@see Command::SUCCESS} (0).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $usernameArg = $input->getArgument('username');
        $username = trim(is_string($usernameArg) ? $usernameArg : '');
        $email = trim(self::stringOption($input, 'email'));
        $password = self::stringOption($input, 'password');
        $displayNameOption = self::stringOption($input, 'display-name');
        $displayName = $displayNameOption !== '' ? $displayNameOption : null;

        // Mirror the registration field rules exactly, in the same order, so
        // `--json` consumers get identical validation semantics over CLI.
        $invalid = $this->validate($input, $output, $username, $email, $password);
        if ($invalid !== null) {
            return $invalid;
        }

        try {
            $repository = ($this->userRepositoryFactory)();

            if ($repository->usernameExists($username)) {
                return $this->fail($input, $output, 'Username already exists: ' . $username, Command::INVALID);
            }
            if ($repository->emailExists($email)) {
                return $this->fail($input, $output, 'Email already registered: ' . $email, Command::INVALID);
            }

            $id = $repository->create([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'display_name' => $displayName,
            ]);
        } catch (Throwable $e) {
            return $this->fail($input, $output, 'User creation failed: ' . $e->getMessage(), Command::FAILURE);
        }

        if ($this->isJsonMode($input)) {
            $this->emitJsonSuccess($output, [[
                'id' => $id,
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName ?? $username,
            ]]);

            return Command::SUCCESS;
        }

        $output->writeln('Created user "' . $username . '" (' . $id . ').');

        return Command::SUCCESS;
    }

    /**
     * Enforce the shared field contract. Returns a non-null exit code to abort.
     */
    private function validate(
        InputInterface $input,
        OutputInterface $output,
        string $username,
        string $email,
        string $password,
    ): ?int {
        if (strlen($username) < 3 || strlen($username) > 50) {
            return $this->fail($input, $output, 'Username must be 3-50 characters.', Command::INVALID);
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $username) !== 1) {
            return $this->fail(
                $input,
                $output,
                'Username must be alphanumeric with underscores only.',
                Command::INVALID,
            );
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($input, $output, 'Invalid email format.', Command::INVALID);
        }
        if ($password === '') {
            return $this->fail($input, $output, 'A --password is required.', Command::INVALID);
        }

        return null;
    }

    /**
     * Render an error in the active output mode and return the given exit code.
     */
    private function fail(InputInterface $input, OutputInterface $output, string $error, int $exitCode): int
    {
        if ($this->isJsonMode($input)) {
            $this->emitJsonError($output, $error);
        } else {
            $output->writeln('<error>' . $error . '</error>');
        }

        return $exitCode;
    }

    private static function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }
}
