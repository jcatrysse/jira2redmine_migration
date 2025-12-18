<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm;

use RuntimeException;

class NodeMapper
{
    private array $nodesMap = [
        'blockCard' => Nodes\BlockCard::class,
        'blockquote' => Nodes\Blockquote::class,
        'bulletList' => Nodes\BulletList::class,
        'codeBlock' => Nodes\CodeBlock::class,
        'date' => Nodes\Date::class,
        'doc' => Nodes\Doc::class,
        'emoji' => Nodes\Emoji::class,
        'expand' => Nodes\Expand::class,
        'hardBreak' => Nodes\HardBreak::class,
        'heading' => Nodes\Heading::class,
        'inlineCard' => Nodes\InlineCard::class,
        'listItem' => Nodes\ListItem::class,
        'media' => Nodes\Media::class,
        'mediaGroup' => Nodes\MediaGroup::class,
        'mediaSingle' => Nodes\MediaSingle::class,
        'mention' => Nodes\Mention::class,
        'orderedList' => Nodes\OrderedList::class,
        'panel' => Nodes\Panel::class,
        'paragraph' => Nodes\Paragraph::class,
        'rule' => Nodes\Rule::class,
        'status' => Nodes\Status::class,
        'table' => Nodes\Table::class,
        'tableCell' => Nodes\TableCell::class,
        'tableHeader' => Nodes\TableHeader::class,
        'tableRow' => Nodes\TableRow::class,
        'text' => Nodes\Text::class,
        'taskList' => Nodes\TaskList::class,
        'taskItem' => Nodes\TaskItem::class,
    ];

    private array $hydratorsMap = [
        Hydrators\BlockCardHydrator::class => Nodes\BlockCard::class,
        Hydrators\CodeBlockHydrator::class => Nodes\CodeBlock::class,
        Hydrators\DateHydrator::class => Nodes\Date::class,
        Hydrators\DocHydrator::class => Nodes\Doc::class,
        Hydrators\EmojiHydrator::class => Nodes\Emoji::class,
        Hydrators\ExpandHydrator::class => Nodes\Expand::class,
        Hydrators\HeadingHydrator::class => Nodes\Heading::class,
        Hydrators\InlineCardHydrator::class => Nodes\InlineCard::class,
        Hydrators\MediaHydrator::class => Nodes\Media::class,
        Hydrators\MentionHydrator::class => Nodes\Mention::class,
        Hydrators\OrderedListHydrator::class => Nodes\OrderedList::class,
        Hydrators\PanelHydrator::class => Nodes\Panel::class,
        Hydrators\StatusHydrator::class => Nodes\Status::class,
        Hydrators\TableHydrator::class => Nodes\Table::class,
        Hydrators\TextHydrator::class => Nodes\Text::class,
        Hydrators\TaskListHydrator::class => Nodes\TaskList::class,
        Hydrators\TaskItemHydrator::class => Nodes\TaskItem::class,
    ];

    public function createNodeFromSchema(object $schema): ?Node
    {
        $type = $schema->type ?? throw new RuntimeException('Property [type] is required');
        
        if (! array_key_exists($schema->type, $this->nodesMap)) {
            // throw new RuntimeException(sprintf('Unsupported node type [%s]', $type));
            return null;
        }

        $node = new $this->nodesMap[$type]();

        foreach ($this->hydratorsMap as $hydrator => $target) {
            if ($node::class === $target) {
                (new $hydrator())->hydrate($node, $schema);
            }
        }

        if ($node instanceof BlockNode && isset($schema->content)) {
            $node->setContent($this->createContentNodesFromSchemas($node, $schema->content));
        }

        return $node;
    }

    protected function createContentNodesFromSchemas(BlockNode $parent, array $schemas): array
    {
        $content = [];

        foreach ($schemas as $schema) {
            $node = $this->createNodeFromSchema($schema);

            if (!$node){
                continue;
            }

            if (! $parent->canContain($node::class)) {
                throw new RuntimeException(
                    sprintf('Node [%s] can not contain [%s]', $parent::class, $node::class)
                );
            }

            $content[] = $node;
        }

        return $content;
    }
}
