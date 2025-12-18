<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\InlineNode;

class Emoji extends InlineNode
{
    private string $shortName = '';

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

        return $this;
    }

    public function toMarkdown(): string
    {
        return $this->shortName;
    }
}
