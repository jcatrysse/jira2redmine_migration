<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

trait HasDepth
{
    public int $depth = 1;

    public function setDepth(int $depth = 1): static
    {
        $this->depth = $depth;

        return $this;
    }
}
