<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\CodeBlock;

class CodeBlockHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof CodeBlock) {
            throw new \Exception();
        }

        if (isset($schema->attrs->language)) {
            $node->setLanguage((string)$schema->attrs->language);
        }

        return $node;
    }
}
