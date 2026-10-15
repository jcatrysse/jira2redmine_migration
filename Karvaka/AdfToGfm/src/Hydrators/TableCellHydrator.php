<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\TableCell;
use Karvaka\AdfToGfm\Nodes\TableHeader;

class TableCellHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof TableCell && ! $node instanceof TableHeader) {
            throw new \Exception();
        }

        $node->setSpans((int)($schema->attrs->colspan ?? 1), (int)($schema->attrs->rowspan ?? 1));

        return $node;
    }
}
