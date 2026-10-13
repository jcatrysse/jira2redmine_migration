<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Decision 4: mentions become @login. Anonymised reproductions of Jira's rendered mentions.
 */
final class MentionsTest extends TestCase
{
    private const LOOKUP = [
        'acc-22' => ['redmine_user_id' => 22, 'redmine_login' => 'jane.doe@example.eu'],
        'acc-23' => ['redmine_user_id' => 23, 'redmine_login' => null],
    ];

    public function testJiraMentionBecomesAtLogin(): void
    {
        $html = '<a href="https://x.atlassian.net/secure/ViewProfile.jspa?accountId=acc-22" class="user-hover" '
            . 'data-account-id="acc-22">Jane Doe</a>thanks Jane';
        $markdown = (string)convertJiraHtmlToMarkdown('<p>' . $html . '</p>', []);
        self::assertSame('@jane.doe@example.eu thanks Jane', trim(replaceJiraUserLinksWithRedmineIds($markdown, self::LOOKUP)));
    }

    public function testMarkdownProfileLinkBecomesAtLogin(): void
    {
        $markdown = 'Hi [Jane Doe](https://x.atlassian.net/secure/ViewProfile.jspa?accountId=acc-22), see this';
        self::assertSame('Hi @jane.doe@example.eu, see this', replaceJiraUserLinksWithRedmineIds($markdown, self::LOOKUP));
    }

    public function testUnknownLoginKeepsUserId(): void
    {
        self::assertSame('ping user#23 and user#99', rewriteUserIdsAsMentions('ping user#23 and user#99', self::LOOKUP));
    }

    public function testAMentionGluedToAWordGetsASpace(): void
    {
        self::assertSame('@jane.doe@example.eu thanks', rewriteUserIdsAsMentions('user#22thanks', self::LOOKUP));
        self::assertSame('@jane.doe@example.eu.', rewriteUserIdsAsMentions('user#22.', self::LOOKUP));
    }

    public function testAdfMentionBecomesAtLogin(): void
    {
        $adf = json_encode(['type' => 'doc', 'version' => 1, 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => ['id' => 'acc-22', 'text' => '@Jane Doe']],
                ['type' => 'text', 'text' => ' please check'],
            ],
        ]]], JSON_THROW_ON_ERROR);
        $markdown = convertDescriptionToMarkdown($adf, new \Karvaka\AdfToGfm\Converter());
        self::assertSame('@jane.doe@example.eu please check', replaceJiraUserLinksWithRedmineIds($markdown, self::LOOKUP));
    }
}
