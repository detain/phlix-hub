<?php

declare(strict_types=1);

namespace Phlix\Hub\Console\Commands;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Shared\Auth\JwtClaims;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `smoke:jwt` — smoke-test the JWT create/validate round-trip.
 *
 * Console wrapper around `scripts/smoke-jwt-roundtrip.php`. It mints an
 * access token with a throwaway 64-character test secret, validates it
 * back through the same {@see JwtHandler}, and asserts the decoded
 * {@see JwtClaims} match what was minted. The point is to prove the
 * cross-repo `JwtHandler` ↔ `Phlix\Shared\Auth\JwtClaims` wiring works —
 * not to exercise the production secret — so the command needs no config
 * and no database.
 *
 * @package Phlix\Hub\Console\Commands
 *
 * @since 0.6.0
 */
#[AsCommand(name: 'smoke:jwt', description: 'Smoke-test the JWT create/validate round-trip')]
final class SmokeJwtCommand extends Command
{
    /**
     * Mint → validate → assert the JWT round-trip.
     *
     * Returns {@see Command::SUCCESS} (0) when the decoded claims match
     * the minted ones and {@see Command::FAILURE} (1) on any mismatch or
     * when validation does not return a {@see JwtClaims}.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $handler = new JwtHandler(str_repeat('s', 64));
        $userId = '8a7f0e2c-1234-4abc-89ef-fedcba012345';
        $scope = ['library:read', 'playback:write'];
        $serverId = 'server-001';

        $token = $handler->createAccessToken($userId, $scope, $serverId);
        $claims = $handler->validateAccessToken($token);

        if (!$claims instanceof JwtClaims) {
            $output->writeln('<error>FAIL: validateAccessToken did not return JwtClaims</error>');

            return Command::FAILURE;
        }

        $expected = [
            'iss' => JwtClaims::ISS_PHLIX_HUB,
            'aud' => JwtClaims::AUD_HUB,
            'sub' => $userId,
            'type' => JwtClaims::TYPE_ACCESS,
            'scope' => $scope,
            'serverId' => $serverId,
        ];

        $actual = [
            'iss' => $claims->iss,
            'aud' => $claims->aud,
            'sub' => $claims->sub,
            'type' => $claims->type,
            'scope' => $claims->scope,
            'serverId' => $claims->serverId,
        ];

        foreach ($expected as $key => $value) {
            if ($actual[$key] !== $value) {
                $output->writeln(sprintf(
                    '<error>FAIL: %s mismatch: expected %s, got %s</error>',
                    $key,
                    (string) json_encode($value),
                    (string) json_encode($actual[$key]),
                ));

                return Command::FAILURE;
            }
        }

        $output->writeln('OK: JWT round-trip succeeded');
        $output->writeln('  Token (first 40 chars): ' . substr($token, 0, 40) . '...');
        $output->writeln('  Decoded claim class:    ' . $claims::class);
        $output->writeln('  iss=' . $claims->iss . ' aud=' . $claims->aud . ' sub=' . $claims->sub);
        $output->writeln(
            '  type=' . $claims->type
            . ' scope=' . implode(',', $claims->scope)
            . ' serverId=' . ($claims->serverId ?? 'null')
        );
        $output->writeln('  iat=' . $claims->iat . ' exp=' . $claims->exp);

        return Command::SUCCESS;
    }
}
