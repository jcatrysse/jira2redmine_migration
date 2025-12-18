<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\InlineNode;

/**
 * @link https://developer.atlassian.com/cloud/jira/platform/apis/document/nodes/text/
 */
class Text extends InlineNode
{
    private string $text = '';
    private bool $isCode = false;
    private bool $isItalic = false;
    private bool $isStrong = false;
    private bool $isStrikeThrough = false;
    private bool $isLink = false;
    private string $href = '#';
    private string $title = '';

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function setIsCode(bool $isCode): static
    {
        $this->isCode = $isCode;

        return $this;
    }

    public function setIsItalic(bool $isItalic): static
    {
        $this->isItalic = $isItalic;

        return $this;
    }

    public function setIsStrong(bool $isStrong): static
    {
        $this->isStrong = $isStrong;

        return $this;
    }

    public function setIsStrikeThrough(bool $isStrikeThrough): static
    {
        $this->isStrikeThrough = $isStrikeThrough;

        return $this;
    }

    public function setLink(string $href, string $title): static
    {
        $this->isLink = true;
        $this->href = $href;
        $this->title = $title;

        return $this;
    }

    public function toMarkdown(): string
    {
        $text = $this->text;

        if ($this->isCode) {
            $text = sprintf('`%s`', $text);
        }

        if ($this->isItalic) {
            $text = sprintf('*%s*', $text);
        }

        if ($this->isStrong) {
            $text = sprintf('**%s**', $text);
        }

        if ($this->isStrikeThrough) {
            $text = sprintf('~~%s~~', $text);
        }

        if ($this->isLink) {
            $parentheses = [$this->href];
            if ($this->title) {
                $parentheses[] = sprintf('"%s"', $this->title);
            }

            $text = sprintf('[%s](%s)', $text, implode(' ', $parentheses));
        }

        return $text;
    }
}
