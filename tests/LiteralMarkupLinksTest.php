<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B42: link markup Jira showed as text (a user typed "[label|url]" or "[label](url)") arrived escaped
 * from the HTML converter and the link rewrites left half a link, "](" visible in Redmine (22 items in
 * the WIKI bench). Inputs are anonymised reproductions of the Jira HTML.
 */
final class LiteralMarkupLinksTest extends TestCase
{
    private static function pipeline(string $html): string
    {
        return transform_text_in_transform_phase((string)convertJiraHtmlToMarkdown($html, []), []);
    }

    public function testJiraWikiLinkTypedAsTextBecomesALink(): void
    {
        $html = '<p>two or more <a href="http://wiki.example.lan/index.php/A:SV_and_CTD">SVP</a>s/'
            . '[SVS|http://wiki.example.lan/index.php/A:SV_and_CTD]s at the same depth</p>';

        self::assertSame(
            'two or more [SVP](http://wiki.example.lan/index.php/A:SV_and_CTD)s/'
            . '[SVS](http://wiki.example.lan/index.php/A:SV_and_CTD)s at the same depth',
            self::pipeline($html)
        );
    }

    public function testMarkdownLinkTypedAsTextBecomesALink(): void
    {
        self::assertSame(
            'see [Magnetic domain](https://en.example.org/wiki/Magnetic_domain) here (and more)',
            self::pipeline('<p>see [Magnetic domain](https://en.example.org/wiki/Magnetic_domain) here (and more)</p>')
        );
    }

    public function testParenthesesInTheAddressKeepOneLink(): void
    {
        self::assertSame(
            'see [Foo bar](https://en.example.org/wiki/Foo_%28bar%29) here',
            self::pipeline('<p>see [Foo bar](https://en.example.org/wiki/Foo_(bar)) here</p>')
        );
    }

    public function testOtherTextAndRealLinksStay(): void
    {
        self::assertSame(
            'keep [normal](https://example.org/a_b) and \[not a link\] text',
            self::pipeline('<p>keep <a href="https://example.org/a_b">normal</a> and [not a link] text</p>')
        );
    }

    public function testCommentsGetItToo(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');

        self::assertTrue(str_contains($eleven, 'restoreLiteralMarkupLinks('), '11 leaves typed links half converted');
    }

    public function testTheSpaceInsideTheBracketsStaysOutsideTheLink(): void
    {
        // decision 26: "to[ glossary|url]." read "toglossary" in Redmine (WIKI-613, WIKI-395; anonymised)
        self::assertSame(
            'link when required to [glossary](https://x.example/g).',
            restoreLiteralMarkupLinks('link when required to\\[ glossary|https://x.example/g\\].')
        );
        self::assertSame(
            'use of [Template A](https://x.example/a) and B',
            restoreLiteralMarkupLinks('use of\\[ Template A |https://x.example/a\\]and B')
        );
        // right after another typed link (WIKI-704: "[FTP push|url][ settings with RINEX files|url]")
        self::assertSame(
            '[FTP push](https://x.example/a) [settings](https://x.example/b).',
            restoreLiteralMarkupLinks('\\[FTP push|https://x.example/a\\]\\[ settings|https://x.example/b\\].')
        );
        // without white space inside, the link stays where the user typed it
        self::assertSame('SVPs/[SVS](https://x.example/s)s', restoreLiteralMarkupLinks('SVPs/\\[SVS|https://x.example/s\\]s'));
        self::assertSame('see ([a](https://x.example/a))', restoreLiteralMarkupLinks('see (\\[a|https://x.example/a\\])'));
    }
}
