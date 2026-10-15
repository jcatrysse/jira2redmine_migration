<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/codeBlock/
 */
class CodeBlock extends BlockNode
{
    private string $language = '';

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function toMarkdown(): string
    {
        $code = implode('', array_map(fn (Node $node) => $node->toMarkdown(), $this->content()));
        // a fence longer than any run of backticks in the code (CommonMark), three at least
        preg_match_all('/`+/', $code, $runs);
        $fence = str_repeat('`', max(3, ...array_map(static fn (string $run): int => strlen($run) + 1, $runs[0] ?: [''])));

        return implode(self::BREAK, [$fence . $this->language, $code, $fence]);
    }

    public function contains(): array
    {
        return [
            Text::class, // todo clear text marks
        ];
    }
}
