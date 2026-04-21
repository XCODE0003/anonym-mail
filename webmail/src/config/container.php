<?php

declare(strict_types=1);

/**
 * Webmail DI container additions
 */

use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Webmail\HtmlSanitizer;
use App\Webmail\ImapClient;
use Psr\Container\ContainerInterface;

return [
    SecurityHeadersMiddleware::class => static fn () => new SecurityHeadersMiddleware(forWebmail: true),

    ImapClient::class => function (ContainerInterface $c) {
        // Use dovecot container name for internal Docker network
        return new ImapClient(
            host: 'dovecot',
            port: 993,
            encryption: 'ssl'
        );
    },

    HtmlSanitizer::class => function (ContainerInterface $c) {
        return new HtmlSanitizer(
            proxyUrl: '/imgproxy'
        );
    },
];
