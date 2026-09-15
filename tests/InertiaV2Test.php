<?php

declare(strict_types=1);

namespace LiteInertia\Tests;

use LiteInertia\Inertia;
use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;

final class InertiaV2Test extends TestCase
{
    private Inertia $inertia;

    protected function setUp(): void
    {
        $this->inertia = new Inertia();
        $this->inertia->version('v2.0.0');
    }

    public function testDeferredPropsOmittedOnInitialVisit(): void
    {
        $commentsEvaluated = false;
        $analyticsEvaluated = false;

        $request = (new ServerRequest('GET', '/posts/1'))->withHeader('X-Inertia', 'true');
        $rendered = $this->inertia->render(new Response(), $request, 'Posts/Show', [
            'post' => ['id' => 1, 'title' => 'Hello World'],
            'comments' => Inertia::defer(function () use (&$commentsEvaluated) {
                $commentsEvaluated = true;
                return [['id' => 101, 'text' => 'Great post']];
            }),
            'stats' => Inertia::defer(function () use (&$analyticsEvaluated) {
                $analyticsEvaluated = true;
                return ['views' => 1500];
            }, group: 'analytics'),
        ]);

        $json = json_decode((string)$rendered->getBody(), true);

        // 1. Initial visit: deferred callbacks NOT evaluated
        $this->assertFalse($commentsEvaluated);
        $this->assertFalse($analyticsEvaluated);

        // 2. Props must not have deferred data yet
        $this->assertArrayNotHasKey('comments', $json['props']);
        $this->assertArrayNotHasKey('stats', $json['props']);
        $this->assertEquals(['id' => 1, 'title' => 'Hello World'], $json['props']['post']);

        // 3. Top-level deferredProps metadata must be present for client async fetch
        $this->assertArrayHasKey('deferredProps', $json);
        $this->assertEquals(['comments'], $json['deferredProps']['default']);
        $this->assertEquals(['stats'], $json['deferredProps']['analytics']);
    }

    public function testDeferredPropsEvaluatedOnPartialReload(): void
    {
        $commentsEvaluated = false;

        $request = (new ServerRequest('GET', '/posts/1'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Partial-Component', 'Posts/Show')
            ->withHeader('X-Inertia-Partial-Data', 'comments');

        $rendered = $this->inertia->render(new Response(), $request, 'Posts/Show', [
            'post' => ['id' => 1, 'title' => 'Hello World'],
            'comments' => Inertia::defer(function () use (&$commentsEvaluated) {
                $commentsEvaluated = true;
                return [['id' => 101, 'text' => 'Great post']];
            }),
        ]);

        $json = json_decode((string)$rendered->getBody(), true);

        // Partial request for comments: evaluated!
        $this->assertTrue($commentsEvaluated);
        $this->assertEquals([['id' => 101, 'text' => 'Great post']], $json['props']['comments']);
        $this->assertArrayNotHasKey('post', $json['props']);
        $this->assertArrayNotHasKey('deferredProps', $json);
    }

    public function testMergePropsMetadata(): void
    {
        $request = (new ServerRequest('GET', '/feed'))->withHeader('X-Inertia', 'true');
        $rendered = $this->inertia->render(new Response(), $request, 'Feed/Index', [
            'feed' => Inertia::merge(['item_3', 'item_4']),
            'total' => 10,
        ]);

        $json = json_decode((string)$rendered->getBody(), true);

        $this->assertArrayHasKey('mergeProps', $json);
        $this->assertEquals(['feed'], $json['mergeProps']);
        $this->assertEquals(['item_3', 'item_4'], $json['props']['feed']);
    }

    public function testAlwaysPropIncludedEvenInPartialReload(): void
    {
        $request = (new ServerRequest('GET', '/users'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Partial-Component', 'Users/Index')
            ->withHeader('X-Inertia-Partial-Data', 'onlyMe');

        $rendered = $this->inertia->render(new Response(), $request, 'Users/Index', [
            'onlyMe' => 'Specific data',
            'ignored' => 'Regular data',
            'notificationCount' => Inertia::always(fn() => 5),
        ]);

        $json = json_decode((string)$rendered->getBody(), true);

        $this->assertEquals('Specific data', $json['props']['onlyMe']);
        $this->assertArrayNotHasKey('ignored', $json['props']);
        // Always prop is preserved!
        $this->assertEquals(5, $json['props']['notificationCount']);
    }

    public function testPartialExceptSupport(): void
    {
        $request = (new ServerRequest('GET', '/dashboard'))
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Partial-Component', 'Dashboard')
            ->withHeader('X-Inertia-Partial-Except', 'heavyChart');

        $rendered = $this->inertia->render(new Response(), $request, 'Dashboard', [
            'title' => 'My Dashboard',
            'heavyChart' => 'Expensive chart data',
        ]);

        $json = json_decode((string)$rendered->getBody(), true);

        $this->assertEquals('My Dashboard', $json['props']['title']);
        $this->assertArrayNotHasKey('heavyChart', $json['props']);
    }

    public function testPrefetchAndHistoryHeaders(): void
    {
        $reqPrefetch = (new ServerRequest('GET', '/about'))->withHeader('X-Inertia-Prefetch', 'true');
        $this->assertTrue($this->inertia->isPrefetching($reqPrefetch));

        $reqNormal = new ServerRequest('GET', '/about');
        $this->assertFalse($this->inertia->isPrefetching($reqNormal));

        $res = new Response();
        $resEncrypted = $this->inertia->encryptHistory($res, true);
        $this->assertEquals('true', $resEncrypted->getHeaderLine('X-Inertia-Encrypt-History'));

        $resCleared = $this->inertia->clearHistory($res, true);
        $this->assertEquals('true', $resCleared->getHeaderLine('X-Inertia-Clear-History'));
    }
}
