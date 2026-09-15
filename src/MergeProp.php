<?php

declare(strict_types=1);

namespace LiteInertia;

/**
 * Wrapper for Inertia v2 merge props (e.g. for infinite scrolling or appending data).
 */
final class MergeProp
{
    /** @var mixed */
    private mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    public function getValue(): mixed
    {
        if (is_callable($this->value)) {
            return ($this->value)();
        }
        return $this->value;
    }

    public function __invoke(): mixed
    {
        return $this->getValue();
    }
}
