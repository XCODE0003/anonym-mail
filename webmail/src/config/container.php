<?php

declare(strict_types=1);

/**
 * Webmail DI container additions
 */

use App\Webmail\ImapClient;
use App\Webmail\HtmlSanitizer;
use Psr\Container\ContainerInterface;

return [
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
