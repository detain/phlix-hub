<?php

/**
 * Phlix hub component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands;

use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;
use function sprintf;
use function trim;

/**
 * `oauth:client:disable` — the other half of the admin surface (S286).
 *
 * Calls {@see OAuthClientRegistry::disable()}, which stamps `disabled_at` so the
 * registry's fail-closed lookup stops resolving the client: no new
 * authorization, no new code, no new token.
 *
 * ## Disabling and revoking are two actions, and this command keeps them two
 *
 * S92 split them on purpose — `disable()` stops NEW grants, and
 * {@see OAuthTokenService::revokeForClient()} cuts LIVE ones — so that an
 * operator can quietly stop a client from acquiring new users without logging
 * out everybody who already linked. Folding them together would remove that
 * choice, so `--revoke-tokens` is an explicit opt-in and the command prints
 * which of the two it did.
 *
 * ⚠ Without `--revoke-tokens`, tokens the client ALREADY holds keep working
 * until they expire (access tokens: 1 hour; refresh tokens: 30 days). That is
 * stated in the output because "I disabled the client" reads like "the client is
 * cut off", and for a compromised client it is not.
 *
 * @package Phlix\Hub\Console\Commands
 * @since   S286 (OAuth resource server, admin surface and prune timer)
 */
#[AsCommand(
    name: 'oauth:client:disable',
    description: 'Disable an OAuth 2.0 client so it can obtain no further grants',
)]
final class OAuthClientDisableCommand extends Command
{
    /**
     * @param callable(): OAuthClientRegistry $registryFactory Lazy registry.
     * @param callable(): OAuthTokenService   $tokenFactory    Lazy token store, used
     *        only when `--revoke-tokens` is passed.
     */
    public function __construct(
        private $registryFactory,
        private $tokenFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client-id', InputArgument::REQUIRED, 'Public client_id to disable')
            ->addOption(
                'revoke-tokens',
                null,
                InputOption::VALUE_NONE,
                'Also revoke every live access/refresh token already issued to this client. '
                . 'Use this when the client is compromised.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var mixed $raw */
        $raw      = $input->getArgument('client-id');
        $clientId = trim(is_string($raw) ? $raw : '');

        if ($clientId === '') {
            $output->writeln('<error>client-id must be non-empty.</error>');

            return Command::INVALID;
        }

        try {
            $registry = ($this->registryFactory)();

            // Reported, not enforced: `disable()` is idempotent, and refusing to
            // disable an already-unresolvable client would mean an operator
            // could not disable a half-provisioned row — which is exactly the
            // row most likely to need disabling.
            $wasResolvable = $registry->find($clientId) !== null;

            $registry->disable($clientId);

            $revoked = null;
            if ($input->getOption('revoke-tokens') === true) {
                $revoked = ($this->tokenFactory)()->revokeForClient($clientId);
            }
        } catch (Throwable $e) {
            $output->writeln('<error>Disable failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Disabled OAuth client</info>');
        $output->writeln('  client_id: ' . $clientId);
        $output->writeln(
            '  was resolvable before this call: ' . ($wasResolvable ? 'yes' : 'no'),
        );

        if ($revoked === null) {
            $output->writeln(
                '<comment>  Tokens already issued to this client remain valid until they expire '
                . '(access 1h, refresh 30d). Re-run with --revoke-tokens to cut them now.</comment>',
            );
        } else {
            $output->writeln(sprintf('  tokens revoked: %d', $revoked));
        }

        return Command::SUCCESS;
    }
}
