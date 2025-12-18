<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

abstract class BlockNode extends Node
{
    private array $content = [];

    public function setContent(array $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function prependContent(Node $node): static
    {
        array_unshift($this->content, $node);

        return $this;
    }

    public function content(): array
    {
        return $this->content;
    }

    abstract public function contains(): array;

    public function canContain(string $class): bool
    {
        foreach ($this->contains() as $contain) {
            if (is_a($class, $contain, true)) {
                return true;
            }
        }

        return false;
    }

}
