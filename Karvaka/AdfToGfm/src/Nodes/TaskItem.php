<?php

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\HasDepth;
use Karvaka\AdfToGfm\Node;

class TaskItem extends BlockNode
{
    use HasDepth;

    private string $state = '';

    public function toMarkdown(): string
    {
        $checkbox = $this->state === 'DONE' ? '[x]' : '[ ]';

        $text = implode(
            '',
            array_map(function (Node $node) {
                return $node->toMarkdown();
            }, $this->content())
        );

        return str_repeat(self::INDENT, $this->depth - 1) . "{$checkbox} {$text}";
    }

    public function contains(): array
    {
        return [
            BulletList::class,
            CodeBlock::class,
            MediaSingle::class,
            OrderedList::class,
            Paragraph::class,
            Text::class,
            HardBreak::class,
            TaskList::class,
            InlineCard::class,
            Media::class,
            MediaGroup::class,
            Mention::class,
            Status::class,
        ];
    }

    public function setState(string $state): void 
    {
        $this->state = $state;
    }

    public function getState(): string 
    {
        return $this->state;
    }
}