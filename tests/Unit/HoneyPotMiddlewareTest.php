<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\HoneyPotMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class HoneyPotMiddlewareTest extends TestCase
{
    private HoneyPotMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new HoneyPotMiddleware();
    }

    public function testGetRequestPassesThrough(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse());

        $this->middleware->process($request, $handler);
    }

    public function testPostWithoutHoneyPotPasses(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $request = $request->withParsedBody([
            'username' => 'real_user',
            'password' => 'real_password',
        ]);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse());

        $this->middleware->process($request, $handler);
    }

    public function testPostWithFilledHoneyPotReturnsFakeSuccess(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/register.php');
        $request = $request->withParsedBody([
            'username' => 'real_user',
            'website' => 'http://spam.com', // Honey pot field filled = bot
        ]);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);
        
        // Should return 200 with fake success (not 403, to confuse bots)
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('success', (string) $response->getBody());
    }

    public function testPostWithEmptyHoneyPotPasses(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/register.php');
        $request = $request->withParsedBody([
            'username' => 'real_user',
            'website' => '', // Empty honey pot = human
        ]);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse());

        $this->middleware->process($request, $handler);
    }
}
