<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Expand;

class ExpandHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Expand) {
            throw new \Exception();
        }

        if (isset($schema->attrs->title)) {
            $node->setTitle((string)$schema->attrs->title);
        }

        return $node;
    }
}
