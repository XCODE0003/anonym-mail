<?php

declare(strict_types=1);

/**
 * Admin panel DI container additions
 */

use App\Admin\AdminService;
use App\Admin\AuditLog;
use Psr\Container\ContainerInterface;

return [
    AdminService::class => function (ContainerInterface $c) {
        return new AdminService($c->get(PDO::class));
    },

    AuditLog::class => function (ContainerInterface $c) {
        return new AuditLog($c->get(PDO::class));
    },
];
