<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeadingAnchorsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
    }

    private const HTML = '<h3><a name="EstimatedTime"></a><b>Estimated time</b></h3>'
        . '<blockquote><p><b>Rule of thumb:</b> more files, more overhead.</p></blockquote>';

    public function testAHeadingAnchorDoesNotStayAsHtml(): void
    {
        $markdown = (string)convertJiraHtmlToMarkdown(self::HTML, []);

        self::assertStringNotContainsString('<a name=', $markdown);
        self::assertStringStartsWith('### **Estimated time**', $markdown);
        self::assertStringContainsString("\n> **Rule of thumb:**", $markdown);
    }

    public function testTheLinkPassesKeepTheMarkdownOutOfAParagraph(): void
    {
        $markdown = (string)convertJiraHtmlToMarkdown(self::HTML, []);
        $markdown = replaceJiraUserLinksWithRedmineIdsOnly($markdown, []);
        $markdown = normalizeAttachmentLinksInText($markdown, ['1' => ['unique' => 'plan.png', 'sharepoint' => null]]);

        // wrapped in <p>, Redmine showed "### " and "&gt; **" as text
        self::assertStringStartsNotWith('<p>', $markdown);
        self::assertStringNotContainsString('&gt;', $markdown);
    }

    public function testAnAnchorWithALinkStays(): void
    {
        self::assertStringContainsString(
            '[site](https://example.org)',
            (string)convertJiraHtmlToMarkdown('<p><a name="x" href="https://example.org">site</a></p>', [])
        );
    }
}
