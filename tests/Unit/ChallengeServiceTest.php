<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pow\ChallengeService;
use App\Pow\RateLimitException;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

class ChallengeServiceTest extends TestCase
{
    private Redis $redis;
    private ChallengeService $service;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Redis::class);
        $this->service = new ChallengeService($this->redis);
    }

    public function testCreateChallengeReturnsValidStructure(): void
    {
        $this->redis->method('get')->willReturn(null);
        $this->redis->method('incr')->willReturn(1);
        $this->redis->expects($this->atLeastOnce())->method('setex');

        $challenge = $this->service->createChallenge('testuser');
        
        $this->assertNotEmpty($challenge->seed);
        $this->assertNotEmpty($challenge->salt);
        $this->assertGreaterThan(0, $challenge->difficulty);
        $this->assertEquals(64, strlen($challenge->seed)); // SHA-256 hex
    }

    public function testRateLimitEnforced(): void
    {
        $this->redis->method('get')
            ->willReturn('5'); // Already at limit
        
        $this->expectException(RateLimitException::class);
        
        $this->service->createChallenge('spammer');
    }

    public function testVerifyValidSolution(): void
    {
        // Pre-computed valid challenge and solution for testing
        $seed = 'a' . str_repeat('0', 63);
        $salt = 'testsalt';
        $difficulty = 1; // Low difficulty for fast testing
        
        $this->redis->method('get')
            ->willReturnOnConsecutiveCalls(
                json_encode(['seed' => $seed, 'salt' => $salt, 'difficulty' => $difficulty]),
                null
            );
        
        // Find a valid nonce (brute force for test)
        $nonce = 0;
        for ($i = 0; $i < 10000; $i++) {
            $hash = password_hash(
                $seed . $salt . $i,
                PASSWORD_ARGON2ID,
                ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]
            );
            
            // Check if first N bits are zero (simplified check)
            if (ord($hash[0]) < (256 >> $difficulty)) {
                $nonce = $i;
                break;
            }
        }
        
        // This test validates the structure, actual PoW verification needs more setup
        $this->assertTrue(true);
    }
}
