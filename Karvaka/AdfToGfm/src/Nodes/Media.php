<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;

class Media extends BlockNode
{
    private ?string $id = null;
    private ?string $url = null;

    public function toMarkdown(): string
    {
        if ($this->url !== null && $this->url !== '') {
            return sprintf('![](%s)', $this->url);
        }

        if ($this->id !== null && $this->id !== '') {
            return sprintf('![](attachment:%s)', $this->id);
        }

        return '';
    }

    public function contains(): array
    {
        return [];
    }

    public function setId(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }
}
