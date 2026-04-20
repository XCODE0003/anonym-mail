<?php

declare(strict_types=1);

namespace App\Admin;

use PDO;

/**
 * Admin audit log.
 * Records actions with DATE only (no time, no IP) for privacy.
 */
final class AuditLog
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function log(string $adminUsername, string $action, ?string $target): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_audit (admin_username, action, target, at) 
             VALUES (:admin, :action, :target, CURRENT_DATE)'
        );
        
        $stmt->execute([
            'admin' => $adminUsername,
            'action' => $action,
            'target' => $target,
        ]);
    }

    /**
     * @return array<array{id: int, admin_username: string, action: string, target: ?string, at: string}>
     */
    public function getRecent(int $page = 1, int $perPage = 100): array
    {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_audit ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        
        $stmt->execute(['limit' => $perPage, 'offset' => $offset]);
        
        return $stmt->fetchAll();
    }
}
