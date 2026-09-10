<?php

declare(strict_types=1);

namespace LiteInertia\Tests;

use LiteInertia\{Inertia, InertiaMiddleware};
use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class InertiaMiddlewareTest extends TestCase
{
    public function testVersionMismatchTriggers409Conflict(): void
    {
        $inertia = new Inertia();
        $inertia->version('v2.0.0');

        $middleware = new InertiaMiddleware($inertia);

        // Client still on v1.0.0
        $request = (new ServerRequest('GET', '/users'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'v1.0.0');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertEquals(409, $response->getStatusCode());
        $this->assertEquals('/users', $response->getHeaderLine('X-Inertia-Location'));
    }

    public function testMatchingVersionPassesToHandler(): void
    {
        $inertia = new Inertia();
        $inertia->version('v2.0.0');

        $middleware = new InertiaMiddleware($inertia);

        $request = (new ServerRequest('GET', '/users'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', 'v2.0.0');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('Content-Type', 'application/json');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Accept', $response->getHeaderLine('Vary'));
    }
}
