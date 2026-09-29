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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
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
                InputOption::VALUE_OPTIONAL,
                'The plain password to set (hashed at rest). Omit to be prompted '
                . '(interactive) or pipe the password on a single stdin line (scripted).',
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
        /** @var mixed $usernameArg */
        $usernameArg = $input->getArgument('username');
        $username = trim(is_string($usernameArg) ? $usernameArg : '');
        $email = trim(self::stringOption($input, 'email'));
        $password = $this->resolvePassword($input, $output);
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
     * Resolve the plain password without forcing it onto the command line.
     *
     * `--password=` keeps working, but a value passed that way is visible in
     * the process table and shell history, so an OMITTED option now falls back
     * to a hidden interactive prompt, or — when not interactive (piped
     * CI/scripting) — to a single line read from stdin. An explicitly empty
     * `--password=` stays an immediate validation failure (the operator asked
     * for the empty value on purpose; never block a script on a prompt).
     */
    private function resolvePassword(InputInterface $input, OutputInterface $output): string
    {
        /** @var mixed $raw */
        $raw = $input->getOption('password');
        if (is_string($raw)) {
            return $raw;
        }

        if ($input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Password (input hidden): ');
            $question->setHidden(true);
            // Terminals without stty support degrade to a visible prompt
            // rather than failing — operator still controls what is echoed.
            $question->setHiddenFallback(true);

            /** @var mixed $answered */
            $answered = $helper->ask($input, $output, $question);

            return is_string($answered) ? $answered : '';
        }

        return $this->readPasswordLine($input);
    }

    /**
     * Read one password line from the input stream (or straight from stdin
     * when no stream was attached to the input object).
     */
    private function readPasswordLine(InputInterface $input): string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $openedHere = false;
        if ($stream === null) {
            $handle = fopen('php://stdin', 'r');
            $stream = $handle === false ? null : $handle;
            $openedHere = true;
        }
        if (!is_resource($stream)) {
            return '';
        }

        $line = fgets($stream);
        if ($openedHere) {
            fclose($stream);
        }

        return $line === false ? '' : rtrim($line, "\r\n");
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
            return $this->fail(
                $input,
                $output,
                'A password is required (--password, the interactive prompt, or one stdin line).',
                Command::INVALID,
            );
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
        /** @var mixed $value */
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }
}
