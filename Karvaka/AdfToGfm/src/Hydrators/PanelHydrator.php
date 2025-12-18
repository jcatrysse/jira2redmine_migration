<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Panel;

class PanelHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Panel) {
            throw new \Exception();
        }

        if (isset($schema->attrs->panelType)) {
            $node->setPanelType((string)$schema->attrs->panelType);
        }

        return $node;
    }
}
