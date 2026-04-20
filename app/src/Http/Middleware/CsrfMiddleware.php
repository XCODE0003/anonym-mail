<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CSRF protection middleware.
 * Uses double-token pattern as in reference (csrf + csrf_valid).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $method = $request->getMethod();

        // Skip CSRF check for safe methods
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        // Validate CSRF tokens for state-changing requests
        if (!$this->validateCsrf($request)) {
            $response = new Response();
            $response->getBody()->write('Invalid CSRF token');
            return $response->withStatus(400);
        }

        return $handler->handle($request);
    }

    private function validateCsrf(ServerRequestInterface $request): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        
        if ($sessionToken === null) {
            return false;
        }

        // Get tokens from POST data
        $body = $request->getParsedBody();
        $csrf = $body['csrf'] ?? null;
        $csrfValid = $body['csrf_valid'] ?? null;

        // Double-token validation (as in reference)
        // Both tokens must match session token
        if ($csrf === null || $csrfValid === null) {
            return false;
        }

        return hash_equals($sessionToken, $csrf) 
            && hash_equals($sessionToken, $csrfValid)
            && $csrf === $csrfValid;
    }
}
