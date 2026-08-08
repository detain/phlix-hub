<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentScreen;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthClient;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;
use Phlix\Hub\OAuth\PendingAuthorization;
use Phlix\Hub\OAuth\Pkce;

use function base64_decode;
use function explode;
use function hash_equals;
use function http_build_query;
use function in_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * The hub's OAuth 2.0 Authorization Server endpoints (S92).
 *
 * Three operations, and the split between them is the security model:
 *
 * | Route                   | Gate           | Mints a code? |
 * |-------------------------|----------------|---------------|
 * | `GET  /oauth/authorize` | AuthMiddleware | **No**        |
 * | `POST /oauth/authorize` | AuthMiddleware | Yes           |
 * | `POST /oauth/token`     | none (client authenticates itself) | No |
 *
 * ## Consent is enforced, not merely displayed
 *
 * There is no call to {@see AuthorizationCodeService::mint()} anywhere in
 * {@see authorize()}. The GET validates the request, stores it as a
 * {@see PendingAuthorization}, and renders a form. The only way to a code is
 * {@see consent()}, which requires a single-use ticket that exists only because
 * a consent screen was rendered, and which is bound to the user id that
 * rendered it. A client that skips the screen — or scripts the redirect and
 * never shows it to anyone — receives no code, because there is no code-minting
 * path it can reach.
 *
 * ## What is deliberately stricter than RFC 6749
 *
 *  - **PKCE `S256` is required, not offered.** `code_challenge` and
 *    `code_challenge_method=S256` are mandatory on every authorization request,
 *    for confidential clients as well as public ones, and `code_verifier` is
 *    mandatory on every code exchange. RFC 7636 §4.4 says an absent method
 *    defaults to `plain`; here an absent method is a refusal. See {@see Pkce}.
 *  - **`redirect_uri` is mandatory and matched whole.** The RFC lets a server
 *    fall back to a client's single registered URI when the parameter is
 *    omitted; that makes "which URI did the user's code go to?" depend on how
 *    many rows a client happens to have. It is always explicit here, and always
 *    compared with {@see OAuthClient::allowsRedirectUri()}, which is exact.
 *  - **`scope` is mandatory.** There is no default grant. S261 in this very
 *    repository shipped the other shape — an MCP token minted with no `scopes`
 *    field received the WRITE scope — and a defaulting Authorization Server
 *    makes that failure silent and third-party-triggerable.
 *
 * ## Where errors go
 *
 * Two failures must NEVER redirect: an unknown/disabled `client_id`, and a
 * `redirect_uri` that is not registered. Redirecting either would turn this
 * endpoint into an open redirect and, in the second case, deliver diagnostics
 * to a destination the real client never registered. Both render a terminal
 * HTML page instead. Every LATER failure has, by definition, already proven the
 * destination is one the client registered, so it redirects with `error=` and
 * the client's `state`, which is what a client can actually handle.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthController
{
    /** The only `response_type` this server implements. */
    public const string RESPONSE_TYPE_CODE = 'code';

    /** Authorization Code grant. */
    public const string GRANT_AUTHORIZATION_CODE = 'authorization_code';

    /** Refresh Token grant. */
    public const string GRANT_REFRESH_TOKEN = 'refresh_token';

    /** Path the consent form posts back to. */
    public const string AUTHORIZE_PATH = '/oauth/authorize';

    /**
     * ⚠ The logger is INJECTED, not fetched from {@see LoggerFactory} inside the
     * constructor. A static factory call there needs the application's logger
     * configuration to have been loaded, which makes the controller impossible
     * to construct in a test — and a controller that cannot be constructed is a
     * controller whose decision tree is never exercised by anything except
     * production.
     *
     * @param OAuthClientRegistry      $clients Client registry.
     * @param ConsentTicketService     $tickets Consent ticket store.
     * @param AuthorizationCodeService $codes   Authorization code store.
     * @param OAuthTokenService        $tokens  Access/refresh token store.
     * @param AuditLogger              $audit   Audit trail for grants and refusals.
     * @param StructuredLogger         $log     Auth-channel log for operator-only detail.
     */
    public function __construct(
        private readonly OAuthClientRegistry $clients,
        private readonly ConsentTicketService $tickets,
        private readonly AuthorizationCodeService $codes,
        private readonly OAuthTokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly StructuredLogger $log,
    ) {
    }

    /**
     * `GET /oauth/authorize` — validate the request and render the consent
     * screen. Mints nothing.
     *
     * @param Request               $request Incoming request (already authenticated
     *                                       by `AuthMiddleware`).
     * @param array<string, string> $params  Path parameters (unused).
     */
    public function authorize(Request $request, array $params = []): Response
    {
        unset($params);

        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            // Unreachable behind AuthMiddleware, but a controller that trusts a
            // middleware it does not itself install is one route-table edit away
            // from being an open endpoint.
            return $this->errorPage(401, 'Sign-in required', 'You must be signed in to authorise an application.');
        }

        $query       = $request->query;
        $clientId    = self::str($query, 'client_id');
        $redirectUri = self::str($query, 'redirect_uri');

        // ---- Non-redirectable failures -------------------------------------
        $client = $this->clients->find($clientId);
        if ($client === null) {
            $this->refuse('oauth.authorize.unknown_client', ['client_id' => $clientId]);

            return $this->errorPage(
                400,
                'Unknown application',
                'The application that sent you here is not registered with this Phlix hub.',
            );
        }

        if (!$client->allowsRedirectUri($redirectUri)) {
            $this->refuse('oauth.authorize.redirect_uri_mismatch', [
                'client_id'    => $clientId,
                'redirect_uri' => $redirectUri,
            ]);

            return $this->errorPage(
                400,
                'Invalid redirect address',
                'The application asked to be sent back to an address it has not registered.',
            );
        }

        // ---- Redirectable failures -----------------------------------------
        $state = self::strOrNull($query, 'state');

        $responseType = self::str($query, 'response_type');
        if (!hash_equals(self::RESPONSE_TYPE_CODE, $responseType)) {
            return $this->redirectError(
                $redirectUri,
                OAuthError::UNSUPPORTED_RESPONSE_TYPE,
                'Only response_type=code is supported.',
                $state,
            );
        }

        $method = self::str($query, 'code_challenge_method');
        if (!Pkce::isSupportedMethod($method)) {
            // Covers BOTH an explicit `plain` and an absent parameter. RFC 7636
            // would default the absent case to `plain`; this server does not.
            return $this->redirectError(
                $redirectUri,
                OAuthError::INVALID_REQUEST,
                'code_challenge_method must be S256; "' . Pkce::METHOD_PLAIN . '" and omission are both refused.',
                $state,
            );
        }

        $challenge = self::str($query, 'code_challenge');
        if (!Pkce::isValidChallenge($challenge)) {
            return $this->redirectError(
                $redirectUri,
                OAuthError::INVALID_REQUEST,
                'code_challenge must be a 43-character base64url SHA-256 digest.',
                $state,
            );
        }

        $requested = OAuthScopes::parse(self::str($query, 'scope'));
        if ($requested === []) {
            // No default grant: "nothing recognised" is a refusal, never
            // "give it the usual".
            return $this->redirectError(
                $redirectUri,
                OAuthError::INVALID_SCOPE,
                'scope is required and must name at least one supported scope.',
                $state,
            );
        }

        if (!$client->permits($requested)) {
            return $this->redirectError(
                $redirectUri,
                OAuthError::INVALID_SCOPE,
                'This application is not registered for one or more of the scopes it requested.',
                $state,
            );
        }

        $issued = $this->tickets->issue(
            new PendingAuthorization($userId, $client->clientId, $redirectUri, $requested, $state, $challenge),
        );

        return $this->secure(
            (new Response())->html(
                ConsentScreen::render($client, $requested, $issued['ticket'], self::AUTHORIZE_PATH),
            ),
        );
    }

    /**
     * `POST /oauth/authorize` — record the user's decision and, on approval,
     * mint the authorization code.
     *
     * @param Request               $request Incoming request.
     * @param array<string, string> $params  Path parameters (unused).
     */
    public function consent(Request $request, array $params = []): Response
    {
        unset($params);

        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return $this->errorPage(401, 'Sign-in required', 'You must be signed in to authorise an application.');
        }

        $ticket = self::str($request->body, ConsentScreen::FIELD_TICKET);
        if ($ticket === '') {
            $this->refuse('oauth.consent.no_ticket', ['user_id' => $userId]);

            return $this->errorPage(
                400,
                'Nothing to authorise',
                'This page was submitted without a valid authorisation request. Start again from the application.',
            );
        }

        $pending = $this->tickets->consume($ticket);
        if ($pending === null) {
            // Unknown, expired, or already used. All three are the same refusal:
            // distinguishing them would tell a holder of a stolen ticket whether
            // it was ever real.
            $this->refuse('oauth.consent.ticket_rejected', ['user_id' => $userId]);

            return $this->errorPage(
                400,
                'This request has expired',
                'The authorisation request was already used or has timed out. Start again from the application.',
            );
        }

        if (!hash_equals($pending->userId, $userId)) {
            // The ticket was minted in somebody else's session. Refuse rather
            // than silently re-attributing the grant to whoever submitted it.
            $this->refuse('oauth.consent.user_mismatch', [
                'user_id'   => $userId,
                'client_id' => $pending->clientId,
            ]);

            return $this->errorPage(
                403,
                'Signed in as someone else',
                'This authorisation request belongs to a different Phlix account.',
            );
        }

        // Re-validate against the registry rather than trusting the stored row
        // alone: the client may have been disabled, or its registered redirect
        // URIs changed, between the screen being rendered and Allow being
        // pressed. The stored redirect URI is re-checked against the CURRENT
        // registration for the same reason.
        $client = $this->clients->find($pending->clientId);
        if ($client === null || !$client->allowsRedirectUri($pending->redirectUri)) {
            $this->refuse('oauth.consent.client_no_longer_valid', ['client_id' => $pending->clientId]);

            return $this->errorPage(
                400,
                'Application unavailable',
                'This application can no longer be authorised on this hub.',
            );
        }

        if (!hash_equals(ConsentScreen::DECISION_ALLOW, self::str($request->body, ConsentScreen::FIELD_DECISION))) {
            // Anything that is not exactly "allow" — including "deny", a blank
            // field, and a value invented by a client — is a refusal.
            return $this->redirectError(
                $pending->redirectUri,
                OAuthError::ACCESS_DENIED,
                'The user declined the request.',
                $pending->state,
            );
        }

        if (!$client->permits($pending->scopes)) {
            // The client's ceiling may have been narrowed while the screen was
            // open. Granting what the user saw would exceed the current
            // registration.
            return $this->redirectError(
                $pending->redirectUri,
                OAuthError::INVALID_SCOPE,
                'This application is no longer registered for the scopes shown.',
                $pending->state,
            );
        }

        $code = $this->codes->mint(
            $client->clientId,
            $userId,
            $pending->redirectUri,
            $pending->scopes,
            $pending->codeChallenge,
        );

        $this->audit->logAdminAction($userId, 'oauth.authorization_granted', $client->clientId, [
            'scopes'  => OAuthScopes::toStorage($pending->scopes),
            'code_id' => $code['id'],
        ]);

        $redirectParams = ['code' => $code['code']];
        if ($pending->state !== null) {
            $redirectParams['state'] = $pending->state;
        }

        return $this->secure(
            (new Response())->redirect(self::appendQuery($pending->redirectUri, $redirectParams), 302),
        );
    }

    /**
     * `POST /oauth/token` — exchange an authorization code, or rotate a refresh
     * token, for tokens.
     *
     * Ungated by route middleware: the caller is a CLIENT, not a hub user, and
     * authenticates itself here with `client_id` (+ `client_secret` when
     * confidential) plus, for the code grant, proof of the PKCE verifier.
     *
     * @param Request               $request Incoming request.
     * @param array<string, string> $params  Path parameters (unused).
     */
    public function token(Request $request, array $params = []): Response
    {
        unset($params);

        $body      = $request->body;
        $grantType = self::str($body, 'grant_type');

        $client = $this->authenticateClient($request);
        if ($client === null) {
            return $this->tokenError(
                401,
                OAuthError::INVALID_CLIENT,
                'Client authentication failed.',
            );
        }

        if (hash_equals(self::GRANT_AUTHORIZATION_CODE, $grantType)) {
            return $this->exchangeAuthorizationCode($request, $client);
        }

        if (hash_equals(self::GRANT_REFRESH_TOKEN, $grantType)) {
            return $this->exchangeRefreshToken($request, $client);
        }

        return $this->tokenError(
            400,
            OAuthError::UNSUPPORTED_GRANT_TYPE,
            'Supported grant types are authorization_code and refresh_token.',
        );
    }

    /**
     * The Authorization Code grant.
     *
     * Order matters: the code is CONSUMED before its bindings are checked, so a
     * failed exchange still burns it. RFC 6749 §4.1.2 requires a code be single
     * use and says the server SHOULD revoke tokens issued from a replayed one;
     * a code that survived a failed PKCE check would be a code an attacker
     * could keep guessing against.
     *
     * @param Request     $request Token request.
     * @param OAuthClient $client  Already-authenticated client.
     */
    private function exchangeAuthorizationCode(Request $request, OAuthClient $client): Response
    {
        $body        = $request->body;
        $verifier    = self::str($body, 'code_verifier');
        $rawCode     = self::str($body, 'code');
        $redirectUri = self::str($body, 'redirect_uri');

        if ($verifier === '') {
            // A missing verifier is refused outright. It is NOT read as "this
            // client is not using PKCE" — that reading is exactly how an
            // attacker holding a stolen code opts out of the control.
            $this->refuse('oauth.token.missing_code_verifier', ['client_id' => $client->clientId]);

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'code_verifier is required.');
        }

        $grant = $this->codes->consume($rawCode);
        if ($grant === null) {
            $replayed = $this->codes->replayedCodeId($rawCode);
            if ($replayed !== null) {
                // A genuine replay: cut every token that descended from the
                // first, successful redemption.
                $revoked = $this->tokens->revokeForCode($replayed);
                $this->refuse('oauth.token.code_replayed', [
                    'client_id'      => $client->clientId,
                    'code_id'        => $replayed,
                    'tokens_revoked' => $revoked,
                ]);
            } else {
                $this->refuse('oauth.token.code_unusable', ['client_id' => $client->clientId]);
            }

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The authorization code is not valid.');
        }

        if (!hash_equals($grant->clientId, $client->clientId)) {
            // The code belongs to a different client. It has just been burned,
            // which is correct: the legitimate client must start over rather
            // than race an impostor for it.
            $this->refuse('oauth.token.code_client_mismatch', [
                'client_id' => $client->clientId,
                'code_id'   => $grant->id,
            ]);

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The authorization code is not valid.');
        }

        if (!hash_equals($grant->redirectUri, $redirectUri)) {
            $this->refuse('oauth.token.redirect_uri_mismatch', [
                'client_id' => $client->clientId,
                'code_id'   => $grant->id,
            ]);

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The authorization code is not valid.');
        }

        if (!Pkce::verify($verifier, $grant->codeChallenge)) {
            $this->refuse('oauth.token.pkce_failed', [
                'client_id' => $client->clientId,
                'code_id'   => $grant->id,
            ]);

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The authorization code is not valid.');
        }

        // The scopes come from the CODE — i.e. from what the user was shown and
        // approved — and never from this request.
        $issued = $this->tokens->issue($grant->clientId, $grant->userId, $grant->scopes, $grant->id);

        $this->audit->logAdminAction($grant->userId, 'oauth.token_issued', $client->clientId, [
            'grant_type' => self::GRANT_AUTHORIZATION_CODE,
            'scopes'     => $issued['scope'],
        ]);

        return $this->secure((new Response())->json($issued, 200));
    }

    /**
     * The Refresh Token grant, with rotation.
     *
     * @param Request     $request Token request.
     * @param OAuthClient $client  Already-authenticated client.
     */
    private function exchangeRefreshToken(Request $request, OAuthClient $client): Response
    {
        $presented = self::str($request->body, 'refresh_token');

        $grant = $this->tokens->consumeRefresh($presented);
        if ($grant === null) {
            $lineage = $this->tokens->revokedLineageFor($presented);
            if ($lineage !== null) {
                // An already-rotated refresh token has come back. Either the
                // client replayed it or somebody else has a copy; both warrant
                // cutting the whole family rather than guessing which.
                $revoked = $this->tokens->revokeForCode($lineage);
                $this->refuse('oauth.token.refresh_replayed', [
                    'client_id'      => $client->clientId,
                    'code_id'        => $lineage,
                    'tokens_revoked' => $revoked,
                ]);
            } else {
                $this->refuse('oauth.token.refresh_unusable', ['client_id' => $client->clientId]);
            }

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The refresh token is not valid.');
        }

        if (!hash_equals($grant->clientId, $client->clientId)) {
            $this->refuse('oauth.token.refresh_client_mismatch', ['client_id' => $client->clientId]);

            return $this->tokenError(400, OAuthError::INVALID_GRANT, 'The refresh token is not valid.');
        }

        // A client may narrow its grant on refresh (RFC 6749 §6) but never
        // widen it. An explicit `scope` that is not a subset is a refusal, not a
        // silent narrowing — a client that thinks it holds a capability it does
        // not is worse off than one that got an error.
        $scopes  = $grant->scopes;
        $wanted  = self::str($request->body, 'scope');
        if ($wanted !== '') {
            $narrowed = OAuthScopes::parse($wanted);
            if ($narrowed === [] || !self::isSubset($narrowed, $grant->scopes)) {
                return $this->tokenError(
                    400,
                    OAuthError::INVALID_SCOPE,
                    'A refresh may narrow the granted scopes but not widen them.',
                );
            }
            $scopes = $narrowed;
        }

        if (!$client->permits($scopes)) {
            // The client's registration was narrowed since the grant was made.
            return $this->tokenError(
                400,
                OAuthError::INVALID_SCOPE,
                'This application is no longer registered for the scopes on this grant.',
            );
        }

        $issued = $this->tokens->issue($grant->clientId, $grant->userId, $scopes, $grant->codeId);

        return $this->secure((new Response())->json($issued, 200));
    }

    /**
     * Authenticate the calling client.
     *
     * A confidential client MUST present a matching secret, by HTTP Basic
     * (RFC 6749 §2.3.1's preferred form) or in the request body. A public
     * client presents only its `client_id` — its assurance comes from PKCE,
     * which is mandatory here for both kinds.
     *
     * Returns null for every failure, so the caller emits one `invalid_client`
     * that cannot be used to enumerate which client ids exist.
     *
     * @param Request $request Token request.
     */
    private function authenticateClient(Request $request): ?OAuthClient
    {
        $basic = self::basicCredentials($request);

        $clientId = $basic['id'] ?? self::str($request->body, 'client_id');
        $secret   = $basic['secret'] ?? self::str($request->body, 'client_secret');

        $client = $this->clients->find($clientId);
        if ($client === null) {
            $this->refuse('oauth.token.unknown_client', ['client_id' => $clientId]);

            return null;
        }

        if ($client->requiresSecret() && !$client->verifySecret($secret)) {
            $this->refuse('oauth.token.bad_client_secret', ['client_id' => $clientId]);

            return null;
        }

        return $client;
    }

    /**
     * Decode an `Authorization: Basic` header into client credentials.
     *
     * @param Request $request Token request.
     *
     * @return array{id?: string, secret?: string} Empty when the header is
     *         absent or unusable.
     */
    private static function basicCredentials(Request $request): array
    {
        // Each guard is its own `if` rather than one `||` chain: Psalm does not
        // narrow `?string` / `string|false` through a short-circuited `||`, so
        // the combined form leaves `substr()` and `explode()` reading a value
        // the analyser still believes may be null or false.
        $header = $request->getHeader('Authorization');
        if ($header === null) {
            return [];
        }
        if (!str_starts_with($header, 'Basic ')) {
            return [];
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false) {
            return [];
        }
        if (!str_contains($decoded, ':')) {
            return [];
        }

        $parts = explode(':', $decoded, 2);
        if (!isset($parts[0], $parts[1]) || $parts[0] === '') {
            return [];
        }

        return ['id' => $parts[0], 'secret' => $parts[1]];
    }

    /**
     * Whether every member of `$subset` appears in `$superset`.
     *
     * An empty `$subset` returns FALSE. "Requested nothing recognised" must not
     * read as "requested nothing forbidden" — the vacuous-true reading is the
     * fail-open one, and a `foreach` with no guard falls into it.
     *
     * @param list<string> $subset   Candidate narrower set.
     * @param list<string> $superset The grant's current scopes.
     */
    private static function isSubset(array $subset, array $superset): bool
    {
        if ($subset === []) {
            return false;
        }

        foreach ($subset as $scope) {
            if (!in_array($scope, $superset, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append query parameters to a URI that may already carry some.
     *
     * @param string                $uri    Base URI (already proven registered).
     * @param array<string, string> $params Parameters to append.
     */
    private static function appendQuery(string $uri, array $params): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri . $separator . http_build_query($params);
    }

    /**
     * Redirect back to the client with an RFC 6749 §4.1.2.1 error.
     *
     * Only ever called with a `$redirectUri` that has already been proven to be
     * one the client registered.
     *
     * @param string      $redirectUri Registered redirect URI.
     * @param string      $error       An {@see OAuthError} constant.
     * @param string      $description Human-readable, non-diagnostic detail.
     * @param string|null $state       Client `state`, echoed verbatim.
     */
    private function redirectError(string $redirectUri, string $error, string $description, ?string $state): Response
    {
        $this->refuse('oauth.authorize.' . $error, ['redirect_uri' => $redirectUri]);

        $params = ['error' => $error, 'error_description' => $description];
        if ($state !== null) {
            $params['state'] = $state;
        }

        return $this->secure((new Response())->redirect(self::appendQuery($redirectUri, $params), 302));
    }

    /**
     * A terminal HTML failure that must not redirect.
     *
     * @param int    $status  HTTP status.
     * @param string $heading Short title.
     * @param string $detail  One-sentence explanation.
     */
    private function errorPage(int $status, string $heading, string $detail): Response
    {
        return $this->secure((new Response())->html(ConsentScreen::error($heading, $detail), $status));
    }

    /**
     * An RFC 6749 §5.2 token-endpoint error body.
     *
     * @param int    $status      HTTP status (401 for `invalid_client`, else 400).
     * @param string $error       An {@see OAuthError} constant.
     * @param string $description Human-readable, non-diagnostic detail.
     */
    private function tokenError(int $status, string $error, string $description): Response
    {
        $response = (new Response())->json(
            ['error' => $error, 'error_description' => $description],
            $status,
        );

        if ($error === OAuthError::INVALID_CLIENT) {
            $response = $response->header('WWW-Authenticate', 'Basic realm="phlix-hub"');
        }

        return $this->secure($response);
    }

    /**
     * Apply the headers every response from this controller carries.
     *
     * `no-store` because responses here contain (or lead to) credentials, and a
     * cached authorization code or token is one a later visitor to the same
     * browser could replay. The framing headers keep the consent screen out of
     * an `<iframe>`, which is how a user is made to click "Allow" on something
     * they cannot see.
     *
     * @param Response $response Response to harden.
     */
    private function secure(Response $response): Response
    {
        return $response
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache')
            ->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "frame-ancestors 'none'");
    }

    /**
     * Record a refusal to the audit trail and the auth log.
     *
     * @param string               $reason  Stable machine-readable reason slug.
     * @param array<string, mixed> $context Structured detail for operators only.
     */
    private function refuse(string $reason, array $context): void
    {
        $this->log->warning($reason, $context);
        $this->audit->logFailedAuth($reason, $context);
    }

    /**
     * Read a string parameter, treating absent and non-string as `''`.
     *
     * @param array<string, mixed> $source Query or body array.
     * @param string               $key    Parameter name.
     */
    private static function str(array $source, string $key): string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Read an optional string parameter, distinguishing absent from empty.
     *
     * `state` needs this: RFC 6749 says it is echoed back only if it was
     * supplied, and an absent `state` must not become `state=`.
     *
     * @param array<string, mixed> $source Query or body array.
     * @param string               $key    Parameter name.
     */
    private static function strOrNull(array $source, string $key): ?string
    {
        /** @var mixed $value */
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
