<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\InlineNode;
use Karvaka\AdfToGfm\Node;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/paragraph/
 */
class Paragraph extends BlockNode
{
    public function toMarkdown(): string
    {
        return implode(
            '',
            array_map(fn (Node $node) => $node->toMarkdown(), $this->content())
        );
    }

    public function contains(): array
    {
        return [
            InlineNode::class,
        ];
    }
}
