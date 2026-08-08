<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;
use Phlix\Hub\OAuth\PendingAuthorization;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function hash;
use function is_string;
use function str_contains;
use function strlen;
use function time;

/**
 * Every secret this subsystem persists is stored as a SHA-256 digest and never
 * as plaintext (S92), following {@see \Phlix\Hub\Hub\ClientRelayTokenService}
 * and {@see \Phlix\Hub\Mcp\McpTokenService}.
 *
 * There are FIVE secrets — the consent ticket, the authorization code, the
 * access token, the refresh token and the client secret — and a test that
 * checked one of them would not notice a fourth store that forgot. Each is
 * asserted separately, by capturing the actual bind parameters that reach the
 * database and searching ALL of them for the plaintext.
 *
 * ⚠ The assertion is not "a `token_hash` key was present". It is "the plaintext
 * appears in NO parameter", which is what a mistake would actually look like:
 * an extra column, a debug field, a value logged alongside its hash.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 *
 * @covers \Phlix\Hub\OAuth\AuthorizationCodeService
 * @covers \Phlix\Hub\OAuth\ConsentTicketService
 * @covers \Phlix\Hub\OAuth\OAuthClientRegistry
 * @covers \Phlix\Hub\OAuth\OAuthTokenService
 */
final class TokenStorageHashingTest extends TestCase
{
    /**
     * Assert that `$plaintext` reaches the database only as its SHA-256 digest.
     *
     * @param array<int, array<string, mixed>> $captured Every parameter set seen.
     */
    private static function assertOnlyTheHashWasStored(string $plaintext, array $captured, string $label): void
    {
        self::assertNotSame('', $plaintext, $label . ': nothing was generated, so nothing was tested');
        self::assertNotSame([], $captured, $label . ': no INSERT reached the database');

        $hash      = hash('sha256', $plaintext);
        $sawTheHash = false;

        foreach ($captured as $params) {
            /** @var mixed $value */
            foreach ($params as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                self::assertNotSame(
                    $plaintext,
                    $value,
                    $label . ': the plaintext was written to the `' . $key . '` column',
                );
                self::assertFalse(
                    str_contains($value, $plaintext),
                    $label . ': the `' . $key . '` column embeds the plaintext',
                );
                if ($value === $hash) {
                    $sawTheHash = true;
                }
            }
        }

        // Anti-vacuity: without this, a service that persisted NOTHING at all
        // would sail through every assertion above.
        self::assertTrue($sawTheHash, $label . ': the SHA-256 digest was never written, so nothing was stored');
    }

    /**
     * @param array<int, array<string, mixed>> $captured
     */
    private function recordingConnection(array &$captured): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$captured): array {
                unset($sql);
                $captured[] = $params;

                return [];
            },
        );

        /** @var Connection $db */
        return $db;
    }

    public function test_a_consent_ticket_is_stored_only_as_a_hash(): void
    {
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $service  = new ConsentTicketService($this->recordingConnection($captured));

        $issued = $service->issue(new PendingAuthorization(
            'user-1',
            'client-1',
            'https://example.test/cb',
            [OAuthScopes::PROFILE_READ],
            'st4te',
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        ));

        self::assertOnlyTheHashWasStored($issued['ticket'], $captured, 'consent ticket');
        self::assertSame(64, strlen($issued['ticket']), '256 bits of CSPRNG material');
    }

    public function test_an_authorization_code_is_stored_only_as_a_hash(): void
    {
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $service  = new AuthorizationCodeService($this->recordingConnection($captured));

        $minted = $service->mint(
            'client-1',
            'user-1',
            'https://example.test/cb',
            [OAuthScopes::PROFILE_READ],
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        );

        self::assertOnlyTheHashWasStored($minted['code'], $captured, 'authorization code');
    }

    public function test_both_issued_tokens_are_stored_only_as_hashes(): void
    {
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $service  = new OAuthTokenService($this->recordingConnection($captured));

        $issued = $service->issue('client-1', 'user-1', [OAuthScopes::PROFILE_READ], 'code-1');

        self::assertOnlyTheHashWasStored($issued['access_token'], $captured, 'access token');
        self::assertOnlyTheHashWasStored($issued['refresh_token'], $captured, 'refresh token');

        // The two are distinct secrets, not the same value handed out twice.
        self::assertNotSame($issued['access_token'], $issued['refresh_token']);
        self::assertStringStartsWith(OAuthTokenService::ACCESS_TOKEN_PREFIX, $issued['access_token']);
        self::assertStringStartsWith(OAuthTokenService::REFRESH_TOKEN_PREFIX, $issued['refresh_token']);
    }

    public function test_a_client_secret_is_stored_only_as_a_hash(): void
    {
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $registry = new OAuthClientRegistry($this->recordingConnection($captured));

        $registry->register(
            'alexa-skill',
            'Phlix for Alexa',
            ['https://layla.amazon.com/api/skill/link/M2ABCDEFG'],
            [OAuthScopes::PROFILE_READ],
            'the-client-secret',
        );

        self::assertOnlyTheHashWasStored('the-client-secret', $captured, 'client secret');
    }

    public function test_the_token_response_reports_a_finite_lifetime(): void
    {
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $service  = new OAuthTokenService($this->recordingConnection($captured));

        $issued = $service->issue('client-1', 'user-1', [OAuthScopes::PROFILE_READ], null);

        self::assertSame('Bearer', $issued['token_type']);
        self::assertSame(OAuthTokenService::ACCESS_TTL_SECONDS, $issued['expires_in']);
        self::assertGreaterThan(0, $issued['expires_in'], 'a perpetual access token is not on offer');
        self::assertLessThanOrEqual(86400, $issued['expires_in'], 'an access token must be short-lived');
        self::assertSame(OAuthScopes::PROFILE_READ, $issued['scope']);
    }

    public function test_a_non_positive_ttl_falls_back_to_the_default_rather_than_zero(): void
    {
        // A zero or negative TTL configured by mistake must not produce a code
        // that has already expired (unusable) or one with no expiry at all.
        /** @var array<int, array<string, mixed>> $captured */
        $captured = [];
        $db       = $this->recordingConnection($captured);

        $before = time();

        self::assertSame(
            $before + AuthorizationCodeService::DEFAULT_TTL_SECONDS,
            (new AuthorizationCodeService($db, 0))->mint('c', 'u', 'https://e.test/cb', [OAuthScopes::PROFILE_READ], 'x')['expires_at'],
        );
        self::assertSame(
            $before + ConsentTicketService::DEFAULT_TTL_SECONDS,
            (new ConsentTicketService($db, -5))->issue(
                new PendingAuthorization('u', 'c', 'https://e.test/cb', [OAuthScopes::PROFILE_READ], null, 'x'),
            )['expires_at'],
        );
        self::assertSame(
            OAuthTokenService::ACCESS_TTL_SECONDS,
            (new OAuthTokenService($db, 0, 0))->issue('c', 'u', [OAuthScopes::PROFILE_READ], null)['expires_in'],
        );
    }

    public function test_the_authorization_code_lifetime_is_short(): void
    {
        // RFC 6749 §4.1.2 permits up to 10 minutes. The exchange is a
        // server-to-server call that happens within a second, so anything
        // approaching the ceiling is a window somebody could act inside.
        self::assertLessThanOrEqual(600, AuthorizationCodeService::DEFAULT_TTL_SECONDS);
        self::assertGreaterThan(0, AuthorizationCodeService::DEFAULT_TTL_SECONDS);
        self::assertLessThanOrEqual(120, AuthorizationCodeService::DEFAULT_TTL_SECONDS);
    }

    public function test_the_two_token_kinds_are_distinct_labels(): void
    {
        // `validateAccess()` filters on this column, so a collision between the
        // two would let a refresh token be presented as a bearer credential.
        self::assertNotSame(OAuthTokenService::KIND_ACCESS, OAuthTokenService::KIND_REFRESH);
        self::assertNotSame(OAuthTokenService::ACCESS_TOKEN_PREFIX, OAuthTokenService::REFRESH_TOKEN_PREFIX);
        self::assertFalse(
            OAuthTokenService::looksLikeAccessToken(OAuthTokenService::REFRESH_TOKEN_PREFIX . 'abc'),
        );
        self::assertTrue(
            OAuthTokenService::looksLikeAccessToken(OAuthTokenService::ACCESS_TOKEN_PREFIX . 'abc'),
        );
    }
}
