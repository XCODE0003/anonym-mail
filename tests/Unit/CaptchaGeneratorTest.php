<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Captcha\CaptchaGenerator;
use PHPUnit\Framework\TestCase;
use Predis\Client as Redis;

class CaptchaGeneratorTest extends TestCase
{
    private Redis $redis;
    private CaptchaGenerator $generator;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Redis::class);
        $this->generator = new CaptchaGenerator($this->redis);
    }

    public function testGenerateReturnsValidId(): void
    {
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                $this->matchesRegularExpression('/^captcha:[a-f0-9]{32}$/'),
                $this->anything(),
                $this->anything()
            );

        $id = $this->generator->generate();
        
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
    }

    public function testVerifyCorrectCode(): void
    {
        $captchaId = 'test123';
        $correctCode = 'ABC123';
        
        $this->redis->method('get')
            ->with("captcha:$captchaId")
            ->willReturn($correctCode);
        
        $this->redis->expects($this->once())
            ->method('del')
            ->with("captcha:$captchaId");

        $result = $this->generator->verify($captchaId, $correctCode);
        
        $this->assertTrue($result);
    }

    public function testVerifyIncorrectCode(): void
    {
        $captchaId = 'test123';
        
        $this->redis->method('get')
            ->with("captcha:$captchaId")
            ->willReturn('CORRECT');

        $result = $this->generator->verify($captchaId, 'WRONG');
        
        $this->assertFalse($result);
    }

    public function testVerifyExpiredCaptcha(): void
    {
        $captchaId = 'expired123';
        
        $this->redis->method('get')
            ->with("captcha:$captchaId")
            ->willReturn(null);

        $result = $this->generator->verify($captchaId, 'ANYCODE');
        
        $this->assertFalse($result);
    }

    public function testVerifyIsCaseInsensitive(): void
    {
        $captchaId = 'test456';
        
        $this->redis->method('get')
            ->with("captcha:$captchaId")
            ->willReturn('ABCD12');

        $this->redis->expects($this->once())
            ->method('del');

        $result = $this->generator->verify($captchaId, 'abcd12');
        
        $this->assertTrue($result);
    }
}
