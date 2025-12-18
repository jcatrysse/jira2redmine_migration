<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

abstract class RootNode extends BlockNode
{
    public function toMarkdown(): string
    {
        return implode(
            self::BREAK . self::BREAK,
            array_map(fn (Node $node) => $node->toMarkdown(), $this->content())
        );
    }

    public function contains(): array
    {
        return [
            BlockNode::class,
        ];
    }
}
