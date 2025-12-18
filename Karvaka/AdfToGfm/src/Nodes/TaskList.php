<?php

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\HasDepth;

class TaskList extends BlockNode
{
    use HasDepth;

    public function toMarkdown(): string
    {

        return implode(
            self::BREAK,
            array_map(
                fn(TaskItem $node) =>
                    str_repeat(self::INDENT, ($this->depth - 1)) .
                    $node->setDepth($this->depth)->toMarkdown(),
                $this->content()
            )
        );
    }

    public function contains(): array
    {
        return [
            TaskItem::class,
        ];
    }
}