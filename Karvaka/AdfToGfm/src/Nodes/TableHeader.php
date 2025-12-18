<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/table_header/
 */
class TableHeader extends BlockNode
{
    public function toMarkdown(): string
    {
        return implode(
            '<br>',
            array_map(fn (Node $node) => $this->renderContentNode($node), $this->content())
        );
    }

    private function renderContentNode(Node $node): string
    {
        if ($node instanceof BulletList) {
            return $this->renderListItems($node->content(), 'ul');
        }

        if ($node instanceof OrderedList) {
            return $this->renderListItems($node->content(), 'ol');
        }

        if ($node instanceof TaskList) {
            return $this->renderTaskList($node);
        }

        return $node->toMarkdown();
    }

    private function renderListItems(array $items, string $tag): string
    {
        $listItems = array_map(
            fn (ListItem $item) => sprintf('<li>%s</li>', $item->toMarkdown()),
            $items
        );

        return sprintf('<%1$s>%2$s</%1$s>', $tag, implode('', $listItems));
    }

    private function renderTaskList(TaskList $list): string
    {
        $items = array_map(
            fn (TaskItem $item) => '- ' . ltrim($item->toMarkdown()),
            $list->content()
        );

        return implode('<br>', $items);
    }

    public function contains(): array
    {
        return [
            Blockquote::class,
            BulletList::class,
            Heading::class,
            MediaSingle::class,
            OrderedList::class,
            Paragraph::class,
            Rule::class,
            TaskList::class,
        ];
    }
}
