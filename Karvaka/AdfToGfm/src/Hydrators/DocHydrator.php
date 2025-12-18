<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Doc;

class DocHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Doc) {
            throw new \Exception();
        }

        if (isset($schema->version)) {
            $node->setVersion((int)$schema->version);
        }

        return $node;
    }
}
