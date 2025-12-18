<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Mention;

class MentionHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Mention) {
            throw new \Exception();
        }

        if (isset($schema->attrs->text)) {
            $node->setText((string)$schema->attrs->text);
        }

        return $node;
    }
}
