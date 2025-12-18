<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\InlineNode;
use Karvaka\AdfToGfm\Node;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/heading/
 */
class Heading extends BlockNode
{
    private int $level = 1;

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function toMarkdown(): string
    {
        return str_repeat('#', $this->level) . ' ' . implode(
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
