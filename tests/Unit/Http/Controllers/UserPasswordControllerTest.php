<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\CurrentPasswordInvalidException;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Http\Controllers\UserPasswordController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see UserPasswordController} (M-HUB-07).
 *
 * The controller is the frame mapper: every failure path is asserted against
 * its registered contract code (`auth.required`, `auth.missing_credentials`,
 * `auth.invalid_credentials`, `validation_failed`, `rate_limited`), the legacy
 * top-level `message` twin the SPA still reads, and the M2 law that the human
 * sentence rides `error`. Success is the bare `{message}` shape of the admin
 * reset sibling (AdminUserController::resetPassword).
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class UserPasswordControllerTest extends TestCase
{
    use DecodedJsonAssertions;

    private function controller(AuthManager $auth): UserPasswordController
    {
        return new UserPasswordController($auth);
    }

    private function authMgr(): AuthManager&MockObject
    {
        return $this->createMock(AuthManager::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, string $userId = 'u-1'): Request
    {
        $request = new Request();
        $request->userId = $userId;
        $request->body = $body;
        return $request;
    }

    public function testDefensiveUnauthorizedWhenUserIdMissing(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('changePassword');

        $response = $this->controller($auth)->changePassword(
            $this->request(['current_password' => 'a', 'new_password' => 'b'], ''),
        );

        self::assertSame(401, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame('auth.required', $payload['code']);
        self::assertSame('Unauthorized', $payload['error']);
    }

    public function testMissingFieldsReturn400AuthMissingCredentials(): void
    {
        $auth = $this->authMgr();
        $auth->expects(self::never())->method('changePassword');

        $response = $this->controller($auth)->changePassword(
            $this->request(['current_password' => 'only-this']),
        );

        self::assertSame(400, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame('auth.missing_credentials', $payload['code']);
        // M2: the human sentence rides `error`; the legacy twin rides `message`.
        self::assertSame('current_password and new_password are required', $payload['error']);
        self::assertSame($payload['error'], $payload['message']);
    }

    public function testWrongCurrentPasswordReturns401InvalidCredentials(): void
    {
        $auth = $this->authMgr();
        $auth->method('changePassword')
            ->willThrowException(new CurrentPasswordInvalidException('Current password is incorrect'));

        $response = $this->controller($auth)->changePassword(
            $this->request(['current_password' => 'wrong', 'new_password' => 'brand-new-secret']),
        );

        self::assertSame(401, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame('auth.invalid_credentials', $payload['code']);
        self::assertSame('Current password is incorrect', $payload['error']);
        self::assertSame($payload['error'], $payload['message']);
    }

    public function testPolicyViolationReturns400ValidationFailed(): void
    {
        $auth = $this->authMgr();
        $auth->method('changePassword')
            ->willThrowException(new InvalidArgumentException('Password must be at least 8 characters'));

        $response = $this->controller($auth)->changePassword(
            $this->request(['current_password' => 'current-secret', 'new_password' => 'short']),
        );

        self::assertSame(400, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame('validation_failed', $payload['code']);
        self::assertSame('Password must be at least 8 characters', $payload['error']);
    }

    public function testExhaustedBudgetReturns429WithRetryAfter(): void
    {
        $auth = $this->authMgr();
        $auth->method('changePassword')
            ->willThrowException(new RateLimitException(resetAt: time() + 60, remaining: 0));

        $response = $this->controller($auth)->changePassword(
            $this->request(['current_password' => 'current-secret', 'new_password' => 'brand-new-secret']),
        );

        self::assertSame(429, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame('rate_limited', $payload['code']);
        self::assertArrayHasKey('Retry-After', $response->headers);
    }

    public function testSuccessReturnsMessageEnvelopeForTheScopedUser(): void
    {
        $auth = $this->authMgr();
        // The proof/policy arguments are forwarded positionally; the id is
        // the caller's own — never a body-supplied id (no horizontal path).
        $auth->expects(self::once())->method('changePassword')
            ->with('u-1', 'current-secret', 'brand-new-secret', self::anything());

        $response = $this->controller($auth)->changePassword(
            $this->request([
                'current_password' => 'current-secret',
                'new_password' => 'brand-new-secret',
                'user_id' => 'u-VICTIM',
            ]),
        );

        self::assertSame(200, $response->statusCode);
        $payload = self::arrayNode(json_decode($response->body, true));
        self::assertSame(['message' => 'Password updated successfully'], $payload);
    }
}
