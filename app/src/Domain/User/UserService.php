<?php

declare(strict_types=1);

namespace App\Domain\User;

use PDO;

/**
 * User service for account management.
 * NO IP/UA stored - privacy first.
 */
final class UserService
{
    private const MIN_PASSWORD_LENGTH = 10;
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Register a new user.
     *
     * @throws UserValidationException
     */
    public function register(
        string $username,
        int $domainId,
        string $password,
        string $passwordConfirm,
    ): int {
        // Validate username
        $this->validateUsername($username);

        // Validate password
        $this->validatePassword($password, $passwordConfirm);

        // Check domain exists and allows registration
        if (!$this->domainAllowsRegistration($domainId)) {
            throw new UserValidationException('Domain does not allow registration');
        }

        // Check username not taken
        if ($this->usernameExists($username, $domainId)) {
            throw new UserValidationException('Username already taken');
        }

        // Check not reserved
        if ($this->isReservedName($username)) {
            throw new UserValidationException('Username is reserved');
        }

        // Hash password (Dovecot-compatible Argon2id)
        $hash = $this->hashPassword($password);

        // Insert user (smtp_blocked=true by default)
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (local_part, domain_id, password_hash, smtp_blocked, created_at)
             VALUES (:local_part, :domain_id, :password_hash, true, CURRENT_DATE)
             RETURNING id'
        );

        $stmt->execute([
            'local_part' => strtolower($username),
            'domain_id' => $domainId,
            'password_hash' => $hash,
        ]);

        $result = $stmt->fetch();
        return (int) $result['id'];
    }

    /**
     * Authenticate user for webmail/settings.
     */
    public function authenticate(string $email, string $password): ?array
    {
        [$localPart, $domain] = $this->parseEmail($email);
        
        if ($localPart === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.local_part, d.name as domain, u.password_hash, 
                    u.smtp_blocked, u.frozen, u.quota_bytes
             FROM users u
             JOIN domains d ON u.domain_id = d.id
             WHERE u.local_part = :local_part 
               AND d.name = :domain
               AND u.frozen = false
               AND (u.delete_after IS NULL OR u.delete_after > CURRENT_DATE)'
        );

        $stmt->execute([
            'local_part' => strtolower($localPart),
            'domain' => strtolower($domain),
        ]);

        $user = $stmt->fetch();
        
        if ($user === false) {
            return null;
        }

        // Verify password
        if (!$this->verifyPassword($password, $user['password_hash'])) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => $user['local_part'] . '@' . $user['domain'],
            'smtp_blocked' => (bool) $user['smtp_blocked'],
            'quota_bytes' => (int) $user['quota_bytes'],
        ];
    }

    /**
     * Change user password.
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        // Get current hash
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            return false;
        }

        // Verify old password
        if (!$this->verifyPassword($oldPassword, $user['password_hash'])) {
            return false;
        }

        // Validate new password
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return false;
        }

        // Update password
        $newHash = $this->hashPassword($newPassword);
        
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash WHERE id = :id'
        );

        return $stmt->execute(['hash' => $newHash, 'id' => $userId]);
    }

    /**
     * Request account deletion (30-day delay).
     */
    public function requestDeletion(int $userId, string $password): bool
    {
        // Verify password
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if ($user === false || !$this->verifyPassword($password, $user['password_hash'])) {
            return false;
        }

        // Set deletion date (30 days from now)
        $deletionDays = (int) ($_ENV['ACCOUNT_DELETION_DELAY_DAYS'] ?? 30);
        
        $stmt = $this->pdo->prepare(
            'UPDATE users SET delete_after = CURRENT_DATE + :days WHERE id = :id'
        );

        return $stmt->execute(['days' => $deletionDays, 'id' => $userId]);
    }

    /**
     * Unblock SMTP for user.
     */
    public function unblockSmtp(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET smtp_blocked = false WHERE id = :id'
        );

        return $stmt->execute(['id' => $userId]);
    }

    /**
     * Check if user's SMTP is blocked.
     */
    public function isSmtpBlocked(string $email): bool
    {
        [$localPart, $domain] = $this->parseEmail($email);
        
        if ($localPart === null) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT smtp_blocked FROM users u
             JOIN domains d ON u.domain_id = d.id
             WHERE u.local_part = :local_part AND d.name = :domain'
        );

        $stmt->execute([
            'local_part' => strtolower($localPart),
            'domain' => strtolower($domain),
        ]);

        $result = $stmt->fetch();
        return $result === false || (bool) $result['smtp_blocked'];
    }

    private function validateUsername(string $username): void
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new UserValidationException(
                'Username must be 3-32 characters, lowercase letters, numbers, dots, underscores, or hyphens'
            );
        }

        // Check for official-* pattern
        if (str_starts_with(strtolower($username), 'official-')) {
            throw new UserValidationException('Username pattern is reserved');
        }
    }

    private function validatePassword(string $password, string $confirm): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new UserValidationException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }

        if ($password !== $confirm) {
            throw new UserValidationException('Passwords do not match');
        }
    }

    private function hashPassword(string $password): string
    {
        // Dovecot-compatible Argon2id hash
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 3,
            'threads' => 2,
        ]);
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    private function domainAllowsRegistration(int $domainId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM domains WHERE id = :id AND active = true AND allow_registration = true'
        );
        $stmt->execute(['id' => $domainId]);
        return $stmt->fetch() !== false;
    }

    private function usernameExists(string $username, int $domainId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM users WHERE local_part = :local_part AND domain_id = :domain_id'
        );
        $stmt->execute([
            'local_part' => strtolower($username),
            'domain_id' => $domainId,
        ]);
        return $stmt->fetch() !== false;
    }

    private function isReservedName(string $username): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM reserved_names WHERE local_part = :local_part'
        );
        $stmt->execute(['local_part' => strtolower($username)]);
        return $stmt->fetch() !== false;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function parseEmail(string $email): array
    {
        $parts = explode('@', $email, 2);
        
        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }
}
