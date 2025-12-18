<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/rule/
 */
class Rule extends BlockNode
{
    public function toMarkdown(): string
    {
        return '* * *';
    }

    public function contains(): array
    {
        return [];
    }
}
