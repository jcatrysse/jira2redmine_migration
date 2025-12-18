<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\HasDepth;
use Karvaka\AdfToGfm\Node;

class ListItem extends BlockNode
{
    use HasDepth;

    public function toMarkdown(): string
    {
        return implode(
            self::BREAK,
            array_map(function (Node $node) {
                if ($node instanceof BulletList || $node instanceof OrderedList || $node instanceof TaskList) {
                    return $node->setDepth($this->depth + 1)->toMarkdown();
                }

                return $node->toMarkdown();
            }, $this->content())
        );
    }

    public function contains(): array
    {
        return [
            BulletList::class,
            CodeBlock::class,
            MediaSingle::class,
            OrderedList::class,
            Paragraph::class,
            HardBreak::class,
            TaskList::class,
            InlineCard::class,
            Media::class,
            MediaGroup::class,
            Mention::class,
            Status::class,
        ];
    }
}
