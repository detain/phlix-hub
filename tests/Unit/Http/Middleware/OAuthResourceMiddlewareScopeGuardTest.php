<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use InvalidArgumentException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Middleware\OAuthResourceMiddleware;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * S286 — the fail-CLOSED half of {@see OAuthResourceMiddleware}: it is
 * impossible to construct one that requires no scope.
 *
 * ## Why this is a separate suite from the 200/401/403 one
 *
 * The empty-allow-list defect is not a request-time branch, it is a
 * CONSTRUCTION-time refusal, and it has to be: this estate has shipped the
 * "empty means permissive" shape twice — a rating cap built from `[]` emitted no
 * `WHERE` clause and authorised everything, and S261 minted an MCP token with no
 * `scopes` field that received the WRITE scope. A middleware that treated `[]`
 * as "no scope required" would be that defect a third time, on a surface any
 * third-party client holding any token could reach.
 *
 * Throwing in the constructor means such an object never exists, so there is no
 * runtime path to test and no dead defensive branch to pin. The evidence is that
 * the throw happens, and that it happens for a TYPO as well as for an empty
 * array — because `OAuthScopes::fromArray()` drops anything unrecognised, a
 * mis-typed required scope normalises to `[]` and would otherwise gate the route
 * on nothing at all, which is strictly worse than the misconfiguration it came
 * from.
 *
 * Nothing here touches a database: the constructor refuses before any
 * collaborator is used, so the {@see OAuthTokenService} is reflection-built. If
 * a future change made the constructor reach it, these tests would raise an
 * `Error` on the uninitialised connection rather than passing quietly.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class OAuthResourceMiddlewareScopeGuardTest extends TestCase
{
    /**
     * Requirement lists that must all be refused, and why each one matters.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function unusableRequirementProvider(): iterable
    {
        yield 'an empty array' => [[]];
        yield 'a single unknown scope' => [['phlix:everything']];
        yield 'a plausible typo of a real scope' => [['phlix:profile:reed']];
        yield 'the right words in the wrong shape' => [['profile:read']];
        yield 'an MCP scope with a stray suffix' => [['mcp:library:read:all']];
        yield 'whitespace only' => [['   ']];
        yield 'an empty string' => [['']];
        yield 'several unknowns at once' => [['alexa:library:read', 'phlix:profile:write']];
    }

    /**
     * @param list<string> $requirement
     */
    #[DataProvider('unusableRequirementProvider')]
    public function testConstructionIsRefusedWhenNoRecognisedScopeIsRequired(array $requirement): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one scope this build recognises/');

        self::middleware($requirement);
    }

    /**
     * 🔴 The CONTROL. Every case above asserts a throw, and a constructor that
     * threw unconditionally would satisfy all of them — "it refused" is not
     * evidence that it refused for the stated reason. This proves the same
     * constructor accepts a requirement that differs from the refused ones in
     * exactly one respect: the scope is one this build knows.
     */
    public function testConstructionSucceedsForEveryScopeInTheVocabulary(): void
    {
        foreach (OAuthScopes::all() as $scope) {
            $middleware = self::middleware([$scope]);

            self::assertSame(
                [$scope],
                $middleware->requiredScopes(),
                $scope . ' is in OAuthScopes::all() but the middleware would not accept it',
            );
        }
    }

    /**
     * A requirement is NORMALISED, not stored verbatim: unknown members are
     * dropped and the survivors come back in `OAuthScopes::all()` order.
     *
     * The mixed case is the interesting one — a list that is PARTLY unknown is
     * accepted (there is still a real requirement to enforce) but the unknown
     * member must not survive into the enforced list, where it could never be
     * satisfied by any token and would turn the route into a permanent 403.
     */
    public function testAnUnknownMemberIsDroppedWhileAKnownOneSurvives(): void
    {
        $middleware = self::middleware([
            'phlix:not:a:scope',
            McpScopes::LIBRARY_READ,
            OAuthScopes::PROFILE_READ,
            McpScopes::LIBRARY_READ,
        ]);

        self::assertSame(
            [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            $middleware->requiredScopes(),
            'requiredScopes() must be de-duplicated, in OAuthScopes::all() order, '
            . 'and free of anything this build does not recognise',
        );
    }

    /**
     * The wiring the hub actually ships: `GET /oauth/userinfo` demands
     * `phlix:profile:read` and nothing else.
     *
     * Restated here as a literal rather than read from the container, because
     * the container binding is what this pins — a check derived from its subject
     * cannot see the subject change. The container-resolved counterpart lives in
     * {@see \Phlix\Hub\Tests\Integration\OAuth\OAuthResourceServerTest}.
     */
    public function testTheShippedRequirementIsExactlyTheProfileReadScope(): void
    {
        self::assertSame('phlix:profile:read', OAuthScopes::PROFILE_READ);
        self::assertSame(
            ['phlix:profile:read'],
            self::middleware([OAuthScopes::PROFILE_READ])->requiredScopes(),
        );
    }

    /**
     * @param list<string> $requiredScopes
     */
    private static function middleware(array $requiredScopes): OAuthResourceMiddleware
    {
        return new OAuthResourceMiddleware(
            (new ReflectionClass(OAuthTokenService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(UserRepository::class))->newInstanceWithoutConstructor(),
            $requiredScopes,
        );
    }
}
