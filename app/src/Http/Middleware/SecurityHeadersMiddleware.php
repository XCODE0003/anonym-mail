<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers middleware.
 * Sets HSTS, CSP, and other security headers.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        // HSTS
        $response = $response->withHeader(
            'Strict-Transport-Security',
            'max-age=63072000; includeSubDomains; preload'
        );

        // Content Security Policy - NO JS allowed
        $response = $response->withHeader(
            'Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'none'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data:; " .
            "font-src 'self'; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'none'; " .
            "upgrade-insecure-requests"
        );

        // Prevent MIME sniffing
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        // Prevent framing
        $response = $response->withHeader('X-Frame-Options', 'DENY');

        // XSS protection (legacy browsers)
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');

        // No referrer
        $response = $response->withHeader('Referrer-Policy', 'no-referrer');

        // Permissions policy
        $response = $response->withHeader(
            'Permissions-Policy',
            'interest-cohort=(), geolocation=(), camera=(), microphone=(), ' .
            'payment=(), usb=(), accelerometer=(), gyroscope=()'
        );

        return $response;
    }
}
