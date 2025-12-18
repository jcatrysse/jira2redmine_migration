<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

class Expand extends BlockNode
{
    public string $title = '';

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function toMarkdown(): string
    {
        return sprintf('<details>
  <summary>%s</summary>
  %s
</details>', $this->title, implode(
            self::BREAK,
            array_map(fn (Node $node) => $node->toMarkdown(), $this->content())
        ));
    }

    public function contains(): array
    {
        return [
            Paragraph::class,
        ];
    }
}
