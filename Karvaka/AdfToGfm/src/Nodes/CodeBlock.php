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
        $content = $this->content();

        return implode(self::BREAK, [
            '`' . $this->language,
            ...array_map(fn (Node $node) => $node->toMarkdown(), $content),
            '`',
        ]);
    }

    public function contains(): array
    {
        return [
            Text::class, // todo clear text marks
        ];
    }
}
