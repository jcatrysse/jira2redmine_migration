<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * todo col/row spans
 *
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/table/
 */
class Table extends BlockNode
{
    public bool $isNumberColumnEnabled = false;

    public function setIsNumberColumnEnabled(bool $isNumberColumnEnabled): static
    {
        $this->isNumberColumnEnabled = $isNumberColumnEnabled;

        return $this;
    }

    public function toMarkdown(): string
    {
        $content = $this->content();

        $length = array_reduce($content, function ($result, TableRow $node) {
            return max(count($node->content()), $result);
        });

        $range = range(1, $length);

        if ($this->hasHeader()) {
            $header = array_shift($content);
        } else {
            $header = (new TableRow())->setContent(array_map(fn () => (new Text()), $range));
        }

        $delimiter = (new TableRow())->setContent(array_map(fn () => (new Text())->setText('---'), $range));

        if ($this->isNumberColumnEnabled) {
            $header = $header->prependContent((new Text())->setText('#'));
            $delimiter = $delimiter->prependContent((new Text())->setText('---'));
            $content = array_map(
                fn (int $index, BlockNode $node) => $node->prependContent((new Text())->setText((string)($index + 1))),
                array_keys($content),
                $content
            );
        }

        array_unshift($content, $delimiter);
        array_unshift($content, $header);

        return implode(
            self::BREAK,
            array_map(fn (Node $node) => $node->toMarkdown(), $content)
        );
    }

    public function hasHeader(): bool
    {
        if (! $first = $this->content()[0] ?? null) {
            return false;
        }

        foreach ($first->content() as $node) {
            if (! is_a($node, TableHeader::class)) {
                return false;
            }
        }

        return true;
    }

    public function contains(): array
    {
        return [
            TableRow::class,
        ];
    }
}
