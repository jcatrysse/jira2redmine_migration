<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\RootNode;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/doc/
 */
class Doc extends RootNode
{
    private int $version = 1;

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }
}
