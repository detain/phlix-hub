<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Console\Commands;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Console\Commands\UserCreateCommand;
use Phlix\Hub\Console\Commands\UserDeleteCommand;
use InvalidArgumentException;
use Phlix\Hub\Console\Commands\UserPromoteCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A-06/A-15 characterization net for the three hub `user:*` commands whose
 * hand-rolled `fail()` clones were folded into the JsonOutput trait.
 *
 * Every case pins the exit code AND the complete display bytes — exact
 * equality, never `assertStringContainsString` — captured from the
 * pre-adoption implementation (HUB-CONSOLE lane, fixtures/matrix-PRE*.txt).
 * The failure path's byte sequence is the contract: without `--json` the
 * `<error>…</error>` render comes out undecorated as the bare message plus a
 * newline; with `--json` the single-line envelope plus newline. Any drift in
 * wording, envelope key order, escaping, trailing newline, or exit code
 * (FAILURE (1) vs INVALID (2) on `user:create`) reddens this file.
 *
 * Repository state is a spec array; the mock is built per case so no
 * interaction expectations compete with the byte assertions. The two stream
 * legs exercise the direct `run()` + `BufferedOutput` shape (interactive must
 * be false for the stdin password line to be read at all — CommandTester
 * otherwise forces the prompt path).
 *
 * @package Phlix\Hub\Tests\Unit\Console\Commands
 */
final class UserCommandOutputByteMatrixTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ALICE = ['id' => 'u1', 'username' => 'alice', 'is_admin' => 0];
    /** @var array<string, mixed> */
    private const ROOT = ['id' => 'u2', 'username' => 'root', 'is_admin' => 1];
    /** @var array<string, mixed> */
    private const MISSING_ID = ['username' => 'zoe', 'is_admin' => 0];

    /** CommandTester run with interactivity disabled (the commands' default shape). */
    private const MODE_TESTER = 'tester';
    /** CommandTester run with interactive=true — the hidden-prompt password leg. */
    private const MODE_INTERACTIVE = 'interactive';
    /**
     * Direct `run()` over an ArrayInput carrying a data:// stream and
     * interactive=false — the scripted stdin password line / empty-stream legs.
     */
    private const MODE_STREAM = 'stream';

    /**
     * @param array<string, mixed> $spec Stub wiring for the repository mock.
     * @param array<string, mixed> $inputs Command inputs for this case.
     */
    #[DataProvider('promoteMatrix')]
    public function testPromoteByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserPromoteCommand::class, 'user:promote', $this->repo($spec)));

        self::assertSame($exit, $tester->execute($inputs));
        self::assertSame($bytes, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $spec Stub wiring for the repository mock.
     * @param array<string, mixed> $inputs Command inputs for this case.
     * @param string $mode One of the MODE_* constants.
     */
    #[DataProvider('createMatrix')]
    public function testCreateByteMatrix(
        array $spec,
        array $inputs,
        int $exit,
        string $mode,
        string $stream,
        string $bytes,
    ): void {
        $repository = $this->repo($spec);
        $command = $this->command(UserCreateCommand::class, 'user:create', $repository);

        if ($mode === self::MODE_STREAM) {
            $input = new ArrayInput($inputs, $command->getDefinition());
            $input->setInteractive(false);
            $handle = fopen('data://text/plain,' . $stream, 'r');
            self::assertIsResource($handle);
            $input->setStream($handle);
            $output = new BufferedOutput();

            self::assertSame($exit, $command->run($input, $output));
            self::assertSame($bytes, $output->fetch());

            return;
        }

        $tester = new CommandTester($command);
        if ($mode === self::MODE_INTERACTIVE) {
            $tester->setInputs(['secret123']);
        }

        self::assertSame($exit, $tester->execute($inputs, ['interactive' => $mode === self::MODE_INTERACTIVE]));
        self::assertSame($bytes, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $spec Stub wiring for the repository mock.
     * @param array<string, mixed> $inputs Command inputs for this case.
     */
    #[DataProvider('deleteMatrix')]
    public function testDeleteByteMatrix(array $spec, array $inputs, int $exit, string $bytes): void
    {
        $tester = new CommandTester($this->command(UserDeleteCommand::class, 'user:delete', $this->repo($spec)));

        self::assertSame($exit, $tester->execute($inputs));
        self::assertSame($bytes, $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function repo(array $spec): UserRepository
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findByUsername')->willReturn($spec['byUsername'] ?? null);
        $repository->method('findByEmail')->willReturn($spec['byEmail'] ?? null);
        $repository->method('countAdmins')->willReturn($spec['adminCount'] ?? 0);
        $repository->method('usernameExists')->willReturn($spec['usernameTaken'] ?? false);
        $repository->method('emailExists')->willReturn($spec['emailTaken'] ?? false);

        $repository->method('create')->willReturn('new-id');

        $throwOn = $spec['throwOn'] ?? '';
        if ($throwOn === 'setAdmin') {
            $repository->method('setAdmin')->willThrowException(new RuntimeException('db down'));
        }
        if ($throwOn === 'create') {
            $repository->method('create')->willThrowException(new RuntimeException('db down'));
        }
        if ($throwOn === 'delete') {
            $repository->method('delete')->willThrowException(new RuntimeException('db down'));
        }

        return $repository;
    }

    /**
     * @param class-string<Command> $class
     */
    private function command(string $class, string $name, UserRepository $repository): Command
    {
        $factory = static fn (): UserRepository => $repository;
        $application = new Application();
        $application->add(match ($class) {
            UserPromoteCommand::class => new UserPromoteCommand($factory),
            UserCreateCommand::class => new UserCreateCommand($factory),
            UserDeleteCommand::class => new UserDeleteCommand($factory),
            default => throw new InvalidArgumentException('unpinned command: ' . $class),
        });

        return $application->find($name);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function promoteMatrix(): array
    {
        return [
            'promote.ok_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                0,
                "User \"alice\" promoted to admin.\n",
            ],
            'promote.ok_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"is_admin\":true}]}\n",
            ],
            'promote.ok_email_resolution' => [
                ['byEmail' => self::ALICE],
                ['user' => 'alice@example.com'],
                0,
                "User \"alice@example.com\" promoted to admin.\n",
            ],
            'promote.notfound_plain' => [
                [],
                ['user' => 'ghost'],
                1,
                "User not found: ghost\n",
            ],
            'promote.notfound_json' => [
                [],
                ['user' => 'ghost', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"User not found: ghost\"}\n",
            ],
            'promote.revoke_ok' => [
                ['byUsername' => self::ROOT, 'adminCount' => 2],
                ['user' => 'root', '--revoke' => true],
                0,
                "User \"root\" demoted from admin.\n",
            ],
            'promote.revoke_last_json' => [
                ['byUsername' => self::ROOT, 'adminCount' => 1],
                ['user' => 'root', '--revoke' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot demote the last admin.\"}\n",
            ],
            'promote.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe'],
                1,
                "User record is missing a valid id.\n",
            ],
            'promote.write_throws' => [
                ['byUsername' => self::ALICE, 'throwOn' => 'setAdmin'],
                ['user' => 'alice'],
                1,
                "Admin change failed: db down\n",
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     0: array<string, mixed>,
     *     1: array<string, mixed>,
     *     2: int,
     *     3: string,
     *     4: string,
     *     5: string,
     * }>
     */
    public static function createMatrix(): array
    {
        return [
            'create.flags_plain' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123'],
                0,
                self::MODE_TESTER,
                '',
                "Created user \"bob\" (new-id).\n",
            ],
            'create.flags_json' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                0,
                self::MODE_TESTER,
                '',
                "{\"ok\":true,\"data\":[{\"id\":\"new-id\",\"username\":\"bob\","
                . "\"email\":\"bob@example.com\",\"display_name\":\"bob\"}]}\n",
            ],
            'create.displayname_json' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123',
                    '--display-name' => 'Bobby', '--json' => true],
                0,
                self::MODE_TESTER,
                '',
                "{\"ok\":true,\"data\":[{\"id\":\"new-id\",\"username\":\"bob\","
                . "\"email\":\"bob@example.com\",\"display_name\":\"Bobby\"}]}\n",
            ],
            'create.interactive_prompt' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com'],
                0,
                self::MODE_INTERACTIVE,
                '',
                "Password (input hidden): \nCreated user \"bob\" (new-id).\n",
            ],
            'create.stdin_line' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com'],
                0,
                self::MODE_STREAM,
                "piped-secret\ntrailing-ignored",
                "Created user \"bob\" (new-id).\n",
            ],
            'create.dup_username' => [
                ['usernameTaken' => true],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123'],
                2,
                self::MODE_TESTER,
                '',
                "Username already exists: bob\n",
            ],
            'create.dup_email_json' => [
                ['emailTaken' => true],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                2,
                self::MODE_TESTER,
                '',
                "{\"ok\":false,\"error\":\"Email already registered: bob@example.com\"}\n",
            ],
            'create.short_username' => [
                [],
                ['username' => 'ab', '--email' => 'a@b.com', '--password' => 'x'],
                2,
                self::MODE_TESTER,
                '',
                "Username must be 3-50 characters.\n",
            ],
            'create.bad_username_chars' => [
                [],
                ['username' => 'b@d', '--email' => 'a@b.com', '--password' => 'x'],
                2,
                self::MODE_TESTER,
                '',
                "Username must be alphanumeric with underscores only.\n",
            ],
            'create.bad_email' => [
                [],
                ['username' => 'bob', '--email' => 'not-an-email', '--password' => 'x'],
                2,
                self::MODE_TESTER,
                '',
                "Invalid email format.\n",
            ],
            'create.empty_password' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => ''],
                2,
                self::MODE_TESTER,
                '',
                "A password is required (--password, the interactive prompt, or one stdin line).\n",
            ],
            'create.no_password_no_stream' => [
                [],
                ['username' => 'bob', '--email' => 'bob@example.com'],
                2,
                self::MODE_STREAM,
                '',
                "A password is required (--password, the interactive prompt, or one stdin line).\n",
            ],
            'create.repo_throws_json' => [
                ['throwOn' => 'create'],
                ['username' => 'bob', '--email' => 'bob@example.com', '--password' => 'secret123', '--json' => true],
                1,
                self::MODE_TESTER,
                '',
                "{\"ok\":false,\"error\":\"User creation failed: db down\"}\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: int, 3: string}>
     */
    public static function deleteMatrix(): array
    {
        return [
            'delete.confirm_refused_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice'],
                1,
                "Refusing to delete user \"alice\"; re-run with --force to confirm.\n",
            ],
            'delete.confirm_refused_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Refusing to delete user \\\"alice\\\"; re-run with --force to confirm.\"}\n",
            ],
            'delete.force_ok_plain' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--force' => true],
                0,
                "Deleted user \"alice\" (u1).\n",
            ],
            'delete.force_ok_json' => [
                ['byUsername' => self::ALICE],
                ['user' => 'alice', '--force' => true, '--json' => true],
                0,
                "{\"ok\":true,\"data\":[{\"id\":\"u1\",\"username\":\"alice\",\"deleted\":true}]}\n",
            ],
            'delete.notfound_plain' => [
                [],
                ['user' => 'ghost', '--force' => true],
                1,
                "User not found: ghost\n",
            ],
            'delete.notfound_json' => [
                [],
                ['user' => 'ghost', '--force' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"User not found: ghost\"}\n",
            ],
            'delete.last_admin_with_force_json' => [
                ['byUsername' => self::ROOT, 'adminCount' => 1],
                ['user' => 'root', '--force' => true, '--json' => true],
                1,
                "{\"ok\":false,\"error\":\"Cannot delete the last admin.\"}\n",
            ],
            'delete.last_admin_no_force_plain' => [
                ['byUsername' => self::ROOT, 'adminCount' => 1],
                ['user' => 'root'],
                1,
                "Cannot delete the last admin.\n",
            ],
            'delete.force_admin_not_last_ok' => [
                ['byUsername' => self::ROOT, 'adminCount' => 2],
                ['user' => 'root', '--force' => true],
                0,
                "Deleted user \"root\" (u2).\n",
            ],
            'delete.repo_throws' => [
                ['byUsername' => self::ALICE, 'throwOn' => 'delete'],
                ['user' => 'alice', '--force' => true],
                1,
                "User deletion failed: db down\n",
            ],
            'delete.missing_id' => [
                ['byUsername' => self::MISSING_ID],
                ['user' => 'zoe', '--force' => true],
                1,
                "User record is missing a valid id.\n",
            ],
        ];
    }
}
