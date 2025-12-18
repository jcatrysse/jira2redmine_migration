<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

interface HydratorInterface
{
    public function hydrate(Node $node, object $schema): Node;
}
