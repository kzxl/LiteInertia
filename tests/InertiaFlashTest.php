<?php

declare(strict_types=1);

namespace LiteInertia\Tests;

use LiteInertia\{Inertia, InertiaMiddleware};
use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class InertiaFlashTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        } else {
            @session_start();
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public function testDynamicShareCallbackInMiddleware(): void
    {
        $inertia = new Inertia();
        $middleware = new InertiaMiddleware($inertia);

        $middleware->share(function (ServerRequestInterface $request) {
            return [
                'auth' => ['user_id' => 123, 'name' => 'John Doe'],
                'locale' => 'vi',
            ];
        });

        $request = new ServerRequest('GET', '/dashboard');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $middleware->process($request, $handler);

        $this->assertEquals(['user_id' => 123, 'name' => 'John Doe'], $inertia->getShared('auth'));
        $this->assertEquals('vi', $inertia->getShared('locale'));
    }

    public function testAutoSharesAndClearsSessionFlashMessages(): void
    {
        $inertia = new Inertia();
        $middleware = new InertiaMiddleware($inertia);

        // Flash message using helper
        Inertia::flash('success', 'Dữ liệu đã được lưu thành công!');
        Inertia::flash('code', 200);

        $this->assertArrayHasKey('flash', $_SESSION);

        $request = new ServerRequest('GET', '/profile');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $middleware->process($request, $handler);

        // Flash data should be shared into Inertia
        $flash = $inertia->getShared('flash');
        $this->assertIsArray($flash);
        $this->assertEquals('Dữ liệu đã được lưu thành công!', $flash['success']);
        $this->assertEquals(200, $flash['code']);

        // And session flash data must be cleared (flash once)
        $this->assertArrayNotHasKey('flash', $_SESSION);
    }
}
