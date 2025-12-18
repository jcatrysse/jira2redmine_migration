<?php

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\TaskItem;

class TaskItemHydrator implements HydratorInterface 
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof TaskItem){
            throw new \Exception();
        }

        if (isset($schema->attrs->state)){
            $node->setState($schema->attrs->state);
        }

        return $node;
    }
}