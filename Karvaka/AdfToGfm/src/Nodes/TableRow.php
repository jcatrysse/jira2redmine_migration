<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/table_row/
 */
class TableRow extends BlockNode
{
    public function toMarkdown(): string
    {
        return sprintf(
            '| %s |',
            implode(
                ' | ',
                array_map(
                    fn (Node $node) => $node->toMarkdown(),
                    $this->content()
                )
            )
        );
    }

    public function contains(): array
    {
        return [
            TableHeader::class,
            TableCell::class,
        ];
    }
}
