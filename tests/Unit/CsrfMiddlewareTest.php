<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->middleware = new CsrfMiddleware();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
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

    public function testPostWithoutTokenReturns403(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $request = $request->withParsedBody([]);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);
        
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testPostWithInvalidTokenReturns403(): void
    {
        $_SESSION['csrf_token'] = 'valid_token';
        
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $request = $request->withParsedBody(['csrf' => 'invalid_token']);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);
        
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testPostWithValidTokenPasses(): void
    {
        $_SESSION['csrf_token'] = 'valid_token';
        
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/');
        $request = $request->withParsedBody([
            'csrf' => 'valid_token',
            'csrf_valid' => 'valid_token',
        ]);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn((new ResponseFactory())->createResponse());

        $this->middleware->process($request, $handler);
    }
}
