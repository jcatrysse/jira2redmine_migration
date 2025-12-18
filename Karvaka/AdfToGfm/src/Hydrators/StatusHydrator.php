<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Status;

class StatusHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Status) {
            throw new \Exception();
        }

        if (isset($schema->attrs->text)) {
            $node->setText((string)$schema->attrs->text);
        }

        if (isset($schema->attrs->color)) {
            $node->setColor((string)$schema->attrs->color);
        }

        return $node;
    }
}
