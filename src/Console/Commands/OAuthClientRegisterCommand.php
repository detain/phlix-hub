<?php

/**
 * Phlix hub component: Commands.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands;

use InvalidArgumentException;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthScopes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_diff;
use function array_values;
use function bin2hex;
use function implode;
use function is_array;
use function is_string;
use function random_bytes;
use function sprintf;
use function trim;

/**
 * `oauth:client:register` — the ADMIN SURFACE for S92's client registry (S286).
 *
 * ## Why this exists
 *
 * S92 shipped {@see OAuthClientRegistry::register()} fully working and fully
 * tested, and **nothing called it**: no route, no command, no page. Registering
 * the Alexa skill therefore meant an operator writing PHP against the container
 * or hand-crafting an `INSERT` — and a hand-crafted `INSERT` is precisely the
 * failure the registry defends against, because it bypasses
 * {@see \Phlix\Hub\OAuth\OAuthClient::create()} and can persist a client with an
 * empty allow-list that the lookup will then silently refuse forever.
 *
 * ## Why a CLI and not an HTTP admin route
 *
 * A considered choice, not the easy one:
 *
 *  - **A client secret is returned exactly once.** The registry stores only its
 *    SHA-256 hash, so the plaintext has to be shown at the moment of creation
 *    and never again. On a CLI that value lands in an operator's terminal; over
 *    HTTP it lands in a response body, an admin SPA's memory, and whatever sits
 *    in between.
 *  - **Registering an OAuth client is the act of deciding who may ask hub users
 *    for access.** That is a deployment-time decision made by whoever runs the
 *    hub, and it is made a handful of times in a hub's life. Putting it behind a
 *    network-reachable route adds a permanent attack surface — one that a
 *    compromised admin session could use to register an attacker's redirect URI
 *    — in exchange for convenience that nobody needs at that frequency.
 *  - It needs no new route, so it does not perturb the `openapi.yaml` ↔ router
 *    bijection, and there is nothing here for an anonymous caller to reach.
 *
 * An admin SPA page is a reasonable later addition; it is deliberately not part
 * of this step, and {@see OAuthClientListCommand} covers the "what is
 * registered?" half in the meantime.
 *
 * ## Usage
 *
 * ```
 * php bin/phlix oauth:client:register alexa-skill "Phlix for Alexa" \
 *     --redirect-uri=https://layla.amazon.com/api/skill/link/M2ABCDEFG \
 *     --redirect-uri=https://pitangui.amazon.com/api/skill/link/M2ABCDEFG \
 *     --scope=phlix:profile:read --scope=mcp:library:read \
 *     --confidential
 * ```
 *
 * Re-running with the same `client_id` UPDATES the row (the registry's
 * `ON DUPLICATE KEY UPDATE`) and clears `disabled_at`, which is what makes this
 * usable for rotating a secret. That is stated in the output rather than left
 * for an operator to discover.
 *
 * @package Phlix\Hub\Console\Commands
 * @since   S286 (OAuth resource server, admin surface and prune timer)
 */
#[AsCommand(
    name: 'oauth:client:register',
    description: 'Register (or re-provision) an OAuth 2.0 client in the hub client registry',
)]
final class OAuthClientRegisterCommand extends Command
{
    /**
     * Bytes of entropy in a generated client secret. 32 bytes → 64 hex chars.
     */
    private const int SECRET_BYTES = 32;

    /**
     * @param callable(): OAuthClientRegistry $registryFactory Resolved LAZILY, so
     *        `php bin/phlix list` opens no database connection. Mirrors
     *        {@see MigrateCommand}'s runner factory.
     */
    public function __construct(private $registryFactory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client-id', InputArgument::REQUIRED, 'Public client_id the client presents')
            ->addArgument('name', InputArgument::REQUIRED, 'Human label shown on the consent screen')
            ->addOption(
                'redirect-uri',
                'r',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'An EXACT redirect URI. Repeat for more than one. At least one is required.',
            )
            ->addOption(
                'scope',
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A scope this client may request. Repeat for more than one. At least one is required.',
            )
            ->addOption(
                'confidential',
                'c',
                InputOption::VALUE_NONE,
                'Issue a client secret (a confidential client). Omit for a public, PKCE-only client.',
            )
            ->addOption(
                'secret',
                null,
                InputOption::VALUE_REQUIRED,
                'Use THIS secret instead of generating one. Implies --confidential. '
                . 'Only for a client whose secret is dictated by the other side.',
            );
    }

    /**
     * Validate, register, and print the credentials exactly once.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientId = trim(self::stringArgument($input, 'client-id'));
        $name     = trim(self::stringArgument($input, 'name'));

        if ($clientId === '' || $name === '') {
            $output->writeln('<error>client-id and name must both be non-empty.</error>');

            return Command::INVALID;
        }

        $redirectUris = self::listOption($input, 'redirect-uri');
        if ($redirectUris === []) {
            $output->writeln('<error>At least one --redirect-uri is required.</error>');

            return Command::INVALID;
        }

        $requestedScopes = self::listOption($input, 'scope');
        if ($requestedScopes === []) {
            $output->writeln('<error>At least one --scope is required.</error>');

            return Command::INVALID;
        }

        // Refuse an UNKNOWN scope loudly here rather than letting
        // OAuthScopes::parse() drop it silently. A typo that were merely dropped
        // would produce a client whose ceiling is narrower than the operator
        // believes — or, if every scope were a typo, an empty ceiling that the
        // registry then refuses with a message about the allow-list rather than
        // about the typo.
        $scopes  = OAuthScopes::parse(implode(' ', $requestedScopes));
        $unknown = array_values(array_diff($requestedScopes, $scopes));
        if ($unknown !== []) {
            $output->writeln(sprintf(
                '<error>Unknown scope(s): %s</error>',
                implode(', ', $unknown),
            ));
            $output->writeln('  Known scopes: ' . implode(', ', OAuthScopes::all()));

            return Command::INVALID;
        }

        $secret = self::resolveSecret($input);

        try {
            $registry = ($this->registryFactory)();
            $client   = $registry->register($clientId, $name, $redirectUris, $scopes, $secret);
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>Refused: ' . $e->getMessage() . '</error>');

            return Command::INVALID;
        } catch (Throwable $e) {
            $output->writeln('<error>Registration failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Registered OAuth client</info>');
        $output->writeln('  client_id:       ' . $client->clientId);
        $output->writeln('  name:            ' . $client->name);
        $output->writeln('  redirect_uris:   ' . implode("\n                   ", $client->redirectUris));
        $output->writeln('  allowed_scopes:  ' . implode(' ', $client->allowedScopes));
        $output->writeln('  confidential:    ' . ($client->requiresSecret() ? 'yes' : 'no (PKCE only)'));

        if ($secret !== null) {
            $output->writeln('');
            $output->writeln('  client_secret:   ' . $secret);
            $output->writeln(
                '<comment>  Only the SHA-256 hash is stored. This is the ONE time the secret is '
                . 'shown — copy it now.</comment>',
            );
        }

        $output->writeln('');
        $output->writeln(
            '<comment>Re-running this command for the same client_id replaces the row and '
            . 're-enables a disabled client.</comment>',
        );

        return Command::SUCCESS;
    }

    /**
     * Decide the plaintext secret, or null for a public client.
     */
    private static function resolveSecret(InputInterface $input): ?string
    {
        /** @var mixed $explicit */
        $explicit = $input->getOption('secret');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return $input->getOption('confidential') === true
            ? bin2hex(random_bytes(self::SECRET_BYTES))
            : null;
    }

    private static function stringArgument(InputInterface $input, string $name): string
    {
        /** @var mixed $value */
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function listOption(InputInterface $input, string $name): array
    {
        /** @var mixed $value */
        $value = $input->getOption($name);
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
