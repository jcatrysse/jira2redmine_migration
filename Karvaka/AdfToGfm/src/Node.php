<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

abstract class Node
{
    public const BREAK = PHP_EOL;
    public const INDENT = '    ';

    public function __construct()
    {
    }

    abstract public function toMarkdown(): string;
}
