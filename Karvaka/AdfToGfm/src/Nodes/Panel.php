<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\BlockNode;
use Karvaka\AdfToGfm\Node;

/**
 * todo make spec tests
 *
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/panel/
 */
class Panel extends BlockNode
{
    public string $panelType = 'info';

    public function setPanelType(string $panelType): self
    {
        $this->panelType = $panelType;

        return $this;
    }

    private function pickPanelTypeEmoji(string $panelType): ?string
    {
        return match ($panelType) {
            'info' => 'ℹ️',
            'note' => '📝',
            'warning' => '⚠️',
            'success' => '✅',
            'error' => '⛔',
            default => null,
        };
    }

    public function toMarkdown(): string
    {
        $content = $this->content();

        if ($emoji = $this->pickPanelTypeEmoji($this->panelType)) {
            array_unshift($content, (new Emoji())->setShortName($emoji));
        }

        return implode(
            self::BREAK,
            array_map(fn (Node $node) => $node->toMarkdown(), $content)
        );
    }

    public function contains(): array
    {
        return [
            BulletList::class,
            Heading::class,
            OrderedList::class,
            Paragraph::class,
        ];
    }
}
