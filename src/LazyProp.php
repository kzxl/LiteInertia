<?php

declare(strict_types=1);

namespace LiteInertia;

/**
 * Wrapper for lazily evaluated Inertia page props.
 */
final class LazyProp
{
    /** @var callable */
    private $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(): mixed
    {
        return ($this->callback)();
    }
}
