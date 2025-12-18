<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;

class BlockCard extends BlockNode
{
    private string $url = '#';

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function toMarkdown(): string
    {
        return $this->url;
    }

    public function contains(): array
    {
        return [];
    }
}
