<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * Merged cells (colspan, rowspan) are spread over the grid: a merged cell keeps its text in its first place
 * and leaves empty cells in the others, so every row is as wide as the table (GFM has no spans).
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
        $content = $this->spreadSpans($this->content());

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

    /**
     * @param list<TableRow> $rows
     * @return list<TableRow>
     */
    private function spreadSpans(array $rows): array
    {
        $covered = [];
        $spread = [];
        foreach ($rows as $r => $row) {
            $cells = [];
            $column = 0;
            $place = function () use (&$cells, &$column, &$covered, $r): void {
                while (isset($covered[$r][$column])) {
                    $cells[] = new TableCell();
                    $column++;
                }
            };
            foreach ($row->content() as $cell) {
                $place();
                $cells[] = $cell;
                $colspan = $cell instanceof TableCell || $cell instanceof TableHeader ? $cell->colspan : 1;
                $rowspan = $cell instanceof TableCell || $cell instanceof TableHeader ? $cell->rowspan : 1;
                for ($k = 1; $k < $colspan; $k++) {
                    $cells[] = $cell instanceof TableHeader ? new TableHeader() : new TableCell();
                }
                for ($below = 1; $below < $rowspan; $below++) {
                    for ($k = 0; $k < $colspan; $k++) {
                        $covered[$r + $below][$column + $k] = true;
                    }
                }
                $column += $colspan;
            }
            $place();
            $spread[] = (new TableRow())->setContent($cells);
        }

        return $spread;
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
