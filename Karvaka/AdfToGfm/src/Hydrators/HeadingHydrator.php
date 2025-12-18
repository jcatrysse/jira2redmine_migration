<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Heading;

class HeadingHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Heading) {
            throw new \Exception();
        }

        if (isset($schema->attrs->level)) {
            $node->setLevel((int)$schema->attrs->level);
        }

        return $node;
    }
}
