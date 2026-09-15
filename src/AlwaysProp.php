<?php

declare(strict_types=1);

namespace LiteInertia;

/**
 * Wrapper for Inertia props that must ALWAYS be included even in partial reloads.
 */
final class AlwaysProp
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
