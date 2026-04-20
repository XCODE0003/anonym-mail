<?php

declare(strict_types=1);

namespace App\Admin;

use PDO;
use RobThree\Auth\TwoFactorAuth;

/**
 * Admin panel service.
 */
final class AdminService
{
    private TwoFactorAuth $tfa;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->tfa = new TwoFactorAuth('AnonymMail');
    }

    public function authenticate(string $username, string $password, string $totpCode): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT username, password_hash, totp_secret FROM admin_users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        
        $admin = $stmt->fetch();
        
        if ($admin === false) {
            return null;
        }
        
        // Verify password
        if (!password_verify($password, $admin['password_hash'])) {
            return null;
        }
        
        // Verify TOTP
        if (!$this->tfa->verifyCode($admin['totp_secret'], $totpCode)) {
            return null;
        }
        
        return [
            'username' => $admin['username'],
        ];
    }

    /**
     * @return array{total_users: int, total_domains: int, blocked_users: int, pending_deletion: int}
     */
    public function getDashboardStats(): array
    {
        $stats = [];
        
        // Total users
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        $stats['total_users'] = (int) $stmt->fetchColumn();
        
        // Total domains
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM domains WHERE active = true');
        $stats['total_domains'] = (int) $stmt->fetchColumn();
        
        // Blocked users
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE smtp_blocked = true');
        $stats['blocked_users'] = (int) $stmt->fetchColumn();
        
        // Pending deletion
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE delete_after IS NOT NULL');
        $stats['pending_deletion'] = (int) $stmt->fetchColumn();
        
        // Frozen users
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users WHERE frozen = true');
        $stats['frozen_users'] = (int) $stmt->fetchColumn();
        
        return $stats;
    }

    /**
     * @return array<array{id: int, name: string, active: bool, allow_registration: bool, user_count: int}>
     */
    public function getAllDomains(): array
    {
        $stmt = $this->pdo->query(
            'SELECT d.*, COUNT(u.id) as user_count 
             FROM domains d 
             LEFT JOIN users u ON d.id = u.domain_id 
             GROUP BY d.id 
             ORDER BY d.name'
        );
        
        return $stmt->fetchAll();
    }

    public function addDomain(string $name): bool
    {
        $name = strtolower(trim($name));
        
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $name)) {
            return false;
        }
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO domains (name, active, allow_registration) 
             VALUES (:name, true, true) 
             ON CONFLICT (name) DO NOTHING'
        );
        
        return $stmt->execute(['name' => $name]);
    }

    /**
     * @return array{users: array, total: int}
     */
    public function searchUsers(string $search, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        
        if ($search !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT u.*, d.name as domain 
                 FROM users u 
                 JOIN domains d ON u.domain_id = d.id 
                 WHERE u.local_part ILIKE :search OR d.name ILIKE :search
                 ORDER BY u.created_at DESC 
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->execute([
                'search' => "%$search%",
                'limit' => $perPage,
                'offset' => $offset,
            ]);
            
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM users u 
                 JOIN domains d ON u.domain_id = d.id 
                 WHERE u.local_part ILIKE :search OR d.name ILIKE :search'
            );
            $countStmt->execute(['search' => "%$search%"]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT u.*, d.name as domain 
                 FROM users u 
                 JOIN domains d ON u.domain_id = d.id 
                 ORDER BY u.created_at DESC 
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->execute(['limit' => $perPage, 'offset' => $offset]);
            
            $countStmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        }
        
        $users = $stmt->fetchAll();
        
        // Add email field
        foreach ($users as &$user) {
            $user['email'] = $user['local_part'] . '@' . $user['domain'];
        }
        
        return [
            'users' => $users,
            'total' => (int) $countStmt->fetchColumn(),
        ];
    }

    public function getUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, d.name as domain 
             FROM users u 
             JOIN domains d ON u.domain_id = d.id 
             WHERE u.id = :id'
        );
        $stmt->execute(['id' => $id]);
        
        $user = $stmt->fetch();
        
        if ($user === false) {
            return null;
        }
        
        $user['email'] = $user['local_part'] . '@' . $user['domain'];
        return $user;
    }

    public function setUserFrozen(int $userId, bool $frozen): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET frozen = :frozen WHERE id = :id');
        return $stmt->execute(['frozen' => $frozen, 'id' => $userId]);
    }

    public function unblockSmtp(int $userId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET smtp_blocked = false WHERE id = :id');
        return $stmt->execute(['id' => $userId]);
    }

    /**
     * @return array<array{id: int, body: string, active: bool}>
     */
    public function getAnnouncements(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM announcements ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    public function saveAnnouncement(string $body, bool $active): bool
    {
        // Deactivate all first
        $this->pdo->exec('UPDATE announcements SET active = false');
        
        if (trim($body) === '') {
            return true;
        }
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO announcements (body, active) VALUES (:body, :active)'
        );
        
        return $stmt->execute(['body' => $body, 'active' => $active]);
    }

    /**
     * @return array<array{key: string, updated_at: string}>
     */
    public function getContentBlocks(): array
    {
        $stmt = $this->pdo->query('SELECT key, updated_at FROM content_blocks ORDER BY key');
        return $stmt->fetchAll();
    }

    public function getContentBlock(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM content_blocks WHERE key = :key');
        $stmt->execute(['key' => $key]);
        
        $block = $stmt->fetch();
        return $block !== false ? $block : null;
    }

    public function updateContentBlock(string $key, string $bodyMd): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE content_blocks SET body_md = :body, updated_at = CURRENT_DATE WHERE key = :key'
        );
        
        return $stmt->execute(['body' => $bodyMd, 'key' => $key]);
    }

    public function createAdminUser(string $username, string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $totpSecret = $this->tfa->createSecret();
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, totp_secret) 
             VALUES (:username, :hash, :totp)
             ON CONFLICT (username) DO UPDATE SET password_hash = :hash, totp_secret = :totp'
        );
        
        $stmt->execute([
            'username' => $username,
            'hash' => $hash,
            'totp' => $totpSecret,
        ]);
        
        return $totpSecret;
    }
}
