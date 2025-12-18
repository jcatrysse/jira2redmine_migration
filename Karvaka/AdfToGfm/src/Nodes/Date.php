<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use DateTime;
use Karvaka\AdfToGfm\InlineNode;

/**
 * todo customize timezone
 */
class Date extends InlineNode
{
    public int $timestamp = 0;
    public static string $format = 'M d Y'; // todo customize

    public function setTimestamp(int $timestamp): static
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    public function toMarkdown(): string
    {
        return (new DateTime())->setTimestamp($this->timestamp)->format(static::$format);
    }
}
