<?php

declare(strict_types=1);

namespace Karvaka\AdfToGfm\Nodes;

use Karvaka\AdfToGfm\InlineNode;

class Mention extends InlineNode
{
    private string $text = '';

    private string $accountId = '';

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function setAccountId(string $accountId): static
    {
        $this->accountId = $accountId;

        return $this;
    }

    /**
     * A mention with an account id becomes a Jira profile link, which the migration turns into @login
     * (decision 4); without one it stays inline code, so it cannot mention the wrong Redmine user.
     */
    public function toMarkdown(): string
    {
        if ($this->accountId !== '') {
            $label = str_replace(['[', ']'], '', $this->text !== '' ? $this->text : '@' . $this->accountId);

            return sprintf('[%s](/secure/ViewProfile.jspa?accountId=%s)', $label, rawurlencode($this->accountId));
        }

        return (new Text())->setText($this->text)->setIsCode(true)->toMarkdown();
    }
}
