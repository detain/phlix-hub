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

    public function testFindAllSelectsPublicColumnsOnly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT id, username, email, is_admin, created_at, updated_at'))
            ->willReturn([
                ['id' => 'u-1', 'username' => 'alice', 'is_admin' => 1],
                ['id' => 'u-2', 'username' => 'bob', 'is_admin' => 0],
            ]);

        $repo = new UserRepository($db);
        $rows = $repo->findAll();

        self::assertCount(2, $rows);
        self::assertSame('alice', $rows[0]['username']);
        self::assertSame('u-2', $rows[1]['id']);
    }

    public function testFindAllReturnsEmptyWhenQueryNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(false);

        $repo = new UserRepository($db);
        self::assertSame([], $repo->findAll());
    }

    public function testUpdateBuildsNamedPlaceholderQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(static function (string $sql): bool {
                    return str_contains($sql, 'UPDATE users SET')
                        && str_contains($sql, 'username = :username')
                        && str_contains($sql, 'email = :email')
                        && str_contains($sql, 'WHERE id = :id');
                }),
                self::callback(static function (array $params): bool {
                    return ($params['username'] ?? null) === 'newname'
                        && ($params['email'] ?? null) === 'new@example.com'
                        && ($params['id'] ?? null) === 'u-1';
                }),
            );

        $repo = new UserRepository($db);
        $repo->update('u-1', ['username' => 'newname', 'email' => 'new@example.com']);
    }

    public function testUpdateHashesPlainPassword(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('password_hash = :password_hash'),
                self::callback(static function (array $params): bool {
                    $hash = $params['password_hash'] ?? null;
                    return is_string($hash)
                        && str_starts_with($hash, '$argon2id$')
                        && ($params['id'] ?? null) === 'u-1';
                }),
            );

        $repo = new UserRepository($db);
        $repo->update('u-1', ['password' => 'correct-horse-battery']);
    }

    public function testUpdateWithNoRecognisedKeysIssuesNoQuery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');

        $repo = new UserRepository($db);
        $repo->update('u-1', ['unknown_column' => 'ignored']);
    }

    public function testDeleteEmitsNamedPlaceholderDelete(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with('DELETE FROM users WHERE id = :id', ['id' => 'u-9']);

        $repo = new UserRepository($db);
        $repo->delete('u-9');
    }

    public function testCountAdminsExtractsScalar(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('WHERE is_admin = 1'))
            ->willReturn([['c' => 3]]);

        $repo = new UserRepository($db);
        self::assertSame(3, $repo->countAdmins());
    }

    public function testCountAdminsReturnsZeroForEmptyResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new UserRepository($db);
        self::assertSame(0, $repo->countAdmins());
    }

    public function testCountAdminsReturnsZeroWhenFirstRowNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(['not-a-row']);

        $repo = new UserRepository($db);
        self::assertSame(0, $repo->countAdmins());
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
