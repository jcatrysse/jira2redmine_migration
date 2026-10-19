<?php

declare(strict_types=1);

use Jira2Redmine\Text\MediaWikiSource;
use PHPUnit\Framework\TestCase;

/**
 * Decision 26: MediaWiki source pasted into Jira (WIKI-280 and others, anonymised). Jira rendered it as a
 * broken table; the ADF holds the text.
 */
final class MediaWikiSourceTest extends TestCase
{
    private const SOURCE = "*Examples:\n:{| class=\"wikitable\"\n!Syntax!!Returns\n|-\n|<pre>{{Tpl\n  | A = b}}</pre>||{{Tpl | A = b}}\n|}";

    private static function adf(string ...$paragraphs): string
    {
        $blocks = [];
        foreach ($paragraphs as $text) {
            $content = [];
            foreach (explode("\n", $text) as $i => $line) {
                if ($i > 0) {
                    $content[] = ['type' => 'hardBreak'];
                }
                $content[] = ['type' => 'text', 'text' => $line];
            }
            $blocks[] = ['type' => 'paragraph', 'content' => $content];
        }

        return (string)json_encode(['type' => 'doc', 'version' => 1, 'content' => $blocks]);
    }

    /** What Jira rendered of the source: its "|" as table cells. */
    private const JIRA_HTML = '<p>The new code:</p>'
        . '<p>*Examples:<br/>:{| class="wikitable"<br/>&#33;Syntax&#33;!Returns</p>'
        . '<div class=\'table-wrap\'><table class=\'confluenceTable\'><tbody><tr><td class=\'confluenceTd\'>-</td></tr>'
        . '<tr><td class=\'confluenceTd\'>&lt;pre&gt;{{Tpl</td></tr><tr><td class=\'confluenceTd\'> A = b}}&lt;/pre&gt;</td>'
        . '<th class=\'confluenceTh\'>{{Tpl </th><td class=\'confluenceTd\'> A = b}}</td></tr><tr><td class=\'confluenceTd\'>}</td></tr>'
        . '</tbody></table></div><p>Thanks</p>';

    public function testOnlyCertainSourceIsFound(): void
    {
        self::assertSame([self::SOURCE], MediaWikiSource::paragraphs(self::adf('The new code:', self::SOURCE, 'Thanks')));
        self::assertSame([], MediaWikiSource::paragraphs(self::adf("Make it '''bold''' and see [[Main Page]]\nor {{Tpl}}\nthanks")));
        self::assertSame([], MediaWikiSource::paragraphs(self::adf('one line with <pre>x</pre>')));
        self::assertTrue(MediaWikiSource::isSource("Usage:<syntaxhighlight>{{Tpl\n  | A =\n}}</syntaxhighlight>"));
    }

    public function testJirasBrokenTableBecomesACodeBlockWithTheSource(): void
    {
        $markdown = convertJiraHtmlToMarkdown(self::JIRA_HTML, [], self::adf('The new code:', self::SOURCE, 'Thanks'));

        self::assertSame("The new code:\n\n```\n" . self::SOURCE . "\n```\n\nThanks", $markdown);
    }

    public function testWithoutTheAdfOrWithoutAMatchTheHtmlStays(): void
    {
        $plain = convertJiraHtmlToMarkdown(self::JIRA_HTML, []);
        self::assertSame($plain, convertJiraHtmlToMarkdown(self::JIRA_HTML, [], self::adf('Other text', "{|\n|a\n|}")));
        self::assertStringContainsString('|---|', (string)$plain);
    }

    public function testTenAndElevenGiveTheConverterTheAdf(): void
    {
        $ten = sourceWithoutLayout((string)file_get_contents(__DIR__ . '/../10_migrate_issues.php'));
        $eleven = sourceWithoutLayout((string)file_get_contents(__DIR__ . '/../11_migrate_journals.php'));

        $description = 'convertJiraHtmlToMarkdown($issueDescriptionHtml, $issueAttachments, is_string($issueDescriptionAdf)';
        self::assertStringContainsString($description, $ten);
        self::assertStringContainsString('convertJiraHtmlToMarkdown($bodyHtml, $issueAttachments, $bodyAdf)', $eleven);
    }
}
