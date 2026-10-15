<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

/**
 * Merged cells of a table cell or header (ADF attrs colspan, rowspan).
 */
trait Spans
{
    public int $colspan = 1;
    public int $rowspan = 1;

    public function setSpans(int $colspan, int $rowspan): static
    {
        $this->colspan = max(1, $colspan);
        $this->rowspan = max(1, $rowspan);

        return $this;
    }
}
