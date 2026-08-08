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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function implode;

/**
 * `oauth:client:list` — show what is actually in `oauth_clients` (S286).
 *
 * The read half of the admin surface. Without it, `oauth:client:disable` asks an
 * operator to name a `client_id` they have no way to look up, and a
 * registration that silently produced an UNUSABLE row (an empty allow-list, a
 * confidential client with no stored hash) would be indistinguishable from a
 * successful one.
 *
 * That last case is why {@see OAuthClientRegistry::listAll()} reports a `usable`
 * column derived from the production {@see OAuthClientRegistry::find()} lookup
 * rather than from the columns it just read: a row can exist, not be disabled,
 * and still be refused by every endpoint. Showing only what `find()` accepts
 * would hide exactly the rows an operator is looking for.
 *
 * No secret and no secret HASH is printed — see `listAll()`.
 *
 * @package Phlix\Hub\Console\Commands
 * @since   S286 (OAuth resource server, admin surface and prune timer)
 */
#[AsCommand(
    name: 'oauth:client:list',
    description: 'List every registered OAuth 2.0 client, including disabled and unusable rows',
)]
final class OAuthClientListCommand extends Command
{
    /**
     * @param callable(): OAuthClientRegistry $registryFactory Lazy registry.
     */
    public function __construct(private $registryFactory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input);

        try {
            $clients = ($this->registryFactory)()->listAll();
        } catch (Throwable $e) {
            $output->writeln('<error>Listing failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($clients === []) {
            $output->writeln('No OAuth clients are registered.');
            $output->writeln('Register one with: php bin/phlix oauth:client:register …');

            return Command::SUCCESS;
        }

        foreach ($clients as $client) {
            $status = $client['disabled']
                ? 'DISABLED'
                : ($client['usable'] ? 'active' : 'UNUSABLE (the token flow refuses this row)');

            $output->writeln('<info>' . $client['client_id'] . '</info>  [' . $status . ']');
            $output->writeln('  name:           ' . $client['name']);
            $output->writeln('  redirect_uris:  ' . implode("\n                  ", $client['redirect_uris']));
            $output->writeln('  allowed_scopes: ' . implode(' ', $client['allowed_scopes']));
            $output->writeln('  confidential:   ' . ($client['is_confidential'] ? 'yes' : 'no (PKCE only)'));
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
