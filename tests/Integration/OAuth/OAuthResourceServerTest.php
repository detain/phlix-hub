<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\OAuth;

use Phlix\Hub\Application;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Container\ContainerFactory;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Http\Controllers\OAuthController;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Middleware\OAuthResourceMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\RequestContext;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentScreen;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthGrant;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;
use Phlix\Hub\OAuth\Pkce;
use Phlix\Hub\Tests\Support\Container\FixedConnectionProvider;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

use function bin2hex;
use function glob;
use function is_array;
use function is_string;
use function json_decode;
use function mkdir;
use function parse_str;
use function parse_url;
use function password_hash;
use function preg_match;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const PASSWORD_DEFAULT;
use const PHP_URL_QUERY;

/**
 * S286 — an OAuth access token now REACHES something, and the refusals are
 * attributable.
 *
 * ## The gap this closes
 *
 * S92 shipped a complete Authorization Server whose tokens authorised nothing:
 * `validateAccess()` existed and was tested, and no middleware anywhere called
 * it. "Alexa can now read libraries" was never true. This suite is the evidence
 * for the narrower thing that IS now true: a token carrying
 * `phlix:profile:read` can read the linked account's identity at
 * `GET /oauth/userinfo`, and nothing else.
 *
 * ## Why every assertion here goes through the ROUTER
 *
 * Calling the controller directly would prove the controller works and say
 * nothing about whether the route exists or which gate fronts it — the S41/S174
 * defect. So the table is built by the REAL
 * `Application::registerOAuthRoutes()` against the REAL container (only the
 * `Connection` is substituted, see {@see FixedConnectionProvider}), and every
 * case below is a `Router::dispatch()`. A route that lost its middleware, or a
 * middleware bound with the wrong scope, changes an outcome here.
 *
 * ## Every refusal sits beside a 200 that differs in ONE input
 *
 * 🔴 A refusal on its own cannot distinguish "the scope check worked" from
 * "something else failed" — both are non-200. Each test therefore asserts a
 * refusal AND the succeeding control next to it, varying exactly one thing:
 *
 *  - no token vs. a good token;
 *  - a good token vs. the SAME token after revocation;
 *  - a good token vs. its own refresh-token sibling;
 *  - a token with the scope vs. a token from a client whose ceiling excludes it
 *    — and that second token is then proved GOOD by a middleware requiring the
 *    scope it does hold, so its 403 is provably about scope and not about the
 *    token being broken;
 *  - a hub SESSION JWT (which `AuthMiddleware` accepts everywhere else) vs. an
 *    OAuth access token, on the same path.
 *
 * ⚠ PHPUnit never enters a Swoole coroutine. Nothing here is evidence about
 * coroutine-scheduled behaviour; what it proves is the SQL semantics (executed
 * by real MySQL), the middleware's decision tree and the composed route table.
 *
 * @package Phlix\Hub\Tests\Integration\OAuth
 *
 * @group integration
 */
final class OAuthResourceServerTest extends RealDatabaseTestCase
{
    private const string USERINFO_PATH = '/oauth/userinfo';

    private const string REDIRECT = 'https://layla.amazon.com/api/skill/link/M2ABCDEFG';

    /** The client whose ceiling INCLUDES the identity scope. */
    private const string CLIENT_ID = 'alexa-skill';

    private const string SECRET = 'amazon-supplied-client-secret';

    /**
     * A second client whose ceiling deliberately EXCLUDES `phlix:profile:read`.
     * It exists so the 403 case can use a token that is otherwise perfect.
     */
    private const string LIBRARY_CLIENT_ID = 'library-only-client';

    private const string LIBRARY_SECRET = 'library-only-client-secret';

    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    /** A >=32-byte HS256 secret so the container's real JwtHandler accepts it. */
    private const string JWT_SECRET = 'S286-oauth-resource-server-secret-0123456789';

    private string $tmpDir = '';

    private ContainerInterface $container;

    private Router $router;

    private OAuthController $authServer;

    private OAuthTokenService $tokens;

    private string $userId = '';

    private string $displayName = 'Ada Lovelace';

    protected function setUp(): void
    {
        parent::setUp();

        AuthMiddleware::resetCache();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-s286-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);

        // php://memory rather than the repo's .logs/: several bindings call
        // LoggerFactory::get() directly and PHPUnit runs with
        // beStrictAboutOutputDuringTests.
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        file_put_contents(
            $this->tmpDir . '/auth.php',
            "<?php\n\nreturn ['secret' => '" . self::JWT_SECRET . "'];\n",
        );

        $this->container = ContainerFactory::create(
            [
                'auth_config_path'   => $this->tmpDir . '/auth.php',
                'logger_config_path' => $this->tmpDir . '/logger.php',
                'public_root'        => dirname(__DIR__, 3) . '/public',
            ],
            [...ContainerFactory::defaultProviders(), new FixedConnectionProvider($this->db)],
        );

        $this->router     = $this->oauthRouter();
        $this->tokens     = new OAuthTokenService($this->db);
        $this->authServer = $this->authorizationServer();

        $this->userId = $this->insertUser('s286-user', $this->displayName);

        $registry = new OAuthClientRegistry($this->db);
        $registry->register(
            self::CLIENT_ID,
            'Phlix for Alexa',
            [self::REDIRECT],
            [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            self::SECRET,
        );
        $registry->register(
            self::LIBRARY_CLIENT_ID,
            'Library Only',
            [self::REDIRECT],
            [McpScopes::LIBRARY_READ],
            self::LIBRARY_SECRET,
        );
    }

    protected function tearDown(): void
    {
        AuthMiddleware::resetCache();
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // =====================================================================
    // 1. The 200 / 401 control pair — both branches in ONE test
    // =====================================================================

    /**
     * 🔴 The acceptance criterion, in one method so it is unambiguous which
     * branch fired: the SAME path, the SAME router, differing only in whether a
     * valid access token is presented.
     *
     * The 401 is asserted with its `WWW-Authenticate` challenge and its
     * `invalid_token` code, not merely by status. A bare "401" is also what a
     * 404-into-a-catch-all, a broken binding or an unrelated auth middleware
     * would produce.
     */
    public function testUserInfoAnswers200WithAValidTokenAnd401Without(): void
    {
        $grant = $this->issueTokensFor(
            self::CLIENT_ID,
            self::SECRET,
            OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
        );

        // --- the WITHOUT half -------------------------------------------
        $anonymous = $this->dispatch(null);

        self::assertSame(401, $anonymous->statusCode, 'an uncredentialed caller must be refused');
        self::assertSame(
            OAuthError::INVALID_REQUEST,
            self::json($anonymous)['error'] ?? null,
            'the refusal must come from the OAuth resource gate, not from some other 401',
        );
        // RFC 6750 §3: a request that carried NO credential gets a BARE
        // challenge — no `error=`, because nothing was tried and rejected.
        self::assertSame(
            'Bearer realm="' . OAuthResourceMiddleware::REALM . '"',
            $anonymous->headers['WWW-Authenticate'] ?? null,
        );

        // --- the WITH half ----------------------------------------------
        $authorised = $this->dispatch($grant['access_token']);

        self::assertSame(
            200,
            $authorised->statusCode,
            'a valid access token carrying phlix:profile:read must reach the resource; got '
            . $authorised->statusCode . ' with body ' . $authorised->body,
        );

        $payload = self::json($authorised);
        self::assertSame($this->userId, $payload['sub'] ?? null);
        self::assertSame($this->displayName, $payload['name'] ?? null);
        self::assertSame(
            OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
            $payload['scope'] ?? null,
            'the scope must be read from the TOKEN, in OAuthScopes::all() order',
        );

        // The 200 must NOT carry a challenge header — otherwise "the header is
        // present" below would be satisfied by every response.
        self::assertArrayNotHasKey('WWW-Authenticate', $authorised->headers);
    }

    /**
     * 🔴 Written because a mutation survived.
     *
     * Deleting the middleware's "no bearer token" guard changed no outcome: an
     * empty token fell through to `OAuthTokenService::validateAccess()`, which
     * has its own `$token === ''` guard and answers null, so the request was
     * still refused 401 — by a different line, one layer down. The refusal was
     * real; the ATTRIBUTION was wrong. That is the S92 M5/M8 shape, where a
     * MySQL affected-row detail refused a replay and the tests read it as the
     * `consumed_at` predicate doing it.
     *
     * The two 401s now differ, per RFC 6750 §3 — "no credential" gets
     * `invalid_request` and a challenge naming NO error code; "bad credential"
     * gets `invalid_token` and a challenge naming it. Asserting BOTH in one
     * method is what makes each branch attributable: with the guard deleted the
     * absent-token case produces the presented-token response, and this reds.
     */
    public function testTheAbsentCredentialRefusalIsDistinguishableFromTheRejectedOne(): void
    {
        $absent   = $this->dispatch(null);
        $rejected = $this->dispatch('phlix-oat-' . bin2hex(random_bytes(32)));

        // Same status — which is exactly why the status alone attributes nothing.
        self::assertSame(401, $absent->statusCode);
        self::assertSame(401, $rejected->statusCode);

        self::assertSame(OAuthError::INVALID_REQUEST, self::json($absent)['error'] ?? null);
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($rejected)['error'] ?? null);

        $absentChallenge   = $absent->headers['WWW-Authenticate'] ?? '';
        $rejectedChallenge = $rejected->headers['WWW-Authenticate'] ?? '';

        self::assertSame('Bearer realm="' . OAuthResourceMiddleware::REALM . '"', $absentChallenge);
        self::assertStringContainsString('error="' . OAuthError::INVALID_TOKEN . '"', $rejectedChallenge);

        // Anti-vacuity: the two responses must not be the same bytes. If they
        // ever converge, every assertion above still passes individually while
        // the branches stop being distinguishable — which is the whole defect.
        self::assertNotSame($absentChallenge, $rejectedChallenge);
        self::assertNotSame($absent->body, $rejected->body);
    }

    /**
     * The resource must publish LESS than the user's own `/api/v1/me` does. A
     * third party holding `phlix:profile:read` learns who the user is; it must
     * not learn their email, their password hash or whether they are an admin.
     *
     * Asserted as an exact key set rather than a deny-list: a deny-list has to
     * be updated for every column a future migration adds to `users`, and the
     * one nobody updates is the one that leaks.
     */
    public function testTheIdentityResponseCarriesExactlyThreeFieldsAndNoUserRowColumns(): void
    {
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);

        $payload = self::json($this->dispatch($grant['access_token']));

        self::assertSame(['sub', 'name', 'scope'], array_keys($payload));
    }

    // =====================================================================
    // 2. 403 — attributable to the SCOPE and to nothing else
    // =====================================================================

    /**
     * 🔴 The sharpest case in this file.
     *
     * A token from `library-only-client` is refused 403 `insufficient_scope` at
     * `/oauth/userinfo`, and the SAME token is then accepted (a real 200 through
     * a real dispatch) by a resource gate that requires the scope it actually
     * holds. Without that second half, "403" would be equally consistent with
     * the token being expired, revoked, wrongly stored, or belonging to a
     * deleted user — the refusal alone attributes nothing.
     */
    public function testATokenWithoutTheRequiredScopeIs403WhileTheSameTokenIsGoodElsewhere(): void
    {
        $grant = $this->issueTokensFor(
            self::LIBRARY_CLIENT_ID,
            self::LIBRARY_SECRET,
            McpScopes::LIBRARY_READ,
        );

        // --- refused where phlix:profile:read is required -----------------
        $refused = $this->dispatch($grant['access_token']);

        self::assertSame(
            403,
            $refused->statusCode,
            'a valid token lacking the required scope must be 403, never 401 — the distinction is '
            . 'what makes "the scope gate refused this" legible',
        );
        self::assertSame(OAuthError::INSUFFICIENT_SCOPE, self::json($refused)['error'] ?? null);
        self::assertStringContainsString(
            'scope="' . OAuthScopes::PROFILE_READ . '"',
            $refused->headers['WWW-Authenticate'] ?? '',
            'the RFC 6750 challenge must name the scope the client is missing',
        );

        // --- CONTROL: the very same token, at a gate that wants what it has --
        $permissive = new Router();
        $permissive->group('/oauth', static function (Router $r): void {
            $r->get('/userinfo', static fn(): Response => (new Response())->json(['reached' => true]));
        }, [
            new OAuthResourceMiddleware(
                $this->tokens,
                $this->realUserRepository(),
                [McpScopes::LIBRARY_READ],
            ),
        ]);

        $accepted = $permissive->dispatch($this->request($grant['access_token']));

        self::assertSame(
            200,
            $accepted->statusCode,
            'the token itself is fine — so the 403 above was about the SCOPE and nothing else. '
            . 'Got ' . $accepted->statusCode . ': ' . $accepted->body,
        );
        self::assertSame(true, self::json($accepted)['reached'] ?? null);
    }

    // =====================================================================
    // 3. Everything that must NOT authenticate here
    // =====================================================================

    /**
     * A hub SESSION JWT — the credential `AuthMiddleware` accepts on every other
     * authenticated route in the hub, minted here by the CONTAINER's own
     * `JwtHandler` for a user that really exists — must not open this resource.
     *
     * This is the test that would red if somebody "simplified" the route by
     * re-gating it with `AuthMiddleware`: a session JWT carries no scopes at
     * all, so serving it here would mean an unscoped credential on a scope-gated
     * surface.
     */
    public function testAHubSessionJwtDoesNotAuthenticateAtTheOAuthResource(): void
    {
        $jwt = $this->container->get(JwtHandler::class);
        self::assertInstanceOf(JwtHandler::class, $jwt);
        $sessionToken = $jwt->createAccessToken($this->userId);

        $refused = $this->dispatch($sessionToken);

        self::assertSame(401, $refused->statusCode);
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);

        // CONTROL — the same user, same path, same router: an OAuth token works.
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);
        self::assertSame(200, $this->dispatch($grant['access_token'])->statusCode);
    }

    /**
     * The REFRESH half of the very same issuance must not act as a bearer
     * credential. `validateAccess()` filters on `kind = 'access'`; the two
     * tokens are otherwise identical rows for the same client, user and scopes,
     * so this isolates the kind filter and nothing else.
     */
    public function testTheRefreshTokenOfTheSameIssuanceIsNotAcceptedAsABearerCredential(): void
    {
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);

        $refused = $this->dispatch($grant['refresh_token']);
        self::assertSame(401, $refused->statusCode);
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);

        self::assertSame(200, $this->dispatch($grant['access_token'])->statusCode);
    }

    /**
     * Revocation is honoured on the resource, not merely at issuance.
     *
     * The 200 is taken FIRST with the same token, so the later 401 is provably
     * caused by the revocation and not by the token never having worked.
     */
    public function testRevokingTheClientCutsOffATokenThatWasWorkingAMomentBefore(): void
    {
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);

        self::assertSame(200, $this->dispatch($grant['access_token'])->statusCode);

        self::assertSame(2, $this->tokens->revokeForClient(self::CLIENT_ID), 'access + refresh');

        $refused = $this->dispatch($grant['access_token']);
        self::assertSame(401, $refused->statusCode);
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);
    }

    /**
     * An EXPIRED token is refused — and the row is aged in the database rather
     * than the test waiting, so what refuses it is the `expires_at > NOW()`
     * predicate rather than a clock the test happens to control.
     */
    public function testAnExpiredAccessTokenIsRefusedWhileAFreshOneIsAccepted(): void
    {
        $stale = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);
        self::assertSame(200, $this->dispatch($stale['access_token'])->statusCode);

        $this->db->query(
            'UPDATE oauth_tokens SET expires_at = NOW() - INTERVAL 5 MINUTE WHERE token_hash = :hash',
            ['hash' => hash('sha256', $stale['access_token'])],
        );

        $refused = $this->dispatch($stale['access_token']);
        self::assertSame(401, $refused->statusCode);
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);

        // CONTROL — a token issued moments later, from the same client and user.
        $fresh = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);
        self::assertSame(200, $this->dispatch($fresh['access_token'])->statusCode);
    }

    /**
     * A cryptographically valid token whose USER has since been deleted is
     * refused 401 rather than serving the identity of a row that is gone.
     *
     * Same reasoning as {@see \Phlix\Hub\Alexa\AlexaAccountLink}: a token
     * outlives the account, and Amazon caches it for the life of the link.
     */
    public function testATokenWhoseUserWasDeletedIsRefused(): void
    {
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);
        self::assertSame(200, $this->dispatch($grant['access_token'])->statusCode);

        $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);

        $refused = $this->dispatch($grant['access_token']);
        self::assertSame(
            401,
            $refused->statusCode,
            'a token whose user no longer exists must be 401 (it identifies nobody), not 403 and '
            . 'certainly not 200',
        );
        self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);
    }

    /**
     * A garbage bearer string is refused without a database row ever matching —
     * the case that would 500 if the middleware assumed a hit.
     */
    public function testAnUnknownBearerStringIsRefusedCleanly(): void
    {
        foreach (
            [
            'phlix-oat-' . bin2hex(random_bytes(32)),
            'not-even-close',
            'Bearer',
            '  ',
            ] as $garbage
        ) {
            $refused = $this->dispatch($garbage);
            self::assertSame(401, $refused->statusCode, 'refused: ' . $garbage);
            self::assertSame(OAuthError::INVALID_TOKEN, self::json($refused)['error'] ?? null);
        }
    }

    // =====================================================================
    // 3b. The MIDDLEWARE's own contract, measured at the handler
    // =====================================================================

    /**
     * 🔴 Written because a mutation survived.
     *
     * Deleting the middleware's `userExists()` probe changed no outcome at
     * `/oauth/userinfo`: that controller re-reads the user row and answers 401
     * itself when it is gone, so the route-level test could not tell the two
     * apart. The middleware's contract is stronger than that one controller's
     * behaviour, and it is the contract every FUTURE resource behind this gate
     * will rely on: **a request that fails any check must never reach the
     * handler at all.**
     *
     * So this dispatches through the container's real middleware onto a sentinel
     * handler that records what it saw. A refusal is proved by the sentinel NOT
     * having run, which no downstream controller can fake.
     *
     * The same sentinel pins the two facts a controller behind this gate depends
     * on and that `/oauth/userinfo` happens not to use: `Request::$userId` and
     * the coroutine-local {@see RequestContext} are both populated with the
     * grant's user.
     */
    public function testNothingThatFailsAGateEverReachesTheHandler(): void
    {
        $grant = $this->issueTokensFor(self::CLIENT_ID, self::SECRET, OAuthScopes::PROFILE_READ);

        /** @var list<array{userId: ?string, contextUserId: ?string, clientId: string}> $seen */
        $seen = [];

        $sentinel = new Router();
        $sentinel->group('/oauth', static function (Router $r) use (&$seen): void {
            $r->get('/userinfo', static function (Request $req) use (&$seen): Response {
                $grant = $req->oauthGrant;
                $seen[] = [
                    'userId'        => $req->userId,
                    'contextUserId' => RequestContext::getUserId(),
                    'clientId'      => $grant instanceof OAuthGrant ? $grant->clientId : '',
                ];

                return (new Response())->json(['reached' => true]);
            });
        }, [$this->container->get(OAuthResourceMiddleware::class)]);

        // --- CONTROL: a good token reaches the handler, fully hydrated -------
        RequestContext::setUserId(null);
        self::assertSame(200, $sentinel->dispatch($this->request($grant['access_token']))->statusCode);
        self::assertCount(1, $seen, 'the sentinel handler was never reached by a VALID token');
        self::assertSame($this->userId, $seen[0]['userId'], 'Request::$userId was not populated');
        self::assertSame(
            $this->userId,
            $seen[0]['contextUserId'],
            'RequestContext::setUserId() was not called, so a downstream service reading the '
            . 'coroutine-local context sees nobody',
        );
        self::assertSame(self::CLIENT_ID, $seen[0]['clientId']);

        // --- the user is deleted; the handler must NOT run ------------------
        $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        RequestContext::setUserId(null);

        $refused = $sentinel->dispatch($this->request($grant['access_token']));

        self::assertSame(401, $refused->statusCode);
        self::assertCount(
            1,
            $seen,
            'the handler RAN for a token whose user no longer exists. The middleware must refuse '
            . 'before dispatch — a controller that happens to re-check the user is not the gate, '
            . 'and the next resource behind this middleware may not re-check anything.',
        );

        // --- and neither may a token that lacks the scope -------------------
        $this->db->query('DELETE FROM oauth_tokens');
        $this->userId = $this->insertUser('s286-scopeless', 'Scopeless');
        $narrow = $this->issueTokensFor(
            self::LIBRARY_CLIENT_ID,
            self::LIBRARY_SECRET,
            McpScopes::LIBRARY_READ,
        );

        self::assertSame(403, $sentinel->dispatch($this->request($narrow['access_token']))->statusCode);
        self::assertCount(1, $seen, 'the handler RAN for a token without the required scope');
    }

    // =====================================================================
    // 4. The CONTAINER wiring — the binding, not a hand-built object
    // =====================================================================

    /**
     * The shipped gate demands exactly `phlix:profile:read`, resolved out of the
     * real provider stack.
     *
     * ⚠ Container-resolved on purpose (S269): the scope list lives in
     * `HubServicesProvider` and nowhere else, so a hand-constructed middleware
     * proves nothing about what production wires. PHP-DI does not autowire into
     * an explicit `factory()` closure, so a missing argument here would be a
     * silent null — and for THIS class a null/empty scope list is a fail-open.
     */
    public function testTheContainerBindsTheResourceGateToTheProfileReadScope(): void
    {
        $middleware = $this->container->get(OAuthResourceMiddleware::class);

        self::assertInstanceOf(OAuthResourceMiddleware::class, $middleware);
        self::assertSame([OAuthScopes::PROFILE_READ], $middleware->requiredScopes());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Build the route table with the REAL production registrar against the REAL
     * container, so the path and the middleware chain are the shipped ones.
     */
    private function oauthRouter(): Router
    {
        $router     = new Router();
        $reflection = new ReflectionClass(Application::class);
        $app        = $reflection->newInstanceWithoutConstructor();

        foreach (['router' => $router, 'container' => $this->container, 'config' => []] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($app, $value);
        }

        $registrar = new ReflectionMethod(Application::class, 'registerOAuthRoutes');
        $registrar->setAccessible(true);
        $registrar->invoke($app);

        $paths = [];
        foreach ($router->getRoutes()['GET'] ?? [] as $route) {
            $paths[] = (string) $route['path'];
        }
        self::assertContains(
            self::USERINFO_PATH,
            $paths,
            'Application::registerOAuthRoutes() no longer registers ' . self::USERINFO_PATH,
        );

        return $router;
    }

    /**
     * The Authorization Server, built on the SAME connection, so the tokens this
     * suite presents are the ones the real flow mints.
     */
    private function authorizationServer(): OAuthController
    {
        $loggerConfig = [
            'handlers'   => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => [],
        ];
        $log = new StructuredLogger('s286-oauth', $loggerConfig);

        return new OAuthController(
            new OAuthClientRegistry($this->db, $log),
            new ConsentTicketService($this->db),
            new AuthorizationCodeService($this->db),
            $this->tokens,
            new AuditLogger(new StructuredLogger('s286-audit', $loggerConfig)),
            $log,
        );
    }

    private function realUserRepository(): \Phlix\Hub\Auth\UserRepository
    {
        $users = $this->container->get(\Phlix\Hub\Auth\UserRepository::class);
        self::assertInstanceOf(\Phlix\Hub\Auth\UserRepository::class, $users);

        return $users;
    }

    /**
     * Run the whole production flow — consent screen, ticket, code, exchange —
     * and return the token response.
     *
     * Never inserts a token row directly. A hand-inserted row would let this
     * suite pass against an Authorization Server that no longer issues anything.
     *
     * @return array<string, string>
     */
    private function issueTokensFor(string $clientId, string $secret, string $scope): array
    {
        $authorize          = new Request();
        $authorize->method  = 'GET';
        $authorize->path    = '/oauth/authorize';
        $authorize->userId  = $this->userId;
        $authorize->query   = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT,
            'scope'                 => $scope,
            'code_challenge'        => Pkce::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state'                 => 'state-' . bin2hex(random_bytes(4)),
        ];

        $screen = $this->authServer->authorize($authorize);
        self::assertSame(200, $screen->statusCode, 'the consent screen did not render: ' . $screen->body);
        self::assertSame(
            1,
            preg_match('/name="' . ConsentScreen::FIELD_TICKET . '" value="([a-f0-9]{64})"/', $screen->body, $m),
            'no consent ticket in the rendered screen',
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
        self::assertSame(302, $redirect->statusCode, 'approval did not redirect: ' . $redirect->body);

        $params = [];
        $query  = parse_url($redirect->headers['Location'] ?? '', PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
        }
        self::assertArrayHasKey('code', $params, 'the approval redirect carried no code');
        self::assertIsString($params['code']);

        $exchange         = new Request();
        $exchange->method = 'POST';
        $exchange->path   = '/oauth/token';
        $exchange->body   = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'code'          => $params['code'],
            'redirect_uri'  => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ];

        $issued = $this->authServer->token($exchange);
        self::assertSame(200, $issued->statusCode, 'the code exchange failed: ' . $issued->body);

        $payload = self::json($issued);
        self::assertIsString($payload['access_token'] ?? null);
        self::assertIsString($payload['refresh_token'] ?? null);

        /** @var array<string, string> $out */
        $out = [
            'access_token'  => (string) $payload['access_token'],
            'refresh_token' => (string) $payload['refresh_token'],
            'scope'         => is_string($payload['scope'] ?? null) ? (string) $payload['scope'] : '',
        ];

        return $out;
    }

    /**
     * Dispatch `GET /oauth/userinfo` through the production router.
     */
    private function dispatch(?string $bearer): Response
    {
        return $this->router->dispatch($this->request($bearer));
    }

    private function request(?string $bearer): Request
    {
        $request         = new Request();
        $request->method = 'GET';
        $request->path   = self::USERINFO_PATH;

        if ($bearer !== null) {
            $request->headers['authorization'] = 'Bearer ' . $bearer;
            $request->bearerToken              = $bearer;
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'the response body was not a JSON object: ' . $response->body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function insertUser(string $username, string $displayName): string
    {
        $id = Ids::uuidV4();
        $this->db->query(
            'INSERT INTO users (id, username, email, password_hash, display_name, is_admin)'
            . ' VALUES (:id, :username, :email, :hash, :displayName, 0)',
            [
                'id'          => $id,
                'username'    => $username,
                'email'       => $username . '@example.test',
                'hash'        => password_hash('s286-password', PASSWORD_DEFAULT),
                'displayName' => $displayName,
            ],
        );

        return $id;
    }
}
