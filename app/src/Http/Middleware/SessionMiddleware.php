<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Session middleware using Redis backend.
 * No IP/UA stored in session for privacy.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    private const SESSION_NAME = 'ANONYM_SESSION';
    private const SESSION_LIFETIME = 86400;

    public function __construct(
        private readonly RedisClient $redis,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        if (session_status() === PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }

        $request = $request
            ->withAttribute('session', $_SESSION)
            ->withAttribute('csrf_token', $_SESSION['csrf_token']);

        return $handler->handle($request);
    }

    private function configureSession(): void
    {
        $isSecure = ($_ENV['APP_ENV'] ?? 'production') === 'production';
        
        session_name(self::SESSION_NAME);
        
        session_set_cookie_params([
            'lifetime' => self::SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (ini_get('session.save_handler') !== 'redis') {
            $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
            $redisPort = $_ENV['REDIS_PORT'] ?? '6379';
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', "tcp://{$redisHost}:{$redisPort}");
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isSecure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
    }
}
