<?php

declare(strict_types=1);

namespace App\Domain\Domain;

use PDO;

/**
 * Repository for email domains.
 */
final class DomainRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /**
     * Get all active domains that allow registration.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getRegistrableDomains(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name FROM domains 
             WHERE active = true AND allow_registration = true 
             ORDER BY name'
        );

        return $stmt->fetchAll();
    }

    /**
     * Get all active domains.
     *
     * @return array<array{id: int, name: string, allow_registration: bool}>
     */
    public function getAllActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, allow_registration FROM domains 
             WHERE active = true 
             ORDER BY name'
        );

        return $stmt->fetchAll();
    }

    /**
     * Get domain by name.
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, active, allow_registration FROM domains WHERE name = :name'
        );
        $stmt->execute(['name' => strtolower($name)]);

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Get domain by ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, active, allow_registration FROM domains WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Get primary domain (first active domain).
     */
    public function getPrimaryDomain(): ?string
    {
        $stmt = $this->pdo->query(
            'SELECT name FROM domains WHERE active = true ORDER BY id LIMIT 1'
        );

        $result = $stmt->fetch();
        return $result !== false ? $result['name'] : null;
    }
}
