<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

use JsonException;

class Converter
{
    /**
     * @throws JsonException
     */
    public function convert(string|object $schema): Node
    {
        if (is_string($schema)) {
            $schema = json_decode($schema, false, flags: JSON_THROW_ON_ERROR);
        }

        return (new NodeMapper())->createNodeFromSchema($schema);
    }
}
