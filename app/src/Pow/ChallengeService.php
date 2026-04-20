<?php

declare(strict_types=1);

namespace App\Pow;

use Predis\Client as RedisClient;

/**
 * Proof-of-Work challenge service.
 * Generates Argon2id-based challenges that require CPU time to solve.
 */
final class ChallengeService
{
    private const KEY_PREFIX = 'pow:challenge:';
    private const RATE_LIMIT_PREFIX = 'pow:rate:';
    private const TTL_SECONDS = 1800; // 30 minutes
    private const RATE_LIMIT_TTL = 3600; // 1 hour

    public function __construct(
        private readonly RedisClient $redis,
        private readonly int $difficultyBits = 22,
        private readonly int $rateLimit = 5,
    ) {}

    /**
     * Generate a new PoW challenge for a user.
     *
     * @throws RateLimitException
     */
    public function generateChallenge(string $email): Challenge
    {
        // Check rate limit
        if (!$this->checkRateLimit($email)) {
            throw new RateLimitException('Too many unblock attempts. Try again later.');
        }

        // Generate challenge parameters
        $seed = bin2hex(random_bytes(32));
        $salt = bin2hex(random_bytes(16));

        $challenge = new Challenge(
            seed: $seed,
            salt: $salt,
            difficulty: $this->difficultyBits,
        );

        // Store challenge in Redis
        $this->redis->setex(
            self::KEY_PREFIX . $email,
            self::TTL_SECONDS,
            json_encode([
                'seed' => $seed,
                'salt' => $salt,
                'difficulty' => $this->difficultyBits,
                'created_at' => time(),
            ])
        );

        // Increment rate limit counter
        $this->incrementRateLimit($email);

        return $challenge;
    }

    /**
     * Verify a challenge solution.
     */
    public function verifySolution(string $email, string $nonce): bool
    {
        $data = $this->redis->get(self::KEY_PREFIX . $email);
        
        if ($data === null) {
            return false;
        }

        $challenge = json_decode($data, true);
        
        if ($challenge === null) {
            return false;
        }

        // Verify the solution
        $isValid = $this->verifyNonce(
            $challenge['seed'],
            $challenge['salt'],
            $nonce,
            $challenge['difficulty']
        );

        if ($isValid) {
            // Delete challenge after successful verification
            $this->redis->del(self::KEY_PREFIX . $email);
        }

        return $isValid;
    }

    /**
     * Get existing challenge for a user (if any).
     */
    public function getChallenge(string $email): ?Challenge
    {
        $data = $this->redis->get(self::KEY_PREFIX . $email);
        
        if ($data === null) {
            return null;
        }

        $challenge = json_decode($data, true);
        
        if ($challenge === null) {
            return null;
        }

        return new Challenge(
            seed: $challenge['seed'],
            salt: $challenge['salt'],
            difficulty: $challenge['difficulty'],
        );
    }

    /**
     * Verify a nonce solves the challenge.
     *
     * The nonce is valid if argon2id(seed || nonce, salt) has 
     * at least $difficulty leading zero bits.
     */
    private function verifyNonce(
        string $seed,
        string $salt,
        string $nonce,
        int $difficulty
    ): bool {
        // Compute hash
        $input = $seed . $nonce;
        
        // Use Argon2id with same parameters as password hashing
        // but we're just checking for leading zeros
        $hash = sodium_crypto_pwhash(
            32, // Output length
            $input,
            hex2bin($salt),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        // Check leading zero bits
        return $this->countLeadingZeroBits($hash) >= $difficulty;
    }

    /**
     * Count leading zero bits in a binary string.
     */
    private function countLeadingZeroBits(string $data): int
    {
        $count = 0;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $byte = ord($data[$i]);
            
            if ($byte === 0) {
                $count += 8;
            } else {
                // Count leading zeros in this byte
                $count += 8 - (int) floor(log($byte, 2)) - 1;
                break;
            }
        }

        return $count;
    }

    private function checkRateLimit(string $email): bool
    {
        $key = self::RATE_LIMIT_PREFIX . hash('sha256', $email);
        $count = (int) $this->redis->get($key);
        
        return $count < $this->rateLimit;
    }

    private function incrementRateLimit(string $email): void
    {
        $key = self::RATE_LIMIT_PREFIX . hash('sha256', $email);
        
        $this->redis->incr($key);
        $this->redis->expire($key, self::RATE_LIMIT_TTL);
    }
}
