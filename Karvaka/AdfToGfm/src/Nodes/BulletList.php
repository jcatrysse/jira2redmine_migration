<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\HasDepth;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/bulletList/
 */
class BulletList extends BlockNode
{
    use HasDepth;

    public function toMarkdown(): string
    {
        return implode(
            self::BREAK,
            array_map(fn (ListItem $node) => str_repeat(self::INDENT, ($this->depth - 1)) .
                sprintf('- %s', $node->setDepth($this->depth)->toMarkdown()), $this->content())
        );
    }

    public function contains(): array
    {
        return [
            ListItem::class,
        ];
    }
}
