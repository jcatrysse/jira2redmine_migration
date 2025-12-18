<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Hydrators;

use Karvaka\AdfToGfm\HydratorInterface;
use Karvaka\AdfToGfm\Node;
use Karvaka\AdfToGfm\Nodes\Text;

class TextHydrator implements HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node
    {
        if (! $node instanceof Text) {
            throw new \Exception();
        }

        if (isset($schema->text)) {
            $node->setText((string)$schema->text);
        }

        if (isset($schema->marks) && is_iterable($schema->marks)) {
            foreach ($schema->marks as $mark) {
                if (! isset($mark->type)) {
                    continue;
                }

                switch ($mark->type) {
                    case 'code':
                        $node->setIsCode(true);

                        break;
                    case 'em':
                        $node->setIsItalic(true);

                        break;
                    case 'link':
                        $node->setLink($mark->attrs->href ?? '#', $mark->attrs->title ?? '');

                        break;
                    case 'strike':
                        $node->setIsStrikeThrough(true);

                        break;
                    case 'strong':
                        $node->setIsStrong(true);

                        break;
                    //case 'subsup':
                    // todo
                    //    break;
                    //case 'textColor':
                    // todo
                    //    break;
                    //case 'underline':
                    // todo
                    //    break;
                }
            }
        }

        return $node;
    }
}
