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
    /** @var (callable(ServerRequestInterface): array<string, mixed>)|null */
    private $shareCallback = null;
    private bool $autoFlash = true;
    private string $flashSessionKey = 'flash';

    public function __construct(Inertia $inertia)
    {
        $this->inertia = $inertia;
    }

    /**
     * Define a callback to share dynamic props on every request (e.g. auth user, permissions).
     *
     * @param callable(ServerRequestInterface): array<string, mixed> $callback
     */
    public function share(callable $callback): self
    {
        $this->shareCallback = $callback;
        return $this;
    }

    /**
     * Configure automatic session flash message sharing into Inertia props.
     */
    public function enableFlash(bool $enabled = true, string $sessionKey = 'flash'): self
    {
        $this->autoFlash = $enabled;
        $this->flashSessionKey = $sessionKey;
        return $this;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isInertia = strtolower($request->getHeaderLine('X-Inertia')) === 'true';

        // 1. Dynamic per-request shared props
        if ($this->shareCallback !== null) {
            $shared = ($this->shareCallback)($request);
            if (is_array($shared)) {
                $this->inertia->share($shared);
            }
        }

        // 2. Auto-share session flash messages if active
        if ($this->autoFlash && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$this->flashSessionKey])) {
            $flash = $_SESSION[$this->flashSessionKey];
            unset($_SESSION[$this->flashSessionKey]);
            $this->inertia->share('flash', $flash);
        }

        // 3. Asset Version Check (GET requests only)
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

        // 4. Ensure Vary header is set so HTTP caches don't confuse JSON with HTML
        if ($isInertia && !$response->hasHeader('Vary')) {
            $response = $response->withHeader('Vary', 'Accept');
        }

        return $response;
    }
}
