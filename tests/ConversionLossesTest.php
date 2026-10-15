<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Decision 26: the remaining conversion losses of the bench, each class with an anonymised reproduction.
 */
final class ConversionLossesTest extends TestCase
{
    public function testEmphasisSplitBySuperscriptStaysOneRun(): void
    {
        // GEOS-53: "16<sup>th</sup>" inside italics came out as "*16**th**", Redmine showed the asterisks
        $html = '<p><em>Figure 1: nav (a), 15</em><sup><em>th</em></sup> <em>yellow, good match</em></p>'
            . '<p><em>Figure 2: Trimble; 16</em><sup><em>th</em></sup><em>. Good matching</em></p>';

        self::assertSame(
            "*Figure 1: nav (a), 15th* *yellow, good match*\n\n*Figure 2: Trimble; 16th. Good matching*",
            convertJiraHtmlToMarkdown($html, [])
        );
    }

    public function testNestedAndSplitEmphasisKeepsItsDelimitersApart(): void
    {
        // GEOS-103: Jira's "<em><b>x</em></b>" and "(</em><b><em>-70</em></b><em>)" became "(****-70****)"; bold
        // between "(" and ")" inside italics would close the italics in CommonMark: the italics stay, that bold goes
        $html = '<p><em>Line: L1</em>#03 range (<em><b>_185.7,197.0</em></b><em>) does not fit within (</em>'
            . '<b><em>-70.0,10.0</em></b><em>).</em></p><p><b>bold </b>next and <b>From:</b> x</p>';

        self::assertSame(
            "*Line: L1*\\#03 range (***\\_185.7,197.0**) does not fit within (-70.0,10.0).*\n\n**bold** next and **From:** x",
            convertJiraHtmlToMarkdown($html, [])
        );
    }

    public function testTheTextPreInACodeBlockStays(): void
    {
        // WIKI-843: MediaWiki source in a code block lost every "<pre>" and "</pre>" it showed
        $html = '<div class="preformatted panel"><div class="preformattedContent panelContent"><pre>|-' . "\n"
            . '| &lt;pre&gt;{{Template' . "\n" . '  | A = b}}&lt;/pre&gt;||{{Template | A = b}}</pre></div></div>'
            . '<p>about &lt;pre&gt; here</p>';

        $markdown = (string)convertJiraHtmlToMarkdown($html, []);
        self::assertStringStartsWith("```\n|-\n| <pre>{{Template\n  | A = b}}</pre>||{{Template | A = b}}\n```\n", $markdown);
        self::assertStringEndsWith("\nabout &lt;pre&gt; here", $markdown);
    }

    public function testInlineCodeJiraShowedAsBracesBecomesCode(): void
    {
        // WIKI template code (anonymised): the ADF marks "{{lc: {{{X|}}} }}" as code, Jira showed "{{{{lc: ...}}}}"
        $code = static fn (string $t): array => ['type' => 'text', 'text' => $t, 'marks' => [['type' => 'code']]];
        $adf = (string)json_encode(['type' => 'doc', 'version' => 1, 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'switch: '], $code('{{lc: {{{X|}}} }}'), ['type' => 'text', 'text' => ' and using '],
            $code(' <div id="A">Some text</div>'), ['type' => 'text', 'text' => ' then {{other}} stays'],
        ]]]]);
        $html = '<p>switch: {{{{lc: {{{X|}}} }}}} and using{{ &lt;div id="A"&gt;Some text&lt;/div&gt;}} then {{other}} stays</p>';

        self::assertSame(
            'switch: `{{lc: {{{X|}}} }}` and using`<div id="A">Some text</div>` then {{other}} stays',
            convertJiraHtmlToMarkdown($html, [], $adf)
        );
    }

    public function testAProfileLinkDoesNotSwallowTheTextBeforeIt(): void
    {
        // WIKI-724: "Linked to [KEY](...), second incident ... [Name](profile)" became "Linked to user#66"
        $text = 'Linked to [https://x.atlassian.net/browse/OTHER-9](https://x.atlassian.net/browse/OTHER-9), second incident. '
            . '[Jane Doe](https://x.atlassian.net/secure/ViewProfile.jspa?accountId=acc-22) please look';
        $lookup = ['acc-22' => ['redmine_user_id' => 22, 'redmine_login' => 'jane']];

        $kept = 'Linked to [https://x.atlassian.net/browse/OTHER-9](https://x.atlassian.net/browse/OTHER-9), second incident. ';
        self::assertSame($kept . 'user#22 please look', transform_text_in_transform_phase($text, ['acc-22' => 22]));
        self::assertSame($kept . '@jane please look', replaceJiraUserLinksWithRedmineIds($text, $lookup));
        // an attachment link before an issue link kept its own label too
        self::assertSame(
            'see [doc.pdf](/rest/api/3/attachment/content/5) and #40',
            replaceIssueLinksInText(
                'see [doc.pdf](/rest/api/3/attachment/content/5) and [WIKI-12](https://x.atlassian.net/browse/WIKI-12)',
                ['WIKI-12' => 40]
            )
        );
    }
}
