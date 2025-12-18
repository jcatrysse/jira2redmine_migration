<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/blockquote/
 */
class Blockquote extends BlockNode
{
    public function toMarkdown(): string
    {
        return implode(
            self::BREAK . '>' . self::BREAK,
            array_map(fn (Node $node) => '> '. $node->toMarkdown(), $this->content())
        );
    }

    public function contains(): array
    {
        return [
            BulletList::class,
            OrderedList::class,
            Paragraph::class,
            TaskList::class,
        ];
    }
}
