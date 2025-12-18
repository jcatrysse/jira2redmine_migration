<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Emoji;

class EmojiHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Emoji) {
            throw new \Exception();
        }

        if (isset($schema->attrs->shortName)) {
            $node->setShortName($schema->attrs->text);
        }

        return $node;
    }
}
