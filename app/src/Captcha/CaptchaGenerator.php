<?php

declare(strict_types=1);

namespace App\Captcha;

use Predis\Client as RedisClient;

/**
 * Server-side CAPTCHA generator using GD.
 * Generates PNG images with distorted text.
 */
final class CaptchaGenerator
{
    private const CHARS = 'abcdefghjkmnpqrstuvwxyz23456789'; // No ambiguous: 0/o, 1/l/i
    private const TTL_SECONDS = 600; // 10 minutes
    private const KEY_PREFIX = 'captcha:';

    private const WIDTH = 200;
    private const HEIGHT = 60;
    private const FONT_SIZE = 24;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $length = 6,
    ) {}

    /**
     * Generate a new CAPTCHA and return the key.
     */
    public function generate(): string
    {
        $solution = $this->generateSolution();
        $key = bin2hex(random_bytes(16));

        // Store solution in Redis
        $this->redis->setex(
            self::KEY_PREFIX . $key,
            self::TTL_SECONDS,
            strtolower($solution)
        );

        return $key;
    }

    /**
     * Render CAPTCHA image as PNG.
     */
    public function render(string $key): ?string
    {
        $solution = $this->redis->get(self::KEY_PREFIX . $key);
        
        if ($solution === null) {
            return null;
        }

        // Create image
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            return null;
        }

        // Colors
        $bgColor = imagecolorallocate($image, 20, 20, 20);
        $textColor = imagecolorallocate($image, 154, 230, 110);
        $noiseColor = imagecolorallocate($image, 60, 60, 60);
        $lineColor = imagecolorallocate($image, 80, 80, 80);

        // Fill background
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $bgColor);

        // Add noise dots
        for ($i = 0; $i < 100; $i++) {
            imagesetpixel(
                $image,
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                $noiseColor
            );
        }

        // Add noise lines
        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                random_int(0, self::WIDTH),
                random_int(0, self::HEIGHT),
                $lineColor
            );
        }

        // Draw text with slight randomization
        $textLen = strlen($solution);
        $charWidth = (self::WIDTH - 40) / $textLen;
        
        for ($i = 0; $i < $textLen; $i++) {
            $char = strtoupper($solution[$i]);
            $x = 20 + ($i * $charWidth) + random_int(-3, 3);
            $y = (self::HEIGHT / 2) + (self::FONT_SIZE / 2) + random_int(-5, 5);
            
            // Use built-in font (no external fonts needed)
            imagestring($image, 5, (int)$x, (int)($y - 15), $char, $textColor);
        }

        // Output as PNG
        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return $data !== false ? $data : null;
    }

    /**
     * Verify CAPTCHA solution.
     */
    public function verify(string $key, string $userSolution): bool
    {
        $storedSolution = $this->redis->get(self::KEY_PREFIX . $key);
        
        if ($storedSolution === null) {
            return false;
        }

        // Delete after verification (one-time use)
        $this->redis->del(self::KEY_PREFIX . $key);

        // Case-insensitive comparison
        return hash_equals(
            strtolower($storedSolution),
            strtolower(trim($userSolution))
        );
    }

    private function generateSolution(): string
    {
        $solution = '';
        $charsLen = strlen(self::CHARS);
        
        for ($i = 0; $i < $this->length; $i++) {
            $solution .= self::CHARS[random_int(0, $charsLen - 1)];
        }

        return $solution;
    }
}
