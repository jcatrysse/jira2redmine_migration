<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B15: Markdown with e-mail autolinks went through DOMDocument (attachment and user-link passes) because
 * "<alice@..." starts with "<a"; libxml read "<alice.smith@example.eu>" as a tag, dropped the domain
 * and wrapped the text in <p>, so its Markdown no longer rendered. Anonymised reproduction.
 */
final class AutolinkProtectionTest extends TestCase
{
    private const MARKDOWN = "**From:** Online 1&lt;<online1.vessel@example.eu>&gt;\n"
        . "**To:** Jane Doe \\| Example&lt;<alice.smith@example.eu>&gt;\n"
        . "See <https://example.org/a> and ![](10001__image.png)";

    public function testAttachmentPassKeepsEmailAutolinksAndAddsNoHtml(): void
    {
        $result = normalizeAttachmentLinksInText(self::MARKDOWN, [
            '10001' => ['unique' => '10001__image.png', 'sharepoint' => null, 'is_image' => true],
        ]);
        self::assertStringContainsString('<online1.vessel@example.eu>', $result);
        self::assertStringContainsString('<alice.smith@example.eu>', $result);
        self::assertStringContainsString('<https://example.org/a>', $result);
        self::assertStringNotContainsString('<p>', $result);
        self::assertStringStartsWith('**From:**', $result);
    }

    public function testUserLinkPassKeepsEmailAutolinks(): void
    {
        $result = replaceJiraUserLinksWithRedmineIds(self::MARKDOWN, []);
        self::assertStringContainsString('<alice.smith@example.eu>', $result);
        self::assertStringNotContainsString('<p>', $result);
    }

    public function testRealAnchorsAreStillRewrittenNextToAutolinks(): void
    {
        $text = 'Mail <alice@example.eu> or ask <a href="https://x.atlassian.net/secure/ViewProfile.jspa?accountId=abc">Alice</a>';
        $result = replaceJiraUserLinksWithRedmineIds($text, ['abc' => ['redmine_user_id' => 7]]);
        self::assertStringContainsString('user#7', $result);
        self::assertStringContainsString('<alice@example.eu>', $result);
    }
}
