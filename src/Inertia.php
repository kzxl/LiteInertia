<?php

declare(strict_types=1);

namespace LiteInertia;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

/**
 * Inertia.js protocol coordinator for Slim 4 and PSR-7/PSR-15 microservices.
 */
class Inertia
{
    /** @var string|callable|null */
    private $version = null;

    /** @var array<string, mixed> */
    private array $sharedProps = [];

    private string $rootView;

    public function __construct(?string $rootView = null)
    {
        $this->rootView = $rootView ?? $this->defaultRootTemplate();
    }

    /**
     * Share global props across all Inertia responses.
     */
    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->sharedProps = array_merge($this->sharedProps, $key);
        } else {
            $this->sharedProps[$key] = $value;
        }
        return $this;
    }

    /**
     * Get shared props.
     */
    public function getShared(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->sharedProps;
        }
        return $this->sharedProps[$key] ?? null;
    }

    /**
     * Set asset version or resolver callback.
     */
    public function version(string|callable $version): self
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Resolve current asset version.
     */
    public function getVersion(): ?string
    {
        if (is_callable($this->version)) {
            return (string)($this->version)();
        }
        return $this->version !== null ? (string)$this->version : null;
    }

    /**
     * Set root HTML template or template file path.
     */
    public function setRootView(string $rootView): self
    {
        $this->rootView = $rootView;
        return $this;
    }

    /**
     * Create a lazy property evaluated only when explicitly requested via partial reloads.
     */
    public function lazy(callable $callback): LazyProp
    {
        return new LazyProp($callback);
    }

    /**
     * Create an Inertia v2 deferred property evaluated asynchronously by client after initial page load.
     */
    public static function defer(callable $callback, string $group = 'default'): DeferredProp
    {
        return new DeferredProp($callback, $group);
    }

    /**
     * Create an Inertia v2 merge property that appends to client state instead of replacing it.
     */
    public static function merge(mixed $value): MergeProp
    {
        return new MergeProp($value);
    }

    /**
     * Create an Inertia property that is always evaluated and sent even during partial reloads.
     */
    public static function always(callable $callback): AlwaysProp
    {
        return new AlwaysProp($callback);
    }

    /**
     * Alias for lazy property.
     */
    public static function optional(callable $callback): LazyProp
    {
        return new LazyProp($callback);
    }

    /**
     * Check if the current request is a client-side prefetch request.
     */
    public function isPrefetching(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('X-Inertia-Prefetch')) === 'true';
    }

    /**
     * Check if the current request is a partial reload.
     */
    public function isPartial(ServerRequestInterface $request, ?string $component = null): bool
    {
        $partialComponent = $request->getHeaderLine('X-Inertia-Partial-Component');
        if ($partialComponent === '') {
            return false;
        }
        return $component === null || $partialComponent === $component;
    }

    /**
     * Instruct the Inertia client to encrypt history state for this visit.
     */
    public function encryptHistory(ResponseInterface $response, bool $encrypt = true): ResponseInterface
    {
        return $response->withHeader('X-Inertia-Encrypt-History', $encrypt ? 'true' : 'false');
    }

    /**
     * Instruct the Inertia client to clear history state on navigation.
     */
    public function clearHistory(ResponseInterface $response, bool $clear = true): ResponseInterface
    {
        return $response->withHeader('X-Inertia-Clear-History', $clear ? 'true' : 'false');
    }

    /**
     * Helper to store a flash message in the active session.
     */
    public static function flash(string $key, mixed $message, string $sessionKey = 'flash'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        $_SESSION[$sessionKey][$key] = $message;
    }

    /**
     * Render an Inertia response matching the Inertia.js protocol.
     *
     * @param array<string, mixed> $props Component props
     */
    public function render(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $component,
        array $props = [],
    ): ResponseInterface {
        $isInertia = strtolower($request->getHeaderLine('X-Inertia')) === 'true';
        $currentUrl = (string)$request->getUri();
        $version = $this->getVersion();

        $allProps = array_merge($this->sharedProps, $props);

        // Handle partial reloads (X-Inertia-Partial-Data and X-Inertia-Partial-Except)
        $only = [];
        $except = [];
        $partialComponent = $request->getHeaderLine('X-Inertia-Partial-Component');
        $isPartial = ($isInertia && $partialComponent !== '' && $partialComponent === $component);

        if ($isPartial) {
            $partialData = $request->getHeaderLine('X-Inertia-Partial-Data');
            if ($partialData !== '') {
                $only = array_filter(array_map('trim', explode(',', $partialData)));
            }

            $partialExcept = $request->getHeaderLine('X-Inertia-Partial-Except');
            if ($partialExcept !== '') {
                $except = array_filter(array_map('trim', explode(',', $partialExcept)));
            }
        }

        [$resolvedProps, $deferredProps, $mergeProps] = $this->resolveProps($allProps, $isPartial, $only, $except);

        $page = [
            'component' => $component,
            'props' => $resolvedProps,
            'url' => $currentUrl,
            'version' => $version,
        ];

        if (!empty($deferredProps)) {
            $page['deferredProps'] = $deferredProps;
        }

        if (!empty($mergeProps)) {
            $page['mergeProps'] = array_values(array_unique($mergeProps));
        }

        // 1. Inertia JSON response
        if ($isInertia) {
            $json = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $response->getBody()->write($json);

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Inertia', 'true')
                ->withHeader('Vary', 'Accept');
        }

        // 2. Initial Full HTML Page
        $html = $this->renderRootView($page);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Create a 409 Conflict response instructing Inertia client to perform a full hard visit.
     */
    public function location(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response
            ->withStatus(409)
            ->withHeader('X-Inertia-Location', $url);
    }

    /**
     * Resolve property values, executing callbacks and respecting partial reloads.
     *
     * @param array<string, mixed> $props
     * @param bool $isPartial
     * @param list<string> $only
     * @param list<string> $except
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: list<string>}
     */
    private function resolveProps(array $props, bool $isPartial, array $only, array $except): array
    {
        $onlyMap = !empty($only) ? array_fill_keys($only, true) : null;
        $exceptMap = !empty($except) ? array_fill_keys($except, true) : null;

        $resolved = [];
        $deferredProps = [];
        $mergeProps = [];

        foreach ($props as $key => $value) {
            // Check if this prop is a MergeProp
            if ($value instanceof MergeProp) {
                $mergeProps[] = $key;
                $value = $value->getValue();
            }

            // AlwaysProp is included even during partial reloads
            if ($value instanceof AlwaysProp) {
                $resolved[$key] = $value();
                continue;
            }

            // Handle DeferredProp (Inertia v2)
            if ($value instanceof DeferredProp) {
                if (!$isPartial) {
                    // On initial / full visit: do NOT evaluate, list in deferredProps for client async fetch
                    $group = $value->getGroup();
                    $deferredProps[$group][] = $key;
                    continue;
                }

                // If partial reload, only include if explicitly requested in $only
                if ($onlyMap !== null && !isset($onlyMap[$key])) {
                    continue;
                }

                $resolved[$key] = $value();
                continue;
            }

            // If partial reload, check only / except
            if ($isPartial) {
                if ($onlyMap !== null && !isset($onlyMap[$key])) {
                    continue;
                }
                if ($exceptMap !== null && isset($exceptMap[$key])) {
                    continue;
                }
            }

            // LazyProp is ONLY evaluated when explicitly requested in partial reload
            if ($value instanceof LazyProp) {
                if (!$isPartial) {
                    continue;
                }
                $value = $value();
            } elseif (is_callable($value)) {
                $value = $value();
            }

            $resolved[$key] = $value;
        }

        return [$resolved, $deferredProps, $mergeProps];
    }

    /**
     * Render the root HTML layout containing the #app container and data-page attribute.
     *
     * @param array<string, mixed> $page
     */
    private function renderRootView(array $page): string
    {
        $json = htmlspecialchars(
            json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        $appContainer = '<div id="app" data-page="' . $json . '"></div>';

        if (file_exists($this->rootView)) {
            $content = (string)file_get_contents($this->rootView);
        } else {
            $content = $this->rootView;
        }

        if (str_contains($content, '@inertia')) {
            return str_replace('@inertia', $appContainer, $content);
        }

        if (str_contains($content, '<!-- @inertia -->')) {
            return str_replace('<!-- @inertia -->', $appContainer, $content);
        }

        if (str_contains($content, 'id="app"')) {
            // Already has container, inject data-page
            return preg_replace('/id=["\']app["\']/', 'id="app" data-page="' . $json . '"', $content, 1) ?? $content;
        }

        if (str_contains($content, '</body>')) {
            return str_replace('</body>', $appContainer . "\n</body>", $content);
        }

        return $content . "\n" . $appContainer;
    }

    private function defaultRootTemplate(): string
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inertia App</title>
</head>
<body>
    @inertia
</body>
</html>';
    }
}
