<?php

declare(strict_types=1);

namespace App\Webmail;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * HTML sanitizer for email content.
 * Removes dangerous elements and proxies external images.
 */
final class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct(
        private readonly string $proxyUrl = '/imgproxy',
    ) {
        $config = HTMLPurifier_Config::createDefault();
        
        // Cache directory
        $config->set('Cache.SerializerPath', '/tmp/htmlpurifier');
        
        // Allowed HTML
        $config->set('HTML.Allowed', 
            'p,br,hr,b,i,u,s,strong,em,a[href],ul,ol,li,blockquote,pre,code,' .
            'h1,h2,h3,h4,h5,h6,table,thead,tbody,tr,th,td,span,div,' .
            'img[src|alt|width|height]'
        );
        
        // No external resources
        $config->set('URI.DisableExternalResources', true);
        
        // Allow data URIs for images
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
        
        // Target blank for links
        $config->set('HTML.TargetBlank', true);
        
        // No forms
        $config->set('HTML.ForbiddenElements', ['form', 'input', 'button', 'script', 'style', 'iframe', 'object', 'embed']);
        
        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(string $html): string
    {
        // First, proxy external images
        $html = $this->proxyImages($html);
        
        // Then sanitize
        return $this->purifier->purify($html);
    }

    private function proxyImages(string $html): string
    {
        // Replace external image sources with proxy URLs
        return preg_replace_callback(
            '/<img\s+([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>/i',
            function (array $matches) {
                $before = $matches[1];
                $src = $matches[2];
                $after = $matches[3];
                
                // Skip data URIs and local images
                if (str_starts_with($src, 'data:') || str_starts_with($src, '/')) {
                    return $matches[0];
                }
                
                // Generate HMAC signature
                $sig = hash_hmac('sha256', $src, $_ENV['APP_KEY'] ?? 'secret');
                $proxiedSrc = $this->proxyUrl . '?url=' . urlencode($src) . '&sig=' . $sig;
                
                // Add placeholder with link to load
                return sprintf(
                    '<span class="external-image">[External image: <a href="%s" target="_blank">Load image</a>]</span>',
                    htmlspecialchars($proxiedSrc)
                );
            },
            $html
        ) ?? $html;
    }
}
