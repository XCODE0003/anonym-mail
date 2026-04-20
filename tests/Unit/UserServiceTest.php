<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\User\UserService;
use App\Domain\User\UserValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

class UserServiceTest extends TestCase
{
    private PDO $pdo;
    private Redis $redis;
    private UserService $service;

    protected function setUp(): void
    {
        // Create in-memory SQLite for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables
        $this->pdo->exec('
            CREATE TABLE domains (
                id INTEGER PRIMARY KEY,
                name TEXT UNIQUE,
                active INTEGER DEFAULT 1,
                allow_registration INTEGER DEFAULT 1
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                domain_id INTEGER,
                local_part TEXT,
                password_hash TEXT,
                smtp_blocked INTEGER DEFAULT 1,
                frozen INTEGER DEFAULT 0,
                delete_after TEXT,
                UNIQUE(domain_id, local_part)
            );
            CREATE TABLE reserved_names (
                local_part TEXT PRIMARY KEY
            );
        ');
        
        // Seed data
        $this->pdo->exec("INSERT INTO domains (id, name) VALUES (1, 'test.com')");
        $this->pdo->exec("INSERT INTO reserved_names (local_part) VALUES ('admin'), ('root'), ('postmaster')");
        
        // Mock Redis
        $this->redis = $this->createMock(Redis::class);
        
        $this->service = new UserService($this->pdo, $this->redis);
    }

    public function testValidUsernameAccepted(): void
    {
        $result = $this->service->register('validuser', 1, 'SecurePass123!');
        $this->assertTrue($result);
    }

    public function testUsernameTooShort(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('too short');
        
        $this->service->register('ab', 1, 'SecurePass123!');
    }

    public function testUsernameTooLong(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('too long');
        
        $this->service->register(str_repeat('a', 65), 1, 'SecurePass123!');
    }

    public function testUsernameInvalidCharacters(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('Invalid characters');
        
        $this->service->register('user@name', 1, 'SecurePass123!');
    }

    public function testUsernameReserved(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('reserved');
        
        $this->service->register('admin', 1, 'SecurePass123!');
    }

    public function testPasswordTooShort(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('at least 12 characters');
        
        $this->service->register('validuser', 1, 'Short1!');
    }

    public function testPasswordNoUppercase(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('uppercase');
        
        $this->service->register('validuser', 1, 'alllowercase123!');
    }

    public function testPasswordNoDigit(): void
    {
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('digit');
        
        $this->service->register('validuser', 1, 'NoDigitsHere!!');
    }

    public function testDuplicateUser(): void
    {
        $this->service->register('existinguser', 1, 'SecurePass123!');
        
        $this->expectException(UserValidationException::class);
        $this->expectExceptionMessage('already exists');
        
        $this->service->register('existinguser', 1, 'AnotherPass456!');
    }

    public function testAuthenticateSuccess(): void
    {
        $this->service->register('authuser', 1, 'SecurePass123!');
        
        $user = $this->service->authenticate('authuser', 1, 'SecurePass123!');
        
        $this->assertIsArray($user);
        $this->assertEquals('authuser', $user['local_part']);
    }

    public function testAuthenticateWrongPassword(): void
    {
        $this->service->register('authuser2', 1, 'SecurePass123!');
        
        $user = $this->service->authenticate('authuser2', 1, 'WrongPassword!');
        
        $this->assertNull($user);
    }

    public function testAuthenticateFrozenUser(): void
    {
        $this->service->register('frozenuser', 1, 'SecurePass123!');
        $this->pdo->exec("UPDATE users SET frozen = 1 WHERE local_part = 'frozenuser'");
        
        $user = $this->service->authenticate('frozenuser', 1, 'SecurePass123!');
        
        $this->assertNull($user);
    }
}
