<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\OAuth;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
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
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

use function hash;
use function is_array;
use function json_decode;
use function parse_str;
use function parse_url;
use function str_contains;
use function urldecode;

use const PHP_URL_QUERY;

/**
 * The S92 Authorization Code + PKCE flow, end to end, against a REAL MySQL
 * database and through the REAL {@see OAuthController}.
 *
 * ## Why this is an integration test and not a mocked one
 *
 * Every security property S92 claims is enforced by a `WHERE` clause:
 * single-use is `AND consumed_at IS NULL` inside a conditional `UPDATE`,
 * expiry is `AND expires_at > NOW()` in the same statement, and revocation is
 * `AND revoked_at IS NULL`. A mocked `Connection` returns whatever the test
 * told it to and therefore proves that the PHP around those clauses is
 * plausible — never that the clauses themselves work. The replay test below in
 * particular is meaningless against a mock: "the second redemption fails"
 * against a stub is a statement about the stub.
 *
 * So the schema comes from `migrations/045_oauth_authorization_server.sql` via
 * {@see RealDatabaseTestCase}, and the atomic claims are executed by MySQL.
 *
 * ## The shape of every test here
 *
 * Each refusal is asserted BESIDE a succeeding control, usually in the same
 * method. A 400 next to a 400 proves nothing — both could be the same
 * catch-all firing. A 400 next to a 200 that differs in exactly one input is
 * what identifies WHICH branch fired.
 *
 * ⚠ PHPUnit never enters a Swoole coroutine, so nothing here should be read as
 * evidence about coroutine-scheduled behaviour. What it does prove is the SQL
 * semantics and the controller's decision tree.
 *
 * @package Phlix\Hub\Tests\Integration\OAuth
 *
 * @group integration
 */
final class AuthorizationCodeFlowTest extends RealDatabaseTestCase
{
    use DecodedJsonAssertions;

    /** Amazon's real account-linking redirect for a European skill. */
    private const REDIRECT = 'https://layla.amazon.com/api/skill/link/M2ABCDEFG';

    /** A second registered redirect, so "exact match" has more than one target to be exact about. */
    private const REDIRECT_ALT = 'https://pitangui.amazon.com/api/skill/link/M2ABCDEFG';

    private const CLIENT_ID = 'alexa-skill';

    private const SECRET = 'amazon-supplied-client-secret';

    private const USER = 'user-0000-0000-0000-000000000001';

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private OAuthController $controller;

    private OAuthClientRegistry $clients;

    private OAuthTokenService $tokens;

    private AuthorizationCodeService $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $loggerConfig = [
            'handlers'   => ['stream' => ['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']],
            'processors' => [],
        ];

        $log = new StructuredLogger('test-oauth', $loggerConfig);

        $this->clients = new OAuthClientRegistry($this->db, $log);
        $this->codes   = new AuthorizationCodeService($this->db);
        $this->tokens  = new OAuthTokenService($this->db);

        $this->controller = new OAuthController(
            $this->clients,
            new ConsentTicketService($this->db),
            $this->codes,
            $this->tokens,
            new AuditLogger(new StructuredLogger('test-audit', $loggerConfig)),
            $log,
        );

        // The acceptance criterion: "the client registry supports at least the
        // Alexa skill as one registered client". Registered here by the
        // production code path, then used by every test below.
        $this->clients->register(
            self::CLIENT_ID,
            'Phlix for Alexa',
            [self::REDIRECT, self::REDIRECT_ALT],
            [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ, McpScopes::PLAYBACK_READ],
            self::SECRET,
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param array<string, string> $overrides Query-parameter overrides; a value
     *                                         of `''` removes the parameter.
     */
    private function authorizeRequest(array $overrides = [], ?string $userId = self::USER): Request
    {
        $query = [
            'response_type'         => 'code',
            'client_id'             => self::CLIENT_ID,
            'redirect_uri'          => self::REDIRECT,
            'scope'                 => OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
            'code_challenge'        => Pkce::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state'                 => 'opaque-state-123',
        ];

        foreach ($overrides as $key => $value) {
            if ($value === '') {
                unset($query[$key]);
                continue;
            }
            $query[$key] = $value;
        }

        $request         = new Request();
        $request->method = 'GET';
        $request->path   = '/oauth/authorize';
        $request->query  = $query;
        $request->userId = $userId;

        return $request;
    }

    /**
     * @param array<string, string> $body
     */
    private function postRequest(string $path, array $body, ?string $userId = self::USER): Request
    {
        $request         = new Request();
        $request->method = 'POST';
        $request->path   = $path;
        $request->body   = $body;
        $request->userId = $userId;

        return $request;
    }

    /**
     * Render the consent screen and pull the single-use ticket out of it.
     *
     * Deliberately scraped from the rendered HTML rather than read out of the
     * database: that is the only channel a real user's browser has, so a test
     * that read the row directly would be exercising a path no client can use.
     *
     * @param array<string, string> $overrides
     */
    private function consentTicketFor(array $overrides = []): string
    {
        $response = $this->controller->authorize($this->authorizeRequest($overrides));
        self::assertSame(200, $response->statusCode, 'the consent screen did not render');

        $pattern = '/name="' . ConsentScreen::FIELD_TICKET . '" value="([a-f0-9]{64})"/';
        if (preg_match($pattern, $response->body, $m) !== 1) {
            self::fail('no consent ticket in the rendered screen');
        }

        return $m[1];
    }

    /**
     * Run GET → POST(allow) and return the authorization code from the redirect.
     *
     * @param array<string, string> $overrides
     */
    private function obtainCode(array $overrides = []): string
    {
        $ticket = $this->consentTicketFor($overrides);

        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));

        self::assertSame(302, $response->statusCode);
        $params = self::redirectParams($response);
        self::assertArrayHasKey('code', $params, 'the approval redirect carried no code');

        return $params['code'];
    }

    /**
     * @return array<string, string>
     */
    private static function redirectParams(Response $response): array
    {
        $location = $response->headers['Location'] ?? '';
        $query    = parse_url($location, PHP_URL_QUERY);
        if (!is_string($query)) {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        $out = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function tokenRequest(string $code, array $overrides = []): Request
    {
        $body = [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ];

        foreach ($overrides as $key => $value) {
            if ($value === '') {
                unset($body[$key]);
                continue;
            }
            $body[$key] = $value;
        }

        return $this->postRequest('/oauth/token', $body, null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'the response body was not a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // =====================================================================
    // 1. The happy path — the control every refusal below is measured against
    // =====================================================================

    public function testFullAuthorizationCodePkceFlowIssuesTokens(): void
    {
        $code     = $this->obtainCode();
        $response = $this->controller->token($this->tokenRequest($code));

        self::assertSame(200, $response->statusCode);
        $payload = self::json($response);

        self::assertIsString($payload['access_token']);
        self::assertIsString($payload['refresh_token']);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertSame(OAuthTokenService::ACCESS_TTL_SECONDS, $payload['expires_in']);

        // The scope is what the USER consented to, not what the token request
        // said. Nothing in tokenRequest() mentions scope at all.
        self::assertSame(
            OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ,
            $payload['scope'],
        );

        // The issued access token actually validates, and carries exactly the
        // consented identity and scopes.
        $grant = $this->tokens->validateAccess(self::stringNode($payload['access_token']));
        self::assertNotNull($grant);
        self::assertSame(self::USER, $grant->userId);
        self::assertSame(self::CLIENT_ID, $grant->clientId);
        self::assertSame([OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ], $grant->scopes);
        self::assertTrue($grant->hasScope(McpScopes::LIBRARY_READ));
        self::assertFalse($grant->hasScope(McpScopes::PLAYBACK_READ), 'a scope never requested must not be granted');

        // Nothing that can be presented to an endpoint was written down.
        $rows = $this->db->query('SELECT token_hash FROM oauth_tokens ORDER BY kind ASC');
        self::assertIsArray($rows);
        self::assertCount(2, $rows);
        self::assertSame(hash('sha256', self::stringNode($payload['access_token'])), $rows[0]['token_hash']);
        self::assertSame(hash('sha256', self::stringNode($payload['refresh_token'])), $rows[1]['token_hash']);
    }

    /**
     * A `scope` on the TOKEN request must be ignored entirely for the
     * authorization-code grant.
     *
     * ⚠ Added after a mutation SURVIVED. `testFullAuthorizationCodePkceFlow…`
     * asserts the issued scope equals the consented one, but its token request
     * sends no `scope` at all — so a controller that read scopes out of the
     * token body and only fell back to the code's would have passed it. That is
     * the fixture-in-canonical-form trap: the input the mutation needed to
     * discriminate on was never present.
     *
     * Both directions are exercised, because they fail differently. Asking for
     * MORE is privilege escalation; asking for LESS would be a silent, un-audited
     * change to what the user approved.
     */
    public function testAScopeOnTheTokenRequestIsIgnoredForTheAuthorizationCodeGrant(): void
    {
        $consented = OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ;

        // Ask for MORE. PLAYBACK_READ is inside the CLIENT's registered ceiling
        // but was never on the consent screen, so a controller that trusted this
        // field would issue a capability the user never saw.
        $escalate = self::json($this->controller->token($this->tokenRequest($this->obtainCode(), [
            'scope' => OAuthScopes::PROFILE_READ . ' ' . McpScopes::PLAYBACK_READ,
        ])));
        self::assertSame($consented, $escalate['scope'], 'the token request widened the grant');

        $grant = $this->tokens->validateAccess(self::stringNode($escalate['access_token']));
        self::assertNotNull($grant);
        self::assertFalse($grant->hasScope(McpScopes::PLAYBACK_READ), 'an unconsented scope reached the token');

        // Ask for LESS.
        $narrow = self::json($this->controller->token($this->tokenRequest($this->obtainCode(), [
            'scope' => OAuthScopes::PROFILE_READ,
        ])));
        self::assertSame($consented, $narrow['scope'], 'the token request narrowed the grant');

        // Ask for NONSENSE — must not empty the grant either.
        $garbage = self::json($this->controller->token($this->tokenRequest($this->obtainCode(), [
            'scope' => 'admin:* nonsense',
        ])));
        self::assertSame($consented, $garbage['scope']);
    }

    public function testTheResponseIsNotCacheableAndTheConsentScreenCannotBeFramed(): void
    {
        $screen = $this->controller->authorize($this->authorizeRequest());
        self::assertSame('no-store', $screen->headers['Cache-Control'] ?? null);
        self::assertSame('DENY', $screen->headers['X-Frame-Options'] ?? null);
        self::assertSame("frame-ancestors 'none'", $screen->headers['Content-Security-Policy'] ?? null);

        $token = $this->controller->token($this->tokenRequest($this->obtainCode()));
        self::assertSame(200, $token->statusCode, 'control: the token request succeeded');
        self::assertSame('no-store', $token->headers['Cache-Control'] ?? null);
    }

    // =====================================================================
    // 2. PKCE S256 is REQUIRED, not merely supported
    // =====================================================================

    public function testTheAuthorizeEndpointRefusesThePlainChallengeMethod(): void
    {
        $response = $this->controller->authorize(
            $this->authorizeRequest(['code_challenge_method' => Pkce::METHOD_PLAIN]),
        );

        self::assertSame(302, $response->statusCode);
        $params = self::redirectParams($response);
        self::assertSame(OAuthError::INVALID_REQUEST, $params['error'] ?? null);
        self::assertStringContainsString('S256', urldecode($params['error_description'] ?? ''));
        self::assertSame('opaque-state-123', $params['state'] ?? null, 'state must be echoed on an error');
        self::assertArrayNotHasKey('code', $params);

        // Nothing was written: a refused authorization must leave no pending
        // consent request behind. Asserted BEFORE the control below, because the
        // control legitimately writes one.
        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        // Control: the same request with S256 renders the consent screen and
        // DOES write a pending row — which is what makes the zero above mean
        // "the refusal fired" rather than "this endpoint never writes".
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
        self::assertSame(1, $this->countRows('oauth_consent_requests'));
    }

    public function testAnOmittedChallengeMethodIsRefusedRatherThanDefaultingToPlain(): void
    {
        // RFC 7636 §4.4 says an absent method DEFAULTS to `plain`. That default
        // is the trap: it lets a client opt out of PKCE by saying nothing.
        $response = $this->controller->authorize($this->authorizeRequest(['code_challenge_method' => '']));

        self::assertSame(302, $response->statusCode);
        self::assertSame(OAuthError::INVALID_REQUEST, self::redirectParams($response)['error'] ?? null);
        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        // Control.
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
        self::assertSame(1, $this->countRows('oauth_consent_requests'));
    }

    public function testAnAbsentOrMalformedCodeChallengeIsRefused(): void
    {
        foreach (['', 'too-short', str_repeat('a', 44), str_repeat('a', 42) . '+'] as $challenge) {
            $response = $this->controller->authorize($this->authorizeRequest(['code_challenge' => $challenge]));

            self::assertSame(302, $response->statusCode);
            self::assertSame(
                OAuthError::INVALID_REQUEST,
                self::redirectParams($response)['error'] ?? null,
                'challenge ' . var_export($challenge, true) . ' should have been refused',
            );
        }

        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        // Control.
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
        self::assertSame(1, $this->countRows('oauth_consent_requests'));
    }

    public function testTheTokenEndpointRefusesAMissingCodeVerifier(): void
    {
        $code = $this->obtainCode();

        // The refusal.
        $response = $this->controller->token($this->tokenRequest($code, ['code_verifier' => '']));
        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);

        // A missing verifier must NOT be read as "this client isn't using
        // PKCE", so the code must still be unredeemed and the CONTROL — the
        // same code with the correct verifier — must still succeed.
        $control = $this->controller->token($this->tokenRequest($code));
        self::assertSame(200, $control->statusCode, 'the code was burned by a request that never reached it');
        self::assertIsString(self::json($control)['access_token']);
    }

    public function testTheTokenEndpointRefusesAWrongCodeVerifier(): void
    {
        $wrong = 'M25iVXpKU3puUjFaYWg3T1NDTDQtcW1ROUY5YXlwalNoc0hhakxpZlRuSQ';

        $response = $this->controller->token($this->tokenRequest($this->obtainCode(), ['code_verifier' => $wrong]));
        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);

        // Control: an otherwise identical exchange with the RIGHT verifier.
        $control = $this->controller->token($this->tokenRequest($this->obtainCode()));
        self::assertSame(200, $control->statusCode);
    }

    public function testPresentingTheChallengeAsTheVerifierDoesNotAuthenticate(): void
    {
        // This is exactly what `plain` mode would accept, so it is the sharpest
        // test that `plain` is genuinely unreachable rather than merely
        // undocumented.
        $challenge = Pkce::challengeFor(self::VERIFIER);

        $response = $this->controller->token(
            $this->tokenRequest($this->obtainCode(), ['code_verifier' => $challenge]),
        );

        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);
        self::assertSame(200, $this->controller->token($this->tokenRequest($this->obtainCode()))->statusCode);
    }

    // =====================================================================
    // 3. Codes are single-use, short-lived and bound
    // =====================================================================

    public function testAnAuthorizationCodeCannotBeRedeemedTwice(): void
    {
        $code = $this->obtainCode();

        // First redemption: the control.
        $first = $this->controller->token($this->tokenRequest($code));
        self::assertSame(200, $first->statusCode);
        $accessToken  = self::stringNode(self::json($first)['access_token']);
        $refreshToken = self::stringNode(self::json($first)['refresh_token']);
        self::assertNotNull($this->tokens->validateAccess($accessToken));

        // Second redemption of the SAME code.
        $second = $this->controller->token($this->tokenRequest($code));
        self::assertSame(400, $second->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($second)['error']);

        // RFC 6749 §4.1.2: a replayed code SHOULD revoke everything issued from
        // the first redemption. Both tokens are now dead.
        self::assertNull(
            $this->tokens->validateAccess($accessToken),
            'the replay did not revoke the access token issued by the first redemption',
        );
        self::assertNull(
            $this->tokens->consumeRefresh($refreshToken),
            'the replay did not revoke the refresh token issued by the first redemption',
        );
    }

    /**
     * The same replay, but with the two redemptions separated in TIME.
     *
     * ⚠ Read this before simplifying either replay test away. The immediate
     * replay above passes even with `AND consumed_at IS NULL` DELETED from the
     * claiming `UPDATE` — mutation-tested, it SURVIVED. The reason is a MySQL
     * detail rather than anything about this code: `UPDATE … SET consumed_at =
     * NOW()` against a row whose `consumed_at` is already this same second
     * changes no bytes, so MySQL reports **0 affected rows**, and
     * `AuthorizationCodeService::consume()` refuses on the affected-row count.
     * The refusal was real; the REASON was a timing coincidence, not the
     * predicate the test was supposed to be pinning.
     *
     * Ageing `consumed_at` backwards makes the second `UPDATE` a genuine change,
     * so the affected-row count no longer masks a missing predicate — which is
     * what makes THIS test the one that actually kills that mutation. It is also
     * the more honest model of the attack: a stolen code is replayed when the
     * attacker gets to it, not inside the same second.
     */
    public function testAReplayedCodeIsRefusedEvenWhenTheRedemptionsAreSecondsApart(): void
    {
        $code = $this->obtainCode();

        $first = $this->controller->token($this->tokenRequest($code));
        self::assertSame(200, $first->statusCode);
        $accessToken = self::stringNode(self::json($first)['access_token']);

        // Move the redemption into the past so re-stamping it would be a real
        // write. Nothing else about the row changes.
        $this->db->query(
            'UPDATE oauth_authorization_codes SET consumed_at = NOW() - INTERVAL 30 SECOND'
                . ' WHERE consumed_at IS NOT NULL',
        );

        $second = $this->controller->token($this->tokenRequest($code));
        self::assertSame(400, $second->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($second)['error']);
        self::assertNull(
            $this->tokens->validateAccess($accessToken),
            'the delayed replay did not revoke the lineage',
        );

        // Control: a FRESH code, unaged, still redeems normally — so the 400
        // above is the single-use guard firing and not a service that has
        // stopped issuing anything.
        self::assertSame(200, $this->controller->token($this->tokenRequest($this->obtainCode()))->statusCode);
    }

    public function testAnExpiredAuthorizationCodeIsRefused(): void
    {
        $code = $this->obtainCode();

        // Age the row past its expiry. The predicate under test lives in the
        // claiming UPDATE's WHERE clause, so this is the only way to reach it
        // without waiting out the TTL.
        $this->db->query(
            'UPDATE oauth_authorization_codes SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE consumed_at IS NULL',
        );

        $response = $this->controller->token($this->tokenRequest($code));
        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);

        // Control: a fresh code, same everything else, still works.
        self::assertSame(200, $this->controller->token($this->tokenRequest($this->obtainCode()))->statusCode);
    }

    public function testACodeIssuedToOneClientCannotBeRedeemedByAnother(): void
    {
        $this->clients->register(
            'other-client',
            'Some Other App',
            ['https://other.test/cb'],
            [OAuthScopes::PROFILE_READ],
            'other-secret',
        );

        $code = $this->obtainCode();

        $response = $this->controller->token($this->tokenRequest($code, [
            'client_id'     => 'other-client',
            'client_secret' => 'other-secret',
        ]));
        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);

        // Control: the legitimate client redeeming its own code.
        self::assertSame(200, $this->controller->token($this->tokenRequest($this->obtainCode()))->statusCode);
    }

    public function testTheRedirectUriPresentedAtTheTokenEndpointMustMatchTheCodesBinding(): void
    {
        // The code was issued against self::REDIRECT. REDIRECT_ALT is a
        // perfectly valid REGISTERED uri for this client — it is simply not the
        // one this code was bound to. That distinction is the point: a check
        // that only asked "is this registered?" would pass this.
        $code = $this->obtainCode();

        $response = $this->controller->token($this->tokenRequest($code, ['redirect_uri' => self::REDIRECT_ALT]));
        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($response)['error']);

        // Control: a code obtained against REDIRECT_ALT redeems against
        // REDIRECT_ALT.
        $altCode = $this->obtainCode(['redirect_uri' => self::REDIRECT_ALT]);
        $control = $this->controller->token($this->tokenRequest($altCode, ['redirect_uri' => self::REDIRECT_ALT]));
        self::assertSame(200, $control->statusCode);
    }

    public function testAnUnknownCodeIsRefusedWithoutRevokingAnything(): void
    {
        $code  = $this->obtainCode();
        $first = $this->controller->token($this->tokenRequest($code));
        self::assertSame(200, $first->statusCode);
        $accessToken = self::stringNode(self::json($first)['access_token']);

        // A code that never existed is not a replay, and must not cost anybody
        // their live tokens.
        $response = $this->controller->token($this->tokenRequest(str_repeat('f', 64)));
        self::assertSame(400, $response->statusCode);
        self::assertNotNull(
            $this->tokens->validateAccess($accessToken),
            'a typo in a code revoked an unrelated live token',
        );
    }

    // =====================================================================
    // 4. redirect_uri is matched EXACTLY
    // =====================================================================

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unregisteredRedirectUriProvider(): iterable
    {
        yield 'trailing slash'      => [self::REDIRECT . '/'];
        yield 'path appended'       => [self::REDIRECT . '/evil'];
        yield 'query appended'      => [self::REDIRECT . '?x=1'];
        yield 'fragment appended'   => [self::REDIRECT . '#x'];
        yield 'lookalike host'      => ['https://layla.amazon.com.evil.test/api/skill/link/M2ABCDEFG'];
        yield 'embedded in a query' => ['https://evil.test/?u=' . self::REDIRECT];
        yield 'origin only'         => ['https://layla.amazon.com'];
        yield 'scheme downgraded'   => ['http://layla.amazon.com/api/skill/link/M2ABCDEFG'];
        yield 'userinfo injected'   => ['https://layla.amazon.com@evil.test/api/skill/link/M2ABCDEFG'];
    }

    /**
     * @dataProvider unregisteredRedirectUriProvider
     */
    public function testAnUnregisteredRedirectUriIsRefusedAndNeverRedirectedTo(string $candidate): void
    {
        $response = $this->controller->authorize($this->authorizeRequest(['redirect_uri' => $candidate]));

        // 400 with an HTML page, NOT a 302. Redirecting here would make the
        // endpoint an open redirect and would deliver diagnostics to a
        // destination the real client never registered.
        self::assertSame(400, $response->statusCode);
        self::assertArrayNotHasKey('Location', $response->headers, 'an unregistered URI must never be redirected to');
        self::assertStringNotContainsString(
            $candidate,
            $response->body,
            'the rejected URI was reflected into the page'
        );
        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        // Control: the registered URI, same request otherwise, renders the
        // consent screen.
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
    }

    public function testAMissingRedirectUriIsRefusedRatherThanInferred(): void
    {
        // RFC 6749 allows a server to fall back to a client's single registered
        // URI. This client has two, but the refusal is unconditional: which URI
        // a user's code went to must not depend on how many rows a client has.
        $response = $this->controller->authorize($this->authorizeRequest(['redirect_uri' => '']));

        self::assertSame(400, $response->statusCode);
        self::assertArrayNotHasKey('Location', $response->headers);
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
    }

    public function testAnUnknownOrDisabledClientIsRefusedWithoutRedirecting(): void
    {
        $unknown = $this->controller->authorize($this->authorizeRequest(['client_id' => 'no-such-client']));
        self::assertSame(400, $unknown->statusCode);
        self::assertArrayNotHasKey('Location', $unknown->headers);

        // Control BEFORE disabling.
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);

        $this->clients->disable(self::CLIENT_ID);
        $disabled = $this->controller->authorize($this->authorizeRequest());
        self::assertSame(400, $disabled->statusCode);
        self::assertArrayNotHasKey('Location', $disabled->headers);
    }

    // =====================================================================
    // 5. Consent is ENFORCED server-side
    // =====================================================================

    public function testRenderingTheConsentScreenMintsNoAuthorizationCode(): void
    {
        // The GET is where a client that wanted to skip consent would have to
        // get its code from. It renders 200 and writes a pending request — and
        // no code, ever.
        $response = $this->controller->authorize($this->authorizeRequest());

        self::assertSame(200, $response->statusCode);
        self::assertArrayNotHasKey('Location', $response->headers, 'the GET redirected somewhere with a code');
        self::assertSame(1, $this->countRows('oauth_consent_requests'));
        self::assertSame(
            0,
            $this->countRows('oauth_authorization_codes'),
            'GET /oauth/authorize minted an authorization code — consent is decorative',
        );
        self::assertSame(0, $this->countRows('oauth_tokens'));

        // Control: the POST that DOES mint one.
        $ticket = $this->consentTicketFor();
        $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));
        self::assertSame(1, $this->countRows('oauth_authorization_codes'));
    }

    public function testTheConsentPostWithoutATicketMintsNothing(): void
    {
        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertArrayNotHasKey('Location', $response->headers);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));
    }

    public function testAnInventedConsentTicketMintsNothing(): void
    {
        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => str_repeat('a', 64),
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));

        // Control: a real ticket does mint one.
        $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $this->consentTicketFor(),
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));
        self::assertSame(1, $this->countRows('oauth_authorization_codes'));
    }

    public function testAConsentTicketIsSingleUse(): void
    {
        $ticket = $this->consentTicketFor();
        $body   = [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ];

        $first = $this->controller->consent($this->postRequest('/oauth/authorize', $body));
        self::assertSame(302, $first->statusCode);
        self::assertArrayHasKey('code', self::redirectParams($first));

        $second = $this->controller->consent($this->postRequest('/oauth/authorize', $body));
        self::assertSame(400, $second->statusCode);
        self::assertArrayNotHasKey('Location', $second->headers);
        self::assertSame(1, $this->countRows('oauth_authorization_codes'), 'a replayed ticket minted a second code');
    }

    /**
     * The consent ticket's single-use guard, with the two submissions separated
     * in TIME.
     *
     * ⚠ Exactly the same trap as
     * {@see testAReplayedCodeIsRefusedEvenWhenTheRedemptionsAreSecondsApart}:
     * {@see testAConsentTicketIsSingleUse} SURVIVED deletion of
     * `AND consumed_at IS NULL` from `ConsentTicketService::consume()`, because
     * re-stamping `consumed_at` within the same second is a zero-affected-row
     * `UPDATE` in MySQL. Ageing the row is what makes the predicate — rather
     * than the clock — the thing being tested.
     */
    public function testAConsentTicketIsStillSingleUseWhenTheSubmissionsAreSecondsApart(): void
    {
        $ticket = $this->consentTicketFor();
        $body   = [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ];

        self::assertSame(302, $this->controller->consent($this->postRequest('/oauth/authorize', $body))->statusCode);

        $this->db->query(
            'UPDATE oauth_consent_requests SET consumed_at = NOW() - INTERVAL 30 SECOND'
                . ' WHERE consumed_at IS NOT NULL',
        );

        $second = $this->controller->consent($this->postRequest('/oauth/authorize', $body));
        self::assertSame(400, $second->statusCode);
        self::assertArrayNotHasKey('Location', $second->headers);
        self::assertSame(
            1,
            $this->countRows('oauth_authorization_codes'),
            'a ticket replayed after a delay minted a second code',
        );

        // Control: a fresh ticket still works, so the 400 is the single-use
        // guard and not a broken endpoint.
        $control = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $this->consentTicketFor(),
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));
        self::assertSame(302, $control->statusCode);
        self::assertSame(2, $this->countRows('oauth_authorization_codes'));
    }

    public function testAnExpiredConsentTicketMintsNothing(): void
    {
        $ticket = $this->consentTicketFor();
        $this->db->query(
            'UPDATE oauth_consent_requests SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE consumed_at IS NULL',
        );

        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));
    }

    public function testAConsentTicketIsInertInAnotherUsersSession(): void
    {
        $ticket = $this->consentTicketFor();

        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ], 'user-0000-0000-0000-000000000002'));

        self::assertSame(403, $response->statusCode);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));

        // Control: the SAME flow, in the session that rendered the screen.
        $control = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $this->consentTicketFor(),
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));
        self::assertSame(302, $control->statusCode);
    }

    public function testOnlyTheExactAllowDecisionAuthorises(): void
    {
        foreach ([ConsentScreen::DECISION_DENY, '', 'ALLOW', 'allow ', 'yes', 'allowed', 'true'] as $decision) {
            $body = [ConsentScreen::FIELD_TICKET => $this->consentTicketFor()];
            if ($decision !== '') {
                $body[ConsentScreen::FIELD_DECISION] = $decision;
            }

            $response = $this->controller->consent($this->postRequest('/oauth/authorize', $body));

            self::assertSame(302, $response->statusCode);
            $params = self::redirectParams($response);
            self::assertSame(
                OAuthError::ACCESS_DENIED,
                $params['error'] ?? null,
                var_export($decision, true) . ' was treated as consent',
            );
            self::assertArrayNotHasKey('code', $params);
            self::assertSame('opaque-state-123', $params['state'] ?? null);
        }

        self::assertSame(0, $this->countRows('oauth_authorization_codes'));

        // Control: exactly "allow".
        $control = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $this->consentTicketFor(),
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));
        self::assertSame(302, $control->statusCode);
        self::assertArrayHasKey('code', self::redirectParams($control));
    }

    public function testAClientDisabledWhileTheConsentScreenWasOpenMintsNothing(): void
    {
        $ticket = $this->consentTicketFor();
        $this->clients->disable(self::CLIENT_ID);

        $response = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ]));

        self::assertSame(400, $response->statusCode);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));
    }

    public function testTheConsentScreenShowsExactlyTheScopesTheTokenWillCarry(): void
    {
        $response = $this->controller->authorize($this->authorizeRequest());

        self::assertStringContainsString(OAuthScopes::describe(OAuthScopes::PROFILE_READ), $response->body);
        self::assertStringContainsString(OAuthScopes::describe(McpScopes::LIBRARY_READ), $response->body);
        self::assertStringNotContainsString(McpScopes::PLAYBACK_READ, $response->body);

        $payload = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));
        self::assertSame(OAuthScopes::PROFILE_READ . ' ' . McpScopes::LIBRARY_READ, $payload['scope']);
    }

    // =====================================================================
    // 6. Scope: no default grant, no widening
    // =====================================================================

    public function testAnAbsentScopeIsRefusedRatherThanDefaulted(): void
    {
        // S261 shipped the other shape in this very repository: an MCP token
        // minted with no `scopes` field received the WRITE scope.
        $response = $this->controller->authorize($this->authorizeRequest(['scope' => '']));

        self::assertSame(302, $response->statusCode);
        self::assertSame(OAuthError::INVALID_SCOPE, self::redirectParams($response)['error'] ?? null);
        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
    }

    public function testAScopeOfOnlyUnknownValuesIsRefusedRatherThanEmptied(): void
    {
        $response = $this->controller->authorize($this->authorizeRequest(['scope' => 'admin:* root nonsense']));

        self::assertSame(302, $response->statusCode);
        self::assertSame(OAuthError::INVALID_SCOPE, self::redirectParams($response)['error'] ?? null);
        self::assertSame(0, $this->countRows('oauth_consent_requests'));
    }

    public function testAScopeOutsideTheClientsCeilingIsRefused(): void
    {
        // PLAYBACK_CONTROL is a real scope that this client is NOT registered
        // for — so this distinguishes "unknown scope" from "not yours".
        $response = $this->controller->authorize(
            $this->authorizeRequest(['scope' => McpScopes::PLAYBACK_CONTROL]),
        );

        self::assertSame(302, $response->statusCode);
        self::assertSame(OAuthError::INVALID_SCOPE, self::redirectParams($response)['error'] ?? null);

        // Control: a scope that IS inside the ceiling.
        self::assertSame(
            200,
            $this->controller->authorize($this->authorizeRequest(['scope' => McpScopes::PLAYBACK_READ]))->statusCode,
        );
    }

    public function testAClientWithAnEmptyScopeAllowListIsUnusableRatherThanUnrestricted(): void
    {
        // Provision the failure directly, bypassing register()'s validation —
        // this is what a hand-edited row or a half-finished migration looks
        // like. An empty allow-list must read as "no client", never as "no
        // restrictions".
        $this->db->query(
            'INSERT INTO oauth_clients (id, client_id, name, redirect_uris, allowed_scopes, is_confidential)'
                . ' VALUES (:id, :client_id, :name, :uris, :scopes, 0)',
            [
                'id'        => '00000000-0000-4000-8000-000000000099',
                'client_id' => 'empty-scopes',
                'name'      => 'Half-provisioned',
                'uris'      => self::REDIRECT,
                'scopes'    => '',
            ],
        );

        self::assertNull($this->clients->find('empty-scopes'));

        $response = $this->controller->authorize($this->authorizeRequest(['client_id' => 'empty-scopes']));
        self::assertSame(400, $response->statusCode);
        self::assertArrayNotHasKey('Location', $response->headers);

        // Control: the properly provisioned client resolves and works.
        self::assertNotNull($this->clients->find(self::CLIENT_ID));
        self::assertSame(200, $this->controller->authorize($this->authorizeRequest())->statusCode);
    }

    public function testAClientWithNoRedirectUrisIsUnusableRatherThanUnrestricted(): void
    {
        $this->db->query(
            'INSERT INTO oauth_clients (id, client_id, name, redirect_uris, allowed_scopes, is_confidential)'
                . ' VALUES (:id, :client_id, :name, :uris, :scopes, 0)',
            [
                'id'        => '00000000-0000-4000-8000-000000000098',
                'client_id' => 'no-uris',
                'name'      => 'Half-provisioned',
                'uris'      => '',
                'scopes'    => OAuthScopes::PROFILE_READ,
            ],
        );

        self::assertNull($this->clients->find('no-uris'));
        self::assertSame(
            400,
            $this->controller->authorize($this->authorizeRequest(['client_id' => 'no-uris']))->statusCode,
        );
    }

    // =====================================================================
    // 7. Client authentication at the token endpoint
    // =====================================================================

    public function testAConfidentialClientMustPresentItsSecret(): void
    {
        $code = $this->obtainCode();

        $noSecret = $this->controller->token($this->tokenRequest($code, ['client_secret' => '']));
        self::assertSame(401, $noSecret->statusCode);
        self::assertSame(OAuthError::INVALID_CLIENT, self::json($noSecret)['error']);
        self::assertArrayHasKey('WWW-Authenticate', $noSecret->headers);

        $wrongSecret = $this->controller->token($this->tokenRequest($code, ['client_secret' => 'wrong']));
        self::assertSame(401, $wrongSecret->statusCode);

        // Control: the code was NOT burned by either refusal — client
        // authentication happens before the code is touched.
        self::assertSame(200, $this->controller->token($this->tokenRequest($code))->statusCode);
    }

    public function testClientCredentialsMayBePresentedByHttpBasic(): void
    {
        $code = $this->obtainCode();

        $request = $this->tokenRequest($code, ['client_id' => '', 'client_secret' => '']);
        $request->headers['authorization'] = 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::SECRET);

        $response = $this->controller->token($request);
        self::assertSame(200, $response->statusCode);
        self::assertIsString(self::json($response)['access_token']);
    }

    public function testABadBasicSecretIsRefused(): void
    {
        $code = $this->obtainCode();

        $request = $this->tokenRequest($code, ['client_id' => '', 'client_secret' => '']);
        $request->headers['authorization'] = 'Basic ' . base64_encode(self::CLIENT_ID . ':wrong');

        self::assertSame(401, $this->controller->token($request)->statusCode);

        // Control: the same header with the right secret.
        $request->headers['authorization'] = 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::SECRET);
        self::assertSame(200, $this->controller->token($request)->statusCode);
    }

    public function testAnUnsupportedGrantTypeIsRefused(): void
    {
        $response = $this->controller->token(
            $this->tokenRequest($this->obtainCode(), ['grant_type' => 'password']),
        );

        self::assertSame(400, $response->statusCode);
        self::assertSame(OAuthError::UNSUPPORTED_GRANT_TYPE, self::json($response)['error']);
    }

    // =====================================================================
    // 8. Refresh rotation
    // =====================================================================

    public function testARefreshTokenRotatesAndTheOldOneStopsWorking(): void
    {
        $first = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));

        $rotated = $this->controller->token($this->postRequest('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'refresh_token' => self::stringNode($first['refresh_token']),
        ], null));

        self::assertSame(200, $rotated->statusCode);
        $second = self::json($rotated);
        self::assertNotSame($first['refresh_token'], $second['refresh_token'], 'the refresh token was not rotated');
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertSame($first['scope'], $second['scope']);

        // The new access token works...
        self::assertNotNull($this->tokens->validateAccess(self::stringNode($second['access_token'])));

        // ...and the old refresh token is dead. Presenting it again is the
        // signature of a stolen token, so it cuts the whole lineage.
        $replay = $this->controller->token($this->postRequest('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'refresh_token' => self::stringNode($first['refresh_token']),
        ], null));
        self::assertSame(400, $replay->statusCode);
        self::assertSame(OAuthError::INVALID_GRANT, self::json($replay)['error']);

        self::assertNull(
            $this->tokens->validateAccess(self::stringNode($second['access_token'])),
            'a replayed refresh token did not revoke the lineage',
        );
    }

    public function testARefreshMayNarrowTheGrantButNotWidenIt(): void
    {
        $issued = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));

        // Narrow: allowed.
        $narrowed = $this->controller->token($this->postRequest('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'refresh_token' => self::stringNode($issued['refresh_token']),
            'scope'         => OAuthScopes::PROFILE_READ,
        ], null));
        self::assertSame(200, $narrowed->statusCode);
        self::assertSame(OAuthScopes::PROFILE_READ, self::json($narrowed)['scope']);

        // Widen: refused. PLAYBACK_READ is inside the CLIENT's ceiling but was
        // never part of THIS grant, which is the distinction being tested.
        $widened = $this->controller->token($this->postRequest('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'refresh_token' => self::stringNode(self::json($narrowed)['refresh_token']),
            'scope'         => OAuthScopes::PROFILE_READ . ' ' . McpScopes::PLAYBACK_READ,
        ], null));
        self::assertSame(400, $widened->statusCode);
        self::assertSame(OAuthError::INVALID_SCOPE, self::json($widened)['error']);
    }

    public function testAnAccessTokenCannotBeUsedAsARefreshTokenOrViceVersa(): void
    {
        $issued = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));

        // The two kinds share a table; the `kind` column is what keeps them
        // apart. A prefix check alone would not.
        self::assertNull($this->tokens->validateAccess(self::stringNode($issued['refresh_token'])));
        self::assertNull($this->tokens->consumeRefresh(self::stringNode($issued['access_token'])));

        // Control: each in its own role.
        self::assertNotNull($this->tokens->validateAccess(self::stringNode($issued['access_token'])));
        self::assertNotNull($this->tokens->consumeRefresh(self::stringNode($issued['refresh_token'])));
    }

    // =====================================================================
    // 9. Housekeeping
    // =====================================================================

    public function testPruningRemovesSpentRowsButNotLiveOnes(): void
    {
        $live = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));

        // A second, fully-spent grant.
        $spent = self::json($this->controller->token($this->tokenRequest($this->obtainCode())));
        $this->db->query(
            'UPDATE oauth_tokens SET revoked_at = NOW() WHERE token_hash = :h',
            ['h' => hash('sha256', self::stringNode($spent['access_token']))],
        );

        self::assertGreaterThan(0, $this->tokens->pruneExpired());
        self::assertNotNull(
            $this->tokens->validateAccess(self::stringNode($live['access_token'])),
            'pruning deleted a live token',
        );
        self::assertNull($this->tokens->validateAccess(self::stringNode($spent['access_token'])));
    }

    public function testAnUnauthenticatedCallerReachesNeitherTheScreenNorACode(): void
    {
        // Belt to AuthMiddleware's braces: the controller does not trust a gate
        // it does not itself install, because that is one route-table edit away
        // from being an open endpoint.
        $screen = $this->controller->authorize($this->authorizeRequest([], null));
        self::assertSame(401, $screen->statusCode);
        self::assertSame(0, $this->countRows('oauth_consent_requests'));

        $ticket = $this->consentTicketFor();
        $post   = $this->controller->consent($this->postRequest('/oauth/authorize', [
            ConsentScreen::FIELD_TICKET   => $ticket,
            ConsentScreen::FIELD_DECISION => ConsentScreen::DECISION_ALLOW,
        ], null));
        self::assertSame(401, $post->statusCode);
        self::assertSame(0, $this->countRows('oauth_authorization_codes'));
    }

    /**
     * Count rows in one of the S92 tables.
     */
    private function countRows(string $table): int
    {
        // The table name is a literal from this file, never input.
        $rows = $this->db->query('SELECT COUNT(*) AS n FROM `' . $table . '`');
        self::assertIsArray($rows);
        self::assertArrayHasKey(0, $rows);
        self::assertIsArray($rows[0]);

        return (int) $rows[0]['n'];
    }
}
