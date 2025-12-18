<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\InlineNode;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/hardBreak/
 */
class HardBreak extends InlineNode
{
    public function toMarkdown(): string
    {
        return self::BREAK;
    }
}
