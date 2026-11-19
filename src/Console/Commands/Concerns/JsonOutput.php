<?php

/**
 * Phlix hub component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands\Concerns;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Shared `--json` rendering for the hub `user:*` console commands.
 *
 * Every command that mixes this trait gains an identical machine-readable
 * contract so a script driving the CLI can branch on one shape regardless of
 * which command produced the line:
 *
 *  - success → `{"ok":true,"data":[...]}`
 *  - failure → `{"ok":false,"error":"..."}`
 *
 * When `--json` is absent the commands render human-readable text instead and
 * never call into here, so the two output modes stay cleanly separated. This is
 * the hub-side copy of the same helper that ships in `phlix-server`
 * (`Phlix\Console\Commands\Concerns\JsonOutput`) — deliberately duplicated
 * rather than pushed into the tagged `detain/phlix-shared` package, so neither
 * repo's CLI depends on a package release to land its contract. The key order is
 * what the per-command tests assert.
 *
 * Hub-side divergence (HUB-CONSOLE, A-06): this copy also owns
 * {@see self::fail()}, the single failure renderer + exit-code chooser folded
 * out of the three command-local `fail()` clones (`user:promote`,
 * `user:create`, `user:delete`). The `phlix-server` copy still lacks that
 * method — folding its clones (they exist on six commands there) is a separate
 * estate decision, tracked report-only in the HUB-CONSOLE lane register.
 * Adopting commands must not re-declare `fail()` locally; the census in
 * `tests/Unit/Console/Concerns/JsonOutputTraitTest.php` pins the trait as the
 * declaring class for every adopted command, and
 * `tests/Unit/Console/Commands/UserCommandOutputByteMatrixTest.php` pins the
 * exact bytes and exit codes the fold preserved.
 *
 * {@see self::JSON_OUTPUT_MARKER} is a code-resident identity marker for the
 * merge tooling (mirrors `SidecarWriter::GENERATOR_MARKER`): because every
 * `user:*` command uses this trait, the constant survives into the compiled
 * source of both repos.
 */
trait JsonOutput
{
    public const string JSON_OUTPUT_MARKER = 'S61CLICOMMANDX4K7';

    /**
     * Whether the current invocation asked for machine-readable output.
     */
    protected function isJsonMode(InputInterface $input): bool
    {
        return $input->getOption('json') === true;
    }

    /**
     * Emit a success envelope. `$data` is normalised to a JSON array (list) so
     * the emitted shape always matches `{"ok":true,"data":[...]}` even for a
     * single logical record.
     *
     * @param array<array-key, mixed> $data Rows or records to expose under `data`.
     */
    protected function emitJsonSuccess(OutputInterface $output, array $data): void
    {
        $payload = [
            'ok' => true,
            'data' => array_values($data),
        ];

        $output->writeln($this->encode($payload));
    }

    /**
     * Emit a failure envelope carrying a single human/machine error string.
     */
    protected function emitJsonError(OutputInterface $output, string $error): void
    {
        $payload = [
            'ok' => false,
            'error' => $error,
        ];

        $output->writeln($this->encode($payload));
    }

    /**
     * Render an error in the active output mode and return the exit code.
     *
     * The single failure path for `user:*` commands: machine mode gets the
     * error envelope, human mode gets the decorated line, and the caller's
     * chosen exit code travels back untouched. The default mirrors what
     * `user:promote` and `user:delete` returned from their folded clones;
     * `user:create` passes `Command::INVALID` explicitly for input rejections.
     * Bytes and exit codes are pinned byte-identical to the pre-fold renders
     * by the byte-matrix suite.
     */
    protected function fail(
        InputInterface $input,
        OutputInterface $output,
        string $error,
        int $exitCode = Command::FAILURE,
    ): int {
        if ($this->isJsonMode($input)) {
            $this->emitJsonError($output, $error);
        } else {
            $output->writeln('<error>' . $error . '</error>');
        }

        return $exitCode;
    }

    /**
     * Encode the envelope to a single JSON line.
     *
     * `JSON_THROW_ON_ERROR` is deliberate: an unencodable value is a real bug,
     * and each caller wraps `execute()` in a `Throwable` guard so the failure
     * surfaces as a non-zero exit rather than a silently truncated line.
     *
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
