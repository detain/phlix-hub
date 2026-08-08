<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use InvalidArgumentException;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\OAuthClient;
use Phlix\Hub\OAuth\OAuthScopes;
use PHPUnit\Framework\TestCase;

use function hash;

/**
 * Unit tests for {@see OAuthClient}, with the redirect-URI matcher as the
 * centrepiece.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 *
 * @covers \Phlix\Hub\OAuth\OAuthClient
 */
final class OAuthClientTest extends TestCase
{
    private const REDIRECT = 'https://layla.amazon.com/api/skill/link/M2ABCDEFG';

    /**
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     */
    private static function client(
        array $redirectUris = [self::REDIRECT],
        array $scopes = [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
        ?string $secret = null,
    ): OAuthClient {
        return OAuthClient::create(
            'row-id',
            'alexa-skill',
            'Phlix for Alexa',
            $redirectUris,
            $scopes,
            $secret !== null,
            $secret !== null ? hash('sha256', $secret) : null,
        );
    }

    // ---- redirect_uri: exact, and only exact --------------------------------

    public function test_an_exactly_registered_redirect_uri_is_accepted(): void
    {
        self::assertTrue(self::client()->allowsRedirectUri(self::REDIRECT));
    }

    /**
     * Every near-miss below is a real, documented code-exfiltration or
     * open-redirect vector. Each one is what a prefix, substring, origin or
     * trailing-slash-tolerant matcher would let through, and each sits beside
     * the exact-match control above.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function nearMissRedirectUriProvider(): iterable
    {
        yield 'trailing slash appended'   => [self::REDIRECT . '/'];
        yield 'path segment appended'     => [self::REDIRECT . '/evil'];
        yield 'query appended'            => [self::REDIRECT . '?next=https://evil.example'];
        yield 'fragment appended'         => [self::REDIRECT . '#evil'];
        yield 'suffix makes a new host'   => ['https://layla.amazon.com.evil.example/api/skill/link/M2ABCDEFG'];
        yield 'registered value is a prefix of the attacker host'
            => ['https://layla.amazon.com/api/skill/link/M2ABCDEFGH'];
        yield 'registered value embedded in a query'
            => ['https://evil.example/?u=' . self::REDIRECT];
        yield 'registered value embedded in a path'
            => ['https://evil.example/' . self::REDIRECT];
        yield 'scheme downgraded'         => ['http://layla.amazon.com/api/skill/link/M2ABCDEFG'];
        yield 'host case changed'         => ['https://LAYLA.amazon.com/api/skill/link/M2ABCDEFG'];
        yield 'path case changed'         => ['https://layla.amazon.com/api/skill/link/m2abcdefg'];
        yield 'userinfo injected'         => ['https://layla.amazon.com@evil.example/api/skill/link/M2ABCDEFG'];
        yield 'traversal'                 => ['https://layla.amazon.com/api/skill/link/M2ABCDEFG/../../evil'];
        yield 'truncated'                 => ['https://layla.amazon.com/api/skill/link/'];
        yield 'origin only'               => ['https://layla.amazon.com'];
        yield 'empty'                     => [''];
        yield 'whitespace padded'         => [' ' . self::REDIRECT];
        yield 'newline appended'          => [self::REDIRECT . "\n"];
        yield 'null byte appended'        => [self::REDIRECT . "\0"];
    }

    /**
     * @dataProvider nearMissRedirectUriProvider
     */
    public function test_a_near_miss_redirect_uri_is_refused(string $candidate): void
    {
        $client = self::client();

        self::assertFalse(
            $client->allowsRedirectUri($candidate),
            'redirect_uri matching must be whole-string; ' . var_export($candidate, true) . ' got through',
        );

        // The succeeding control, in the SAME test: this proves the matcher is
        // not simply returning false for everything.
        self::assertTrue($client->allowsRedirectUri(self::REDIRECT));
    }

    public function test_each_of_several_registered_uris_matches_and_nothing_between_them_does(): void
    {
        $a = 'https://alexa.amazon.co.jp/api/skill/link/M2ABCDEFG';
        $b = 'https://layla.amazon.com/api/skill/link/M2ABCDEFG';
        $c = 'https://pitangui.amazon.com/api/skill/link/M2ABCDEFG';

        $client = self::client([$a, $b, $c]);

        self::assertTrue($client->allowsRedirectUri($a));
        self::assertTrue($client->allowsRedirectUri($b));
        self::assertTrue($client->allowsRedirectUri($c));
        self::assertFalse($client->allowsRedirectUri('https://amazon.com/api/skill/link/M2ABCDEFG'));
        self::assertFalse($client->allowsRedirectUri('https://layla.amazon.com/api/skill/link/'));
    }

    // ---- the empty-allow-list fail-open, refused at construction ------------

    public function test_a_client_with_no_registered_redirect_uris_cannot_exist(): void
    {
        // The failure this refuses to allow: an empty list means the loop in
        // allowsRedirectUri() has nothing to compare against. This estate has
        // already shipped a rating cap built from [] that emitted no WHERE
        // clause and authorised everything.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no registered redirect URIs');

        self::client([]);
    }

    public function test_a_client_whose_redirect_uris_are_all_blank_cannot_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no registered redirect URIs');

        self::client(['', '']);
    }

    public function test_a_client_with_no_recognised_scopes_cannot_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no recognised allowed scopes');

        self::client([self::REDIRECT], ['admin:*', 'nonsense']);
    }

    public function test_a_client_with_an_empty_scope_list_cannot_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no recognised allowed scopes');

        self::client([self::REDIRECT], []);
    }

    public function test_a_confidential_client_with_no_secret_hash_cannot_exist(): void
    {
        // It could never be authenticated, so it would be a client that is
        // permanently unusable — or, worse, one a lenient token endpoint waves
        // through.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stores no secret hash');

        OAuthClient::create('row-id', 'c', 'C', [self::REDIRECT], [OAuthScopes::PROFILE_READ], true, null);
    }

    public function test_a_client_with_a_blank_client_id_cannot_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client_id must not be empty');

        OAuthClient::create('row-id', '', 'C', [self::REDIRECT], [OAuthScopes::PROFILE_READ], false, null);
    }

    public function test_a_valid_client_is_built_and_normalised(): void
    {
        // Control for every refusal above: the same factory does succeed.
        $client = self::client(
            [self::REDIRECT, '', '  '],
            [McpScopes::LIBRARY_READ, 'admin:*', OAuthScopes::PROFILE_READ],
        );

        self::assertSame('alexa-skill', $client->clientId);
        self::assertSame([self::REDIRECT], $client->redirectUris, 'blank URIs are dropped, not stored');
        self::assertSame(
            [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            $client->allowedScopes,
            'unknown scopes are dropped and the order is normalised',
        );
        self::assertFalse($client->requiresSecret());
    }

    // ---- scope ceiling ------------------------------------------------------

    public function test_permits_accepts_a_subset_and_refuses_anything_outside_the_ceiling(): void
    {
        $client = self::client([self::REDIRECT], [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ]);

        // Controls.
        self::assertTrue($client->permits([OAuthScopes::PROFILE_READ]));
        self::assertTrue($client->permits([OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ]));

        // Outside the ceiling.
        self::assertFalse($client->permits([McpScopes::PLAYBACK_CONTROL]));
        self::assertFalse($client->permits([OAuthScopes::PROFILE_READ, McpScopes::PLAYBACK_CONTROL]));
    }

    public function test_permits_refuses_an_empty_scope_list_rather_than_passing_it_vacuously(): void
    {
        // A `foreach` over an empty list falls straight through to `return
        // true`. "Requested nothing recognised" must not read as "requested
        // nothing forbidden" — that is the fail-open reading.
        self::assertFalse(self::client()->permits([]));

        // Control.
        self::assertTrue(self::client()->permits([OAuthScopes::PROFILE_READ]));
    }

    // ---- client secret ------------------------------------------------------

    public function test_a_confidential_client_verifies_only_the_right_secret(): void
    {
        $client = self::client([self::REDIRECT], [OAuthScopes::PROFILE_READ], 'super-secret-value');

        self::assertTrue($client->requiresSecret());
        self::assertTrue($client->verifySecret('super-secret-value'));

        self::assertFalse($client->verifySecret('super-secret-valu'));
        self::assertFalse($client->verifySecret('super-secret-value '));
        self::assertFalse($client->verifySecret('SUPER-SECRET-VALUE'));
        self::assertFalse($client->verifySecret(''));
        self::assertFalse(
            $client->verifySecret(hash('sha256', 'super-secret-value')),
            'presenting the stored HASH as the secret must not authenticate',
        );
    }

    public function test_a_public_client_never_verifies_a_secret(): void
    {
        $client = self::client();

        self::assertFalse($client->requiresSecret());
        self::assertFalse($client->verifySecret('anything'));
        self::assertFalse($client->verifySecret(''));
    }
}
