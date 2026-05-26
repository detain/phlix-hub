<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use Phlix\Hub\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see UserRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 *
 * @covers \Phlix\Hub\Auth\UserRepository
 */
final class UserRepositoryTest extends TestCase
{
    public function testFindByIdReturnsUserRecord(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with('SELECT * FROM users WHERE id = :id', ['id' => 'u-1'])
            ->willReturn([['id' => 'u-1', 'username' => 'alice', 'email' => 'a@example.com']]);

        $repo = new UserRepository($db);
        $row = $repo->findById('u-1');

        self::assertIsArray($row);
        self::assertSame('alice', $row['username']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertNull($repo->findById('unknown'));
    }

    public function testFindByUsernameReturnsRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'u-2', 'username' => 'bob']]);

        $repo = new UserRepository($db);
        $row = $repo->findByUsername('bob');
        self::assertIsArray($row);
        self::assertSame('u-2', $row['id']);
    }

    public function testFindByEmailReturnsUserRecord(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'u-3', 'email' => 'c@example.com']]);

        $repo = new UserRepository($db);
        $row = $repo->findByEmail('c@example.com');
        self::assertIsArray($row);
        self::assertSame('u-3', $row['id']);
    }

    public function testFindByEmailReturnsNullWhenMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertNull($repo->findByEmail('nobody@example.com'));
    }

    public function testFindAdminByIdReturnsRowWhenAdmin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'u-4', 'is_admin' => 1]]);

        $repo = new UserRepository($db);
        self::assertNotNull($repo->findAdminById('u-4'));
    }

    public function testFindAdminByIdReturnsNullWhenNotAdmin(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertNull($repo->findAdminById('u-5'));
    }

    public function testCountUsersExtractsScalar(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['c' => 5]]);

        $repo = new UserRepository($db);
        self::assertSame(5, $repo->countUsers());
    }

    public function testCountUsersReturnsZeroForEmptyResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertSame(0, $repo->countUsers());
    }

    public function testSetAdminEmitsExpectedUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with('UPDATE users SET is_admin = :flag WHERE id = :id', ['flag' => 1, 'id' => 'u-9']);

        $repo = new UserRepository($db);
        $repo->setAdmin('u-9', true);
    }

    public function testInsertUserReturnsInsertedId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO users'),
                self::callback(static function ($bindings): bool {
                    return is_array($bindings)
                        && isset($bindings['username'])
                        && $bindings['username'] === 'alice'
                        && isset($bindings['email'])
                        && $bindings['email'] === 'a@example.com'
                        && isset($bindings['pwd'])
                        && is_string($bindings['pwd'])
                        && str_starts_with($bindings['pwd'], '$argon2id$');
                }),
            );

        $repo = new UserRepository($db);
        $id = $repo->create([
            'username' => 'alice',
            'email'    => 'a@example.com',
            'password' => 'correct-horse-battery',
        ]);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testInsertUserUsesDisplayNameWhenProvided(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO users'),
                self::callback(static function ($bindings): bool {
                    return is_array($bindings) && ($bindings['display'] ?? '') === 'Alice Liddell';
                }),
            );

        $repo = new UserRepository($db);
        $repo->create([
            'username'     => 'alice',
            'email'        => 'a@example.com',
            'password'     => 'correct-horse-battery',
            'display_name' => 'Alice Liddell',
        ]);
    }

    public function testInsertUserDuplicateEmailThrows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new \RuntimeException('Duplicate entry'));

        $repo = new UserRepository($db);
        $this->expectException(\RuntimeException::class);
        $repo->create([
            'username' => 'dup',
            'email'    => 'dup@example.com',
            'password' => 'correct-horse-battery',
        ]);
    }

    public function testVerifyPasswordTrueWhenMatching(): void
    {
        $hash = password_hash('hunter2!!', PASSWORD_ARGON2ID);
        self::assertIsString($hash);
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'u-1', 'password_hash' => $hash]]);

        $repo = new UserRepository($db);
        self::assertTrue($repo->verifyPassword('u-1', 'hunter2!!'));
    }

    public function testVerifyPasswordFalseWhenWrong(): void
    {
        $hash = password_hash('hunter2!!', PASSWORD_ARGON2ID);
        self::assertIsString($hash);
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'u-1', 'password_hash' => $hash]]);

        $repo = new UserRepository($db);
        self::assertFalse($repo->verifyPassword('u-1', 'wrong-password'));
    }

    public function testVerifyPasswordFalseWhenUserMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertFalse($repo->verifyPassword('nobody', 'anything'));
    }

    public function testEmailExistsTrueForKnown(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['1' => 1]]);

        $repo = new UserRepository($db);
        self::assertTrue($repo->emailExists('a@example.com'));
    }

    public function testEmailExistsFalseForUnknown(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertFalse($repo->emailExists('nobody@example.com'));
    }

    public function testUsernameExistsTrueForKnown(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['1' => 1]]);

        $repo = new UserRepository($db);
        self::assertTrue($repo->usernameExists('alice'));
    }

    public function testUsernameExistsFalseForUnknown(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertFalse($repo->usernameExists('ghost'));
    }

    public function testUpdateLastLoginEmitsUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('UPDATE users SET updated_at'), ['id' => 'u-7']);

        $repo = new UserRepository($db);
        $repo->updateLastLogin('u-7');
    }

    public function testGenerateUuidProducesV4Shape(): void
    {
        $uuid = UserRepository::generateUuid();
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }
}
