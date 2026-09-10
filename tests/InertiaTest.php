<?php

declare(strict_types=1);

namespace LiteInertia\Tests;

use LiteInertia\Inertia;
use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;

final class InertiaTest extends TestCase
{
    private Inertia $inertia;

    protected function setUp(): void
    {
        $this->inertia = new Inertia();
        $this->inertia->version('v1.0.0');
    }

    public function testInitialHtmlPageRendering(): void
    {
        $request = new ServerRequest('GET', '/users');
        $response = new Response();

        $rendered = $this->inertia->render($response, $request, 'Users/Index', [
            'users' => [['id' => 1, 'name' => 'Alice']],
        ]);

        $this->assertEquals(200, $rendered->getStatusCode());
        $this->assertEquals('text/html; charset=UTF-8', $rendered->getHeaderLine('Content-Type'));

        $body = (string)$rendered->getBody();
        $this->assertStringContainsString('<div id="app" data-page="', $body);
        $this->assertStringContainsString('&quot;component&quot;:&quot;Users/Index&quot;', $body);
        $this->assertStringContainsString('&quot;version&quot;:&quot;v1.0.0&quot;', $body);
    }

    public function testInertiaJsonResponse(): void
    {
        $request = (new ServerRequest('GET', '/users'))
            ->withHeader('X-Inertia', 'true');
        $response = new Response();

        $rendered = $this->inertia->render($response, $request, 'Users/Index', [
            'users' => [['id' => 1, 'name' => 'Alice']],
        ]);

        $this->assertEquals(200, $rendered->getStatusCode());
        $this->assertEquals('application/json', $rendered->getHeaderLine('Content-Type'));
        $this->assertEquals('true', $rendered->getHeaderLine('X-Inertia'));

        $json = json_decode((string)$rendered->getBody(), true);
        $this->assertEquals('Users/Index', $json['component']);
        $this->assertEquals('/users', $json['url']);
        $this->assertEquals('v1.0.0', $json['version']);
        $this->assertEquals([['id' => 1, 'name' => 'Alice']], $json['props']['users']);
    }

    public function testSharedProps(): void
    {
        $this->inertia->share('auth', ['user' => 'admin']);
        $this->inertia->share('siteName', 'LiteStore');

        $request = (new ServerRequest('GET', '/dashboard'))
            ->withHeader('X-Inertia', 'true');
        $response = new Response();

        $rendered = $this->inertia->render($response, $request, 'Dashboard', ['stats' => [100]]);
        $json = json_decode((string)$rendered->getBody(), true);

        $this->assertEquals(['user' => 'admin'], $json['props']['auth']);
        $this->assertEquals('LiteStore', $json['props']['siteName']);
        $this->assertEquals([100], $json['props']['stats']);
    }

    public function testPartialReloads(): void
    {
        $lazyCallCount = 0;
        $lazyProp = $this->inertia->lazy(function () use (&$lazyCallCount) {
            $lazyCallCount++;
            return ['expensive' => true];
        });

        // 1. Normal Inertia visit: lazy prop is NOT evaluated
        $request1 = (new ServerRequest('GET', '/users'))->withHeader('X-Inertia', 'true');
        $rendered1 = $this->inertia->render(new Response(), $request1, 'Users/Index', [
            'summary' => 'Quick data',
            'heavyReport' => $lazyProp,
        ]);
        $json1 = json_decode((string)$rendered1->getBody(), true);

        $this->assertEquals('Quick data', $json1['props']['summary']);
        $this->assertArrayNotHasKey('heavyReport', $json1['props']);
        $this->assertEquals(0, $lazyCallCount);

        // 2. Partial reload requesting ONLY heavyReport
        $request2 = (new ServerRequest('GET', '/users'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Partial-Component', 'Users/Index')
            ->withHeader('X-Inertia-Partial-Data', 'heavyReport');

        $rendered2 = $this->inertia->render(new Response(), $request2, 'Users/Index', [
            'summary' => 'Quick data',
            'heavyReport' => $lazyProp,
        ]);
        $json2 = json_decode((string)$rendered2->getBody(), true);

        $this->assertArrayNotHasKey('summary', $json2['props']); // Skipped!
        $this->assertEquals(['expensive' => true], $json2['props']['heavyReport']);
        $this->assertEquals(1, $lazyCallCount);
    }

    public function testLocationResponse(): void
    {
        $response = new Response();
        $redirect = $this->inertia->location($response, 'https://example.com/login');

        $this->assertEquals(409, $redirect->getStatusCode());
        $this->assertEquals('https://example.com/login', $redirect->getHeaderLine('X-Inertia-Location'));
    }
}
