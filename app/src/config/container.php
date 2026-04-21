<?php

declare(strict_types=1);

/**
 * DI Container configuration
 */

use App\Captcha\CaptchaGenerator;
use App\Domain\Domain\DomainRepository;
use App\Domain\User\UserService;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\HoneyPotMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SessionMiddleware;
use Predis\Client as RedisClient;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    // Environment
    'settings' => fn () => [
        'debug' => (bool) ($_ENV['APP_DEBUG'] ?? false),
        'primaryDomain' => $_ENV['PRIMARY_DOMAIN'] ?? 'localhost',
        'brandName' => $_ENV['BRAND_NAME'] ?? 'Anonym Mail',
    ],

    // Redis client
    RedisClient::class => function (ContainerInterface $c) {
        return new RedisClient([
            'scheme' => 'tcp',
            'host' => $_ENV['REDIS_HOST'] ?? 'redis',
            'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?: null,
        ]);
    },

    // PDO (MySQL)
    PDO::class => function (ContainerInterface $c) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'] ?? 'mysql',
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_NAME'] ?? 'mailservice'
        );

        return new PDO(
            $dsn,
            $_ENV['DB_USER'] ?? 'mailservice',
            $_ENV['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    },

    // Twig
    Environment::class => function (ContainerInterface $c) {
        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        
        $settings = $c->get('settings');
        
        $twig = new Environment($loader, [
            'cache' => $settings['debug'] ? false : '/tmp/twig-cache',
            'debug' => $settings['debug'],
            'auto_reload' => $settings['debug'],
            'strict_variables' => true,
        ]);

        // Global variables
        $twig->addGlobal('brand_name', $settings['brandName']);
        $twig->addGlobal('primary_domain', $settings['primaryDomain']);
        $twig->addGlobal('asset_version', $_ENV['ASSET_VERSION'] ?? '');

        return $twig;
    },

    // Services
    CaptchaGenerator::class => function (ContainerInterface $c) {
        return new CaptchaGenerator(
            $c->get(RedisClient::class),
            (int) ($_ENV['CAPTCHA_LENGTH'] ?? 6)
        );
    },

    UserService::class => function (ContainerInterface $c) {
        return new UserService($c->get(PDO::class));
    },

    DomainRepository::class => function (ContainerInterface $c) {
        return new DomainRepository($c->get(PDO::class));
    },

    // PoW Challenge Service
    \App\Pow\ChallengeService::class => function (ContainerInterface $c) {
        return new \App\Pow\ChallengeService(
            $c->get(RedisClient::class),
            (int) ($_ENV['POW_DIFFICULTY_BITS'] ?? 22),
            (int) ($_ENV['RATE_LIMIT_UNBLOCK_PER_HOUR'] ?? 5)
        );
    },

    // Middleware
    SessionMiddleware::class => function (ContainerInterface $c) {
        return new SessionMiddleware($c->get(RedisClient::class));
    },

    CsrfMiddleware::class => fn () => new CsrfMiddleware(),

    HoneyPotMiddleware::class => fn () => new HoneyPotMiddleware(),

    SecurityHeadersMiddleware::class => fn () => new SecurityHeadersMiddleware(),
];
