<?php

declare(strict_types=1);

namespace LiteInertia;

/**
 * Wrapper for Inertia v2 deferred page props.
 * Deferred props are omitted from initial page loads and fetched asynchronously by client.
 */
final class DeferredProp
{
    /** @var callable */
    private $callback;
    private string $group;

    public function __construct(callable $callback, string $group = 'default')
    {
        $this->callback = $callback;
        $this->group = $group;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function __invoke(): mixed
    {
        return ($this->callback)();
    }
}
