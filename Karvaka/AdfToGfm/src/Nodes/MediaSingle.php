<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;

class MediaSingle extends BlockNode
{
    public function toMarkdown(): string
    {
        return implode(
            self::BREAK,
            array_map(
                static fn (Media $node) => $node->toMarkdown(),
                $this->content()
            )
        );
    }

    public function contains(): array
    {
        return [
            Media::class,
        ];
    }
}
