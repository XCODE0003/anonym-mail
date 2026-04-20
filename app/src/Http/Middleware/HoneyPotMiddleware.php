<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Honey-pot middleware for bot detection.
 * If password_confirm field is filled, silently return fake success.
 * Bots see no indication they were caught.
 */
final class HoneyPotMiddleware implements MiddlewareInterface
{
    private const HONEYPOT_FIELD = 'password_confirm';
    private const METHODS_TO_CHECK = ['POST', 'PUT', 'PATCH'];

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $method = $request->getMethod();

        if (!in_array($method, self::METHODS_TO_CHECK, true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();

        // Check if honey-pot field is filled (it should be empty)
        if (isset($body[self::HONEYPOT_FIELD]) && $body[self::HONEYPOT_FIELD] !== '') {
            // Bot detected! Return fake success response
            // Do NOT indicate that we caught them
            return $this->createFakeSuccessResponse($request);
        }

        return $handler->handle($request);
    }

    private function createFakeSuccessResponse(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response();
        
        // Determine appropriate fake response based on path
        $path = $request->getUri()->getPath();
        
        if (str_contains($path, 'register')) {
            $response->getBody()->write($this->getFakeRegistrationSuccess());
        } else {
            $response->getBody()->write($this->getFakeGenericSuccess());
        }

        return $response->withStatus(200)->withHeader('Content-Type', 'text/html');
    }

    private function getFakeRegistrationSuccess(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><title>Registration Successful</title></head>
<body>
<h1>Account Created</h1>
<p>Your account has been created successfully. You can now log in.</p>
</body>
</html>
HTML;
    }

    private function getFakeGenericSuccess(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><title>Success</title></head>
<body>
<h1>Success</h1>
<p>Your request has been processed successfully.</p>
</body>
</html>
HTML;
    }
}
