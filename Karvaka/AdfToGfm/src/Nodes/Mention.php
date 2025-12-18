<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\InlineNode;

class Mention extends InlineNode
{
    private string $text = '';

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function toMarkdown(): string
    {
        return (new Text())->setText($this->text)->setIsCode(true)->toMarkdown();
    }
}
