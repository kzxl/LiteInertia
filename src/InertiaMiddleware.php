<?php

declare(strict_types=1);

namespace LiteInertia;

use Nyholm\Psr7\Response;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/**
 * PSR-15 middleware enforcing Inertia asset versioning and response variance.
 */
class InertiaMiddleware implements MiddlewareInterface
{
    private Inertia $inertia;

    public function __construct(Inertia $inertia)
    {
        $this->inertia = $inertia;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isInertia = strtolower($request->getHeaderLine('X-Inertia')) === 'true';

        // 1. Asset Version Check (GET requests only)
        if ($isInertia && $request->getMethod() === 'GET') {
            $version = $this->inertia->getVersion();
            if ($version !== null) {
                $clientVersion = $request->getHeaderLine('X-Inertia-Version');
                if ($clientVersion !== '' && $clientVersion !== $version) {
                    $response = new Response();
                    return $this->inertia->location($response, (string)$request->getUri());
                }
            }
        }

        $response = $handler->handle($request);

        // 2. Ensure Vary header is set so HTTP caches don't confuse JSON with HTML
        if ($isInertia && !$response->hasHeader('Vary')) {
            $response = $response->withHeader('Vary', 'Accept');
        }

        return $response;
    }
}
