<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Date;

class DateHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Date) {
            throw new \Exception();
        }

        if (isset($schema->attrs->timestamp)) {
            $node->setTimestamp(((int)$schema->attrs->timestamp) / 1000);
        }

        return $node;
    }
}
