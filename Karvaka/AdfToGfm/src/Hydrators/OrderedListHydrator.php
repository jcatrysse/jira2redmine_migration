<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\OrderedList;

class OrderedListHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof OrderedList) {
            throw new \Exception();
        }

        if (isset($schema->attrs->order)) {
            $node->setOrder((int)$schema->attrs->order);
        }

        return $node;
    }
}
