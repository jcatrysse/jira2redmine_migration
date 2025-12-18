<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Table;

class TableHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Table) {
            throw new \Exception();
        }

        if (isset($schema->attrs->isNumberColumnEnabled)) {
            $node->setIsNumberColumnEnabled((bool)$schema->attrs->isNumberColumnEnabled);
        }

        return $node;
    }
}
