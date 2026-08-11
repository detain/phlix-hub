<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\OAuth;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Console\Commands\OAuthClientDisableCommand;
use Phlix\Hub\Console\Commands\OAuthClientListCommand;
use Phlix\Hub\Console\Commands\OAuthClientRegisterCommand;
use Phlix\Hub\Http\Controllers\OAuthController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentScreen;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;
use Phlix\Hub\OAuth\Pkce;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function parse_url;
use function preg_match;
use function random_bytes;
use function str_contains;

use const PHP_URL_QUERY;

/**
 * S286 — the ADMIN SURFACE for S92's client registry, and the proof that what it
 * writes is the record the token flow reads.
 *
 * ## The gap this closes
 *
 * `OAuthClientRegistry::register()` and `disable()` shipped in S92 fully working
 * and fully tested, and **nothing called them**: no route, no command, no page.
 * Registering the Alexa skill meant writing PHP against the container or
 * hand-crafting an `INSERT` — and a hand-crafted `INSERT` is precisely what the
 * registry defends against, because it bypasses `OAuthClient::create()` and can
 * persist a client with an empty allow-list that every endpoint then silently
 * refuses.
 *
 * ## Why "the insert succeeded" would not be evidence
 *
 * 🔴 The acceptance criterion is deliberately stronger than "a row appeared".
 * A command that wrote a row the token endpoint cannot use — wrong column, wrong
 * delimiter, a secret stored in plaintext, a scope string in the wrong format —
 * would satisfy a row-count assertion completely. So every test here that claims
 * a registration worked drives the **whole production flow** against it:
 * consent screen → ticket → authorization code → token exchange, through the
 * real {@see OAuthController}, ending in a real access token. The registration
 * is proved by the token, not by the insert.
 *
 * The commands are exercised through Symfony's {@see CommandTester}, i.e. the
 * same `execute()` `bin/phlix` invokes, against real MySQL. What `bin/phlix`
 * adds — that these commands are actually REGISTERED on the CLI application — is
 * pinned separately by
 * {@see \Phlix\Hub\Tests\Unit\Console\CliCommandRegistrationTest}, because a
 * command that works but is not registered is the S41/S174 shape.
 *
 * @package Phlix\Hub\Tests\Integration\OAuth
 *
 * @group integration
 */
final class OAuthAdminSurfaceTest extends RealDatabaseTestCase
{
    private const string CLIENT_ID = 'alexa-skill';

    private const string REDIRECT = 'https://layla.amazon.com/api/skill/link/M2ABCDEFG';

    private const string REDIRECT_ALT = 'https://pitangui.amazon.com/api/skill/link/M2ABCDEFG';

    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private OAuthClientRegistry $registry;

    private OAuthTokenService $tokens;

    private OAuthController $authServer;

    private string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $loggerConfig = [
            'handlers'   => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => [],
        ];
        $log = new StructuredLogger('s286-admin', $loggerConfig);

        $this->registry = new OAuthClientRegistry($this->db, $log);
        $this->tokens   = new OAuthTokenService($this->db);

        $this->authServer = new OAuthController(
            $this->registry,
            new ConsentTicketService($this->db),
            new AuthorizationCodeService($this->db),
            $this->tokens,
            new AuditLogger(new StructuredLogger('s286-admin-audit', $loggerConfig)),
            $log,
        );

        $this->userId = Ids::uuidV4();
    }

    // =====================================================================
    // 1. The acceptance criterion
    // =====================================================================

    /**
     * 🔴 Registered through the admin surface, then USED by the token flow —
     * and the token that comes out is checked against the exact values the
     * command was given.
     *
     * Every one of the command's outputs is load-bearing in the flow that
     * follows: the `client_id` is what `/oauth/authorize` looks up, the redirect
     * URI is matched WHOLE at both the authorize and the token step, the scope
     * ceiling caps what the consent screen can offer, and the printed secret is
     * what the token endpoint checks against the stored hash. If the command had
     * written any of them differently from what it printed, this flow breaks.
     */
    public function testAClientRegisteredThroughTheCommandIsTheRecordTheTokenFlowReads(): void
    {
        $tester = $this->registerTester();

        $exit = $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT, self::REDIRECT_ALT],
            '--scope'        => [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            '--confidential' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $secret = self::secretFromDisplay($tester->getDisplay());

        // --- the registration is what the LOOKUP the endpoints use returns ---
        $client = $this->registry->find(self::CLIENT_ID);
        self::assertNotNull($client, 'the registered client is invisible to the production lookup');
        self::assertSame('Phlix for Alexa', $client->name);
        self::assertSame([self::REDIRECT, self::REDIRECT_ALT], $client->redirectUris);
        self::assertSame([OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ], $client->allowedScopes);
        self::assertTrue($client->requiresSecret());

        // --- and the WHOLE flow works against it, using the printed secret ---
        $token = $this->runFlow(
            self::CLIENT_ID,
            $secret,
            self::REDIRECT,
            OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
        );

        self::assertStringStartsWith(OAuthTokenService::ACCESS_TOKEN_PREFIX, $token['access_token']);
        self::assertSame(
            OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
            $token['scope'],
        );

        // The token really is bound to the registered client — the strongest
        // form of "the same record", since this is read back out of the token
        // store by the production validator rather than out of oauth_clients.
        $grant = $this->tokens->validateAccess($token['access_token']);
        self::assertNotNull($grant);
        self::assertSame(self::CLIENT_ID, $grant->clientId);
        self::assertSame($this->userId, $grant->userId);
    }

    /**
     * The SECOND registered redirect URI is equally real. A command that wrote
     * only the first (or that joined them into one string) would pass the test
     * above, because that one only ever uses `self::REDIRECT`.
     */
    public function testTheSecondRegisteredRedirectUriAlsoCompletesTheFlow(): void
    {
        $tester = $this->registerTester();
        $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT, self::REDIRECT_ALT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
            '--confidential' => true,
        ]);
        $secret = self::secretFromDisplay($tester->getDisplay());

        $token = $this->runFlow(self::CLIENT_ID, $secret, self::REDIRECT_ALT, OAuthScopes::PROFILE_READ);
        self::assertNotSame('', $token['access_token']);

        // CONTROL — an unregistered near-miss of the same URI is refused, so the
        // success above is exact matching rather than a permissive comparison.
        $refused = $this->authorize(self::CLIENT_ID, self::REDIRECT_ALT . '/', OAuthScopes::PROFILE_READ);
        self::assertSame(400, $refused->statusCode);
        self::assertArrayNotHasKey('Location', $refused->headers);
    }

    /**
     * A PUBLIC client (no `--confidential`) is registered without a secret and
     * completes the flow on PKCE alone — and the command prints no secret.
     */
    public function testAPublicClientIsRegisteredWithoutASecretAndStillCompletesTheFlow(): void
    {
        $tester = $this->registerTester();
        $exit   = $tester->execute([
            'client-id'      => 'public-client',
            'name'           => 'A public client',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringNotContainsString('client_secret:', $tester->getDisplay());

        $client = $this->registry->find('public-client');
        self::assertNotNull($client);
        self::assertFalse($client->requiresSecret());

        $token = $this->runFlow('public-client', null, self::REDIRECT, OAuthScopes::PROFILE_READ);
        self::assertNotSame('', $token['access_token']);
    }

    // =====================================================================
    // 2. The command refuses what the registry would refuse — before writing
    // =====================================================================

    /**
     * An unknown scope is refused LOUDLY rather than dropped.
     *
     * `OAuthScopes::parse()` silently discards anything it does not recognise,
     * which is right at an endpoint (fail closed) and wrong at a provisioning
     * command: a typo would produce a client whose ceiling is narrower than the
     * operator believes, and nothing would say so. The control is the same
     * command with the scope spelled correctly.
     */
    public function testAMistypedScopeIsRefusedAndWritesNothing(): void
    {
        $tester = $this->registerTester();

        $exit = $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ, 'mcp:library:reed'],
            '--confidential' => true,
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Unknown scope(s): mcp:library:reed', $tester->getDisplay());
        self::assertNull(
            $this->registry->find(self::CLIENT_ID),
            'a refused registration must write nothing at all',
        );

        // CONTROL — one character different, and it registers.
        $ok = $this->registerTester();
        self::assertSame(Command::SUCCESS, $ok->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            '--confidential' => true,
        ]), $ok->getDisplay());
        self::assertNotNull($this->registry->find(self::CLIENT_ID));
    }

    /**
     * A registration with no scope at all is refused. This is the empty
     * allow-list, at the provisioning end: the registry would throw, but the
     * command must not need the exception to get there — an operator who typed
     * no `--scope` gets a sentence about `--scope`.
     */
    public function testARegistrationWithNoScopeOrNoRedirectUriIsRefused(): void
    {
        $noScope = $this->registerTester();
        self::assertSame(Command::INVALID, $noScope->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
        ]));
        self::assertStringContainsString('At least one --scope is required', $noScope->getDisplay());

        $noRedirect = $this->registerTester();
        self::assertSame(Command::INVALID, $noRedirect->execute([
            'client-id' => self::CLIENT_ID,
            'name'      => 'Phlix for Alexa',
            '--scope'   => [OAuthScopes::PROFILE_READ],
        ]));
        self::assertStringContainsString('At least one --redirect-uri is required', $noRedirect->getDisplay());

        self::assertNull($this->registry->find(self::CLIENT_ID));
    }

    /**
     * A redirect URI containing the storage delimiter is refused by the
     * registry, and the command reports that rather than throwing out of the
     * CLI. Without the guard the value would be stored as TWO registered
     * redirect URIs, the second of which nobody asked for.
     */
    public function testARedirectUriContainingTheStorageDelimiterIsRefused(): void
    {
        $tester = $this->registerTester();

        $exit = $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT . "\nhttps://attacker.example/cb"],
            '--scope'        => [OAuthScopes::PROFILE_READ],
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('must not contain a newline', $tester->getDisplay());
        self::assertNull($this->registry->find(self::CLIENT_ID));
    }

    // =====================================================================
    // 3. disable — and the flow stops
    // =====================================================================

    /**
     * 🔴 Disable through the admin surface, and the SAME flow that worked a
     * moment ago is refused `invalid_client`.
     *
     * The success is taken first, with the same client and the same secret, so
     * the later refusal is attributable to the disable and not to the client
     * never having worked.
     */
    public function testDisablingThroughTheCommandStopsTheFlowThatJustSucceeded(): void
    {
        $tester = $this->registerTester();
        $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
            '--confidential' => true,
        ]);
        $secret = self::secretFromDisplay($tester->getDisplay());

        $token = $this->runFlow(self::CLIENT_ID, $secret, self::REDIRECT, OAuthScopes::PROFILE_READ);
        self::assertNotSame('', $token['access_token']);

        $disable = new CommandTester(
            new OAuthClientDisableCommand(
                fn(): OAuthClientRegistry => $this->registry,
                fn(): OAuthTokenService => $this->tokens,
            ),
        );
        self::assertSame(Command::SUCCESS, $disable->execute(['client-id' => self::CLIENT_ID]));
        self::assertStringContainsString('was resolvable before this call: yes', $disable->getDisplay());

        $refused = $this->authorize(self::CLIENT_ID, self::REDIRECT, OAuthScopes::PROFILE_READ);
        self::assertSame(400, $refused->statusCode, 'a disabled client must not reach a consent screen');
        // A terminal HTML page and NO `Location` — an unknown/disabled client is
        // one of the two failures that must never redirect, or the endpoint
        // becomes an open redirect. The absence of the consent form's ticket
        // field is what says "no consent screen was rendered".
        self::assertArrayNotHasKey('Location', $refused->headers);
        self::assertStringNotContainsString(ConsentScreen::FIELD_TICKET, $refused->body);

        // Not revoked by default: the already-issued token still validates.
        self::assertNotNull(
            $this->tokens->validateAccess($token['access_token']),
            'disable() must not silently revoke live tokens — that is a separate, explicit action',
        );
    }

    /**
     * `--revoke-tokens` is the explicit second action, and it is the one that
     * cuts the live token. Run beside the default above, the pair shows the
     * split is real rather than nominal.
     */
    public function testRevokeTokensCutsTheLiveTokenTheDefaultDisableLeavesAlone(): void
    {
        $tester = $this->registerTester();
        $tester->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
            '--confidential' => true,
        ]);
        $secret = self::secretFromDisplay($tester->getDisplay());
        $token  = $this->runFlow(self::CLIENT_ID, $secret, self::REDIRECT, OAuthScopes::PROFILE_READ);

        self::assertNotNull($this->tokens->validateAccess($token['access_token']));

        $disable = new CommandTester(
            new OAuthClientDisableCommand(
                fn(): OAuthClientRegistry => $this->registry,
                fn(): OAuthTokenService => $this->tokens,
            ),
        );
        self::assertSame(Command::SUCCESS, $disable->execute([
            'client-id'       => self::CLIENT_ID,
            '--revoke-tokens' => true,
        ]));
        self::assertStringContainsString('tokens revoked: 2', $disable->getDisplay());

        self::assertNull(
            $this->tokens->validateAccess($token['access_token']),
            '--revoke-tokens did not cut the live access token',
        );
    }

    /**
     * Re-registering the same `client_id` re-enables it and replaces the secret.
     * That is the documented rotation path, so it is asserted rather than left
     * to a reader of `ON DUPLICATE KEY UPDATE`.
     */
    public function testReRegisteringRotatesTheSecretAndReEnablesADisabledClient(): void
    {
        $first = $this->registerTester();
        $first->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
            '--confidential' => true,
        ]);
        $oldSecret = self::secretFromDisplay($first->getDisplay());

        $this->registry->disable(self::CLIENT_ID);
        self::assertNull($this->registry->find(self::CLIENT_ID));

        $second = $this->registerTester();
        self::assertSame(Command::SUCCESS, $second->execute([
            'client-id'      => self::CLIENT_ID,
            'name'           => 'Phlix for Alexa (rotated)',
            '--redirect-uri' => [self::REDIRECT],
            '--scope'        => [OAuthScopes::PROFILE_READ],
            '--confidential' => true,
        ]));
        $newSecret = self::secretFromDisplay($second->getDisplay());

        self::assertNotSame($oldSecret, $newSecret, 'a re-registration must mint a NEW secret');
        self::assertNotNull($this->registry->find(self::CLIENT_ID), 'the client was not re-enabled');

        // The new secret works...
        self::assertNotSame(
            '',
            $this->runFlow(self::CLIENT_ID, $newSecret, self::REDIRECT, OAuthScopes::PROFILE_READ)['access_token'],
        );

        // ...and the old one no longer does. Without this the rotation would be
        // "a new string was printed", not "the stored verifier changed".
        $code    = $this->obtainCode(self::CLIENT_ID, self::REDIRECT, OAuthScopes::PROFILE_READ);
        $refused = $this->exchange(self::CLIENT_ID, $oldSecret, $code, self::REDIRECT);
        self::assertSame(401, $refused->statusCode);
        self::assertStringContainsString(OAuthError::INVALID_CLIENT, $refused->body);
    }

    // =====================================================================
    // 4. list — including the rows the fail-closed lookup hides
    // =====================================================================

    /**
     * The listing shows an active client, a disabled one, and — the case that
     * matters — a row that exists, is not disabled, and that the token flow
     * nevertheless refuses.
     *
     * That last row is inserted directly, bypassing `register()`, because it
     * models the thing this command exists for: an operator who provisioned by
     * hand and needs to know why nothing works. A listing built on `find()`
     * would show it as simply absent, which reads as "my registration failed"
     * rather than "the row is unusable".
     */
    public function testTheListingDistinguishesActiveDisabledAndUnusableRows(): void
    {
        $this->registry->register('active-client', 'Active', [self::REDIRECT], [OAuthScopes::PROFILE_READ]);
        $this->registry->register('disabled-client', 'Disabled', [self::REDIRECT], [OAuthScopes::PROFILE_READ]);
        $this->registry->disable('disabled-client');

        // A half-provisioned row: no redirect URI at all, so OAuthClient::create()
        // refuses it and find() answers null even though disabled_at IS NULL.
        $this->db->query(
            'INSERT INTO oauth_clients (id, client_id, name, redirect_uris, allowed_scopes, is_confidential)'
            . ' VALUES (:id, :client_id, :name, :uris, :scopes, 0)',
            [
                'id'        => Ids::uuidV4(),
                'client_id' => 'broken-client',
                'name'      => 'Broken',
                'uris'      => '',
                'scopes'    => OAuthScopes::PROFILE_READ,
            ],
        );

        $tester = new CommandTester(new OAuthClientListCommand(fn(): OAuthClientRegistry => $this->registry));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('active-client', $display);
        self::assertStringContainsString('[active]', $display);
        self::assertStringContainsString('disabled-client', $display);
        self::assertStringContainsString('[DISABLED]', $display);
        self::assertStringContainsString('broken-client', $display);
        self::assertStringContainsString('UNUSABLE', $display);

        // The production lookup really does refuse the row the listing flagged —
        // otherwise "UNUSABLE" would be a label this test taught itself.
        self::assertNull($this->registry->find('broken-client'));
        self::assertNotNull($this->registry->find('active-client'));
    }

    /**
     * No secret and no secret HASH may appear in the listing. A hash is still a
     * credential verifier and an operator listing has no use for one.
     */
    public function testTheListingPrintsNoSecretMaterial(): void
    {
        $secret = 'a-very-recognisable-secret-value';
        $this->registry->register(
            self::CLIENT_ID,
            'Phlix for Alexa',
            [self::REDIRECT],
            [OAuthScopes::PROFILE_READ],
            $secret,
        );

        $tester = new CommandTester(new OAuthClientListCommand(fn(): OAuthClientRegistry => $this->registry));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString(self::CLIENT_ID, $display, 'anti-vacuity: the client must be listed');
        self::assertStringNotContainsString($secret, $display);
        self::assertStringNotContainsString(hash('sha256', $secret), $display);
    }

    /**
     * An empty registry says so rather than printing nothing, so an operator can
     * tell "no clients" from "the command did not run".
     */
    public function testAnEmptyRegistryReportsItself(): void
    {
        $tester = new CommandTester(new OAuthClientListCommand(fn(): OAuthClientRegistry => $this->registry));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No OAuth clients are registered', $tester->getDisplay());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function registerTester(): CommandTester
    {
        return new CommandTester(
            new OAuthClientRegisterCommand(fn(): OAuthClientRegistry => $this->registry),
        );
    }

    /**
     * Pull the one-time secret out of what the command printed, rather than out
     * of the database — the CLI display is the only channel an operator has, so
     * reading it anywhere else would test a path nobody can use.
     */
    private static function secretFromDisplay(string $display): string
    {
        self::assertSame(
            1,
            preg_match('/client_secret:\s+([0-9a-f]{64})/', $display, $m),
            'the command printed no client secret: ' . $display,
        );

        return $m[1];
    }

    private function authorize(string $clientId, string $redirectUri, string $scope): Response
    {
        $request         = new Request();
        $request->method = 'GET';
        $request->path   = '/oauth/authorize';
        $request->userId = $this->userId;
        $request->query  = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => $scope,
            'code_challenge'        => Pkce::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state'                 => 'state-' . bin2hex(random_bytes(4)),
        ];

        return $this->authServer->authorize($request);
    }

    private function obtainCode(string $clientId, string $redirectUri, string $scope): string
    {
        $screen = $this->authorize($clientId, $redirectUri, $scope);
        self::assertSame(200, $screen->statusCode, 'the consent screen did not render: ' . $screen->body);
        self::assertSame(
            1,
            preg_match('/name="' . ConsentScreen::FIELD_TICKET . '" value="([a-f0-9]{64})"/', $screen->body, $m),
        );

        $consent         = new Request();
        $consent->method = 'POST';
        $consent->path   = '/oauth/authorize';
        $consent->userId = $this->userId;
        $consent->body   = [
            ConsentScreen::FIELD_TICKET   => $m[1],
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ];

        $redirect = $this->authServer->consent($consent);
        self::assertSame(302, $redirect->statusCode, $redirect->body);

        $params = [];
        $query  = parse_url($redirect->headers['Location'] ?? '', PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
        }
        self::assertArrayHasKey('code', $params);
        self::assertIsString($params['code']);

        return $params['code'];
    }

    private function exchange(string $clientId, ?string $secret, string $code, string $redirectUri): Response
    {
        $request         = new Request();
        $request->method = 'POST';
        $request->path   = '/oauth/token';
        $request->body   = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => self::VERIFIER,
        ];
        if ($secret !== null) {
            $request->body['client_secret'] = $secret;
        }

        return $this->authServer->token($request);
    }

    /**
     * The full production flow, end to end.
     *
     * @return array{access_token: string, scope: string}
     */
    private function runFlow(string $clientId, ?string $secret, string $redirectUri, string $scope): array
    {
        $code   = $this->obtainCode($clientId, $redirectUri, $scope);
        $issued = $this->exchange($clientId, $secret, $code, $redirectUri);

        self::assertSame(200, $issued->statusCode, 'the code exchange failed: ' . $issued->body);

        /** @var mixed $payload */
        $payload = json_decode($issued->body, true);
        self::assertIsArray($payload);
        self::assertIsString($payload['access_token'] ?? null);
        self::assertIsString($payload['scope'] ?? null);

        return [
            'access_token' => (string) $payload['access_token'],
            'scope'        => (string) $payload['scope'],
        ];
    }
}
