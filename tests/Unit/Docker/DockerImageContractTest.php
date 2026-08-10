<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Docker;

use PHPUnit\Framework\TestCase;

/**
 * S300 — the hub image's build/run contract, pinned where a `docker build`
 * cannot see it.
 *
 * ## Why this file exists
 *
 * The hub image was built, for its whole life, by a job in ANOTHER repository
 * (`phlix-server/.github/workflows/docker.yml`, "Build and Push phlix-hub",
 * `ref: master`). That job was green on every run — and the image had never
 * executed a single line of the application:
 *
 * ```
 * $ docker run -d --name x phlix-hub:master && docker logs x
 * sh: can't open '/docker-entrypoint.sh': No such file or directory
 * $ docker inspect -f '{{.State.ExitCode}}' x
 * 2
 * ```
 *
 * **`docker build` never executes CMD, ENTRYPOINT or HEALTHCHECK.** A build job
 * therefore cannot fail on any of it. Two layers now can:
 *
 *  * `scripts/docker-boot-smoke.sh` — BOOTS the built image and asserts 16
 *    things about the running container. It is the real gate and it is the only
 *    thing here that observes runtime behaviour.
 *  * this test — asserts the *wiring* that the boot gate depends on, in the
 *    unit suite, so a deletion is caught in seconds on every PR rather than
 *    only when someone reads a workflow file. Each assertion below corresponds
 *    to a single edit that would otherwise be silent:
 *
 * | mutation                                                        | here |
 * | --------------------------------------------------------------- | ---- |
 * | delete `COPY docker/docker-entrypoint.sh /docker-entrypoint.sh`   | RED  |
 * | point the CMD at a path nothing writes                            | RED  |
 * | resurrect `public/index.php` as the daemon (it does not exist)    | RED  |
 * | gate migrations on `PHLIX_DATABASE_*` again (no php reads it)     | RED  |
 * | drop the HEALTHCHECK, or push its start period past the observer  | RED  |
 * | re-add `--ignore-platform-reqs` to the image's composer install   | RED  |
 * | delete `.github/workflows/docker.yml` or its boot-gate job        | RED  |
 * | make the boot gate advisory (`continue-on-error`)                 | RED  |
 * | stop running the boot gate on pull requests                       | RED  |
 * | delete `scripts/docker-boot-smoke.sh`                             | RED  |
 * | comment any of the above out instead of deleting it               | RED  |
 *
 * The last row is why the workflow assertions run against text with
 * comment-only lines stripped: `# ./scripts/docker-boot-smoke.sh` must not
 * satisfy a check that the gate is invoked.
 *
 * ⚠ Scope, stated so it is not mistaken for more than it is. This is a
 * host-side text test. It cannot see a wrong binary name, a missing PHP
 * extension, a port nothing binds, or a container that exits in one second —
 * that is precisely the class of defect that survived here for years, and only
 * the boot gate covers it. Do not let a green run of THIS file stand in for a
 * green run of THAT one.
 *
 * @package Phlix\Hub\Tests\Unit\Docker
 */
final class DockerImageContractTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    private const DOCKERFILE = self::REPO_ROOT . '/Dockerfile';

    private const ENTRYPOINT = self::REPO_ROOT . '/docker/docker-entrypoint.sh';

    private const WORKFLOW = self::REPO_ROOT . '/.github/workflows/docker.yml';

    private const SMOKE_SCRIPT = self::REPO_ROOT . '/scripts/docker-boot-smoke.sh';

    /**
     * The HTTP port `config/server.php` defaults to and the Dockerfile must
     * expose. A literal, not a value read back out of the subject: a check
     * derived from the thing it checks self-adjusts and can never fail.
     */
    private const HTTP_PORT = 8800;

    /** Relay/SyncPlay worker ports (RelayWorker, ClientRelayWorker, SyncPlayRelayWorker). */
    private const RELAY_PORTS = [8802, 8803, 8804];

    /** Read a file, failing the test rather than returning '' when it is absent. */
    private function contents(string $path, string $why): string
    {
        self::assertFileExists($path, $why);

        return (string) file_get_contents($path);
    }

    /** The same text with comment-only lines removed. A commented-out rule is not a rule. */
    private function withoutCommentLines(string $text, string $marker = '#'): string
    {
        $kept = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\s*' . preg_quote($marker, '/') . '/', $line) === 1) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    // -----------------------------------------------------------------------
    // The Dockerfile <-> entrypoint pair
    // -----------------------------------------------------------------------

    public function testTheCmdNamesAPathTheDockerfileActuallyWrites(): void
    {
        $dockerfile = $this->withoutCommentLines(
            $this->contents(self::DOCKERFILE, 'the hub image needs a Dockerfile'),
        );

        self::assertMatchesRegularExpression(
            '/^CMD\s+\["sh",\s*"\/docker-entrypoint\.sh"\]/m',
            $dockerfile,
            'the image must start via /docker-entrypoint.sh',
        );

        self::assertMatchesRegularExpression(
            '#^COPY\s+docker/docker-entrypoint\.sh\s+/docker-entrypoint\.sh\s*$#m',
            $dockerfile,
            'The CMD names /docker-entrypoint.sh, so something must PUT a file there. '
            . '`COPY . /var/www/html/` lands it at /var/www/html/docker/docker-entrypoint.sh '
            . 'instead, and without this line every container exits 2 with '
            . '"sh: can\'t open \'/docker-entrypoint.sh\'" — measured at master @ 4e8828d. '
            . 'A docker build never executes CMD, so no build job can catch this.',
        );
    }

    public function testTheEntrypointStartsTheWorkermanDaemonAndNotTheAbsentFrontController(): void
    {
        $entrypoint = $this->withoutCommentLines(
            $this->contents(self::ENTRYPOINT, 'the image needs an entrypoint'),
        );

        self::assertMatchesRegularExpression(
            '#exec\s+php\s+"?\$\{APP_ROOT\}/start\.php"?\s+start#',
            $entrypoint,
            'the entrypoint must exec start.php — the Workerman daemon (Worker::runAll())',
        );

        self::assertStringNotContainsString(
            'public/index.php',
            $entrypoint,
            'public/index.php DOES NOT EXIST in this repository (public/ holds assets/ only). '
            . 'The entrypoint exec\'d it for years; nothing noticed because the entrypoint '
            . 'itself was unreachable.',
        );
    }

    public function testTheEntrypointReadsTheDatabaseVariablesThisRepositoryActuallyUses(): void
    {
        $entrypoint = $this->withoutCommentLines(
            $this->contents(self::ENTRYPOINT, 'the image needs an entrypoint'),
        );

        self::assertStringContainsString(
            'HUB_DB_HOST',
            $entrypoint,
            'config/database.php reads HUB_DB_HOST/PORT/USER/PASSWORD/NAME; the migration step '
            . 'must gate on the same name it will be run with.',
        );

        self::assertStringNotContainsString(
            'PHLIX_DATABASE_HOST',
            $entrypoint,
            'PHLIX_DATABASE_* is phlix-server\'s naming. NO php in this repository reads it, so '
            . 'gating the migration step on it silently skipped migrations on a correctly '
            . 'configured hub container.',
        );
    }

    public function testTheEntrypointPrintsAGreppableOutcomeForEveryMigrationPath(): void
    {
        $entrypoint = $this->contents(self::ENTRYPOINT, 'the image needs an entrypoint');

        foreach (
            [
                'PHLIX-HUB-MIGRATIONS-OK',
                'PHLIX-HUB-MIGRATION-FAILURE',
                'PHLIX-HUB-MIGRATIONS-NOT-RUN',
            ] as $banner
        ) {
            self::assertStringContainsString(
                $banner,
                $entrypoint,
                sprintf(
                    'the %s banner is what scripts/docker-boot-smoke.sh reads: /health is DB-free '
                    . 'by design, so without these the gate cannot tell a migrated schema from no '
                    . 'schema at all',
                    $banner,
                ),
            );
        }
    }

    // -----------------------------------------------------------------------
    // The image's own declarations
    // -----------------------------------------------------------------------

    public function testTheImageExposesThePortsTheDaemonBinds(): void
    {
        $dockerfile = $this->withoutCommentLines(
            $this->contents(self::DOCKERFILE, 'the hub image needs a Dockerfile'),
        );

        self::assertMatchesRegularExpression('/^EXPOSE\s+.*\b8800\b/m', $dockerfile, sprintf(
            'EXPOSE must name the HTTP port the daemon binds (%d). The previous `EXPOSE 80 443` '
            . 'described an nginx that is not in the image.',
            self::HTTP_PORT,
        ));

        foreach (self::RELAY_PORTS as $port) {
            self::assertMatchesRegularExpression(
                '/^EXPOSE\s+.*\b' . $port . '\b/m',
                $dockerfile,
                sprintf('EXPOSE must name relay port %d — the tunnel workers bind it', $port),
            );
        }
    }

    public function testTheImageDeclaresAHealthcheckThatCanActuallyGoBad(): void
    {
        $dockerfile = $this->withoutCommentLines(
            $this->contents(self::DOCKERFILE, 'the hub image needs a Dockerfile'),
        );

        self::assertMatchesRegularExpression(
            '/^HEALTHCHECK\s/m',
            $dockerfile,
            'Without a HEALTHCHECK a container in which the application never started reports '
            . '`Up` forever — which is exactly how the equivalent phlix-server outage stayed '
            . 'invisible for weeks.',
        );

        self::assertMatchesRegularExpression(
            '#curl\s+-fsS\s+http://127\.0\.0\.1:8800/health#',
            $dockerfile,
            'the healthcheck must hit /health on the daemon\'s own port',
        );

        $matched = preg_match('/--start-period=(\d+)s/', $dockerfile, $m);
        self::assertSame(1, $matched, 'the HEALTHCHECK must declare an explicit start period');
        self::assertLessThanOrEqual(
            120,
            (int) $m[1],
            'A start period that outlives the observer makes the health state decorative: '
            . 'failures inside it are not counted, so `unhealthy` is unreachable while a gate is '
            . 'watching. phlix-server shipped 180s and its boot gate could never see a bad one. '
            . 'scripts/docker-boot-smoke.sh asserts the same bound against the built image.',
        );
    }

    public function testTheImageDoesNotHideAMissingPhpExtension(): void
    {
        $dockerfile = $this->withoutCommentLines(
            $this->contents(self::DOCKERFILE, 'the hub image needs a Dockerfile'),
        );

        self::assertStringNotContainsString(
            '--ignore-platform-reqs',
            $dockerfile,
            'composer.json\'s only platform requirement today is php ^8.3, so the flag hides '
            . 'nothing — but it would hide the FIRST ext-* requirement anyone adds, exactly as it '
            . 'hid ext-ldap from every phlix-server image until S163. Without it a missing '
            . 'extension fails the BUILD instead of the running container.',
        );
    }

    public function testTheImageShipsOneServingModel(): void
    {
        $dockerfile = $this->withoutCommentLines(
            $this->contents(self::DOCKERFILE, 'the hub image needs a Dockerfile'),
        );

        foreach (['docker/supervisord.conf', 'docker/nginx.conf'] as $deadConfig) {
            self::assertStringNotContainsString(
                'COPY ' . $deadConfig,
                $dockerfile,
                sprintf(
                    'The shared base is php:8.3-cli-alpine and ships NEITHER php-fpm NOR nginx '
                    . '(phlix-server/docker/Dockerfile.base says so, and names this image as the '
                    . 'consequence). Installing %s puts a config for absent binaries into the '
                    . 'image and invites the two-serving-models confusion back.',
                    $deadConfig,
                ),
            );
        }
    }

    // -----------------------------------------------------------------------
    // The gate itself
    // -----------------------------------------------------------------------

    public function testTheBootGateScriptExists(): void
    {
        $smoke = $this->contents(
            self::SMOKE_SCRIPT,
            'scripts/docker-boot-smoke.sh IS the gate — a build job cannot replace it, because '
            . 'docker build never executes CMD, ENTRYPOINT or HEALTHCHECK',
        );

        self::assertStringContainsString(
            'EXPECTED_CHECKS',
            $smoke,
            'the gate must keep its check registry: a check that reaches no verdict has to be a '
            . 'failure, not a silent skip',
        );

        self::assertStringContainsString(
            'failure-exit-nonzero',
            $smoke,
            'the gate must keep its positive control — a deliberately misconfigured container that '
            . 'MUST die non-zero. Without it, nothing distinguishes "every assertion passed" from '
            . '"every assertion is incapable of failing".',
        );
    }

    public function testTheWorkflowBuildsAndBootsThisRepositorysOwnImage(): void
    {
        $workflow = $this->withoutCommentLines(
            $this->contents(
                self::WORKFLOW,
                'phlix-hub must build its OWN image. While the only build lived in '
                . 'phlix-server/.github/workflows/docker.yml at `ref: master`, a hub PR never '
                . 'built anything, the published image lagged the merge, and a hub defect '
                . 'reddened a phlix-server PR.',
            ),
        );

        self::assertMatchesRegularExpression(
            '/^  docker-boot-gate:\s*$/m',
            $workflow,
            'the workflow must define the `docker-boot-gate` job',
        );

        self::assertMatchesRegularExpression(
            '/^  docker-build:\s*$/m',
            $workflow,
            'the workflow must define the `docker-build` job',
        );

        self::assertMatchesRegularExpression(
            '#\./scripts/docker-boot-smoke\.sh#',
            $workflow,
            'the boot-gate job must actually RUN the smoke script',
        );

        self::assertMatchesRegularExpression(
            '/^  pull_request:\s*$/m',
            $workflow,
            'The gate must run on pull_request — that is the whole point. Building only on push '
            . 'reproduces the defect this step fixes: the image is validated after the merge that '
            . 'broke it.',
        );

        self::assertStringNotContainsString(
            'continue-on-error',
            $workflow,
            'An advisory gate is not a gate. This estate has no branch protection, so a job that '
            . 'cannot fail the run is indistinguishable from one that was never written.',
        );
    }

    public function testThePublishJobCannotShipAnImageThatWasNeverBooted(): void
    {
        $workflow = $this->withoutCommentLines(
            $this->contents(self::WORKFLOW, 'the docker workflow must exist'),
        );

        self::assertMatchesRegularExpression(
            '/needs:\s*\[base-digest,\s*docker-boot-gate\]/',
            $workflow,
            'The publish job must depend on the boot gate. Publishing an image that has never been '
            . 'started is exactly what the upstream job did for the life of this image.',
        );

        self::assertStringContainsString(
            "push: \${{ github.event_name != 'pull_request' }}",
            $workflow,
            'a pull request must not move a tag anyone deploys',
        );
    }
}
