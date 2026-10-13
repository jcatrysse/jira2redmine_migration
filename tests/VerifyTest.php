<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VerifyTest extends TestCase
{
    public function testHtmlToTextSeparatesBlocksAndDecodesEntities(): void
    {
        $text = verifyHtmlToText('<p>First&nbsp;line</p><p>Second &amp; third<br/>fourth</p>');
        self::assertSame(['first', 'line', 'second', 'third', 'fourth'], verifyWords($text));
    }

    public function testMarkdownToTextKeepsLinkLabelsAndAltText(): void
    {
        $text = verifyMarkdownToText("## Title\n\nSee [the manual](https://example.com/m) and ![diagram](diagram.png)\n\n| a | b |\n|---|---|\n| c | d |");
        self::assertSame(['title', 'see', 'the', 'manual', 'and', 'diagram', 'a', 'b', 'c', 'd'], verifyWords($text));
    }

    public function testCodeKeepsAngleBrackets(): void
    {
        $text = verifyMarkdownToText("```\n=ATAN(<depth>/<distance>)\n```\nand `<b>` too");
        self::assertSame(['atan', 'depth', 'distance', 'and', 'b', 'too'], verifyWords($text));
    }

    public function testJiraBrowseUrlsAreLeftOutOnBothSides(): void
    {
        $html = '<p>See <a href="https://x.atlassian.net/browse/OAC-42?focusedCommentId=1">https://x.atlassian.net/browse/OAC-42?focusedCommentId=1</a> now</p>';
        self::assertSame(['see', 'now'], verifyWords(verifyHtmlToText($html)));
        self::assertSame(['see', '42', 'now'], verifyWords(verifyMarkdownToText('See #42 now <https://x.atlassian.net/browse/OAC-42>')));
    }

    public function testMarkdownAutolinksAreText(): void
    {
        $text = verifyMarkdownToText('Linked with <https://example.com/docs> and <mailto:a@b.example>');
        self::assertSame(['linked', 'with', 'https', 'example', 'com', 'docs', 'and', 'mailto', 'a', 'b', 'example'], verifyWords($text));
    }

    public function testMarkdownToTextRendersUserReferencesAsNames(): void
    {
        $names = ['126' => 'Jane Doe', 'jdoe' => 'Jane Doe'];
        $resolver = static fn (string $ref): ?string => $names[$ref] ?? null;
        self::assertSame(['jane', 'doe', 'will', 'do'], verifyWords(verifyMarkdownToText('user#126 will do', $resolver)));
        self::assertSame(['hi', 'jane', 'doe', 'mail', 'a', 'b', 'c'], verifyWords(verifyMarkdownToText('hi @jdoe, mail a@b.c', $resolver)));
        self::assertSame(['user', '999'], verifyWords(verifyMarkdownToText('user#999', $resolver)));
    }

    public function testNormaliseFilenameDropsUploadPrefix(): void
    {
        self::assertSame('Survey_Checklist_v2.docx', verifyNormaliseFilename('13716__Survey_Checklist_v2.docx'));
        self::assertSame('Survey_Checklist_v2.docx', verifyNormaliseFilename('Survey Checklist v2.docx'));
        self::assertSame('4E59_-20250825.png', verifyNormaliseFilename('[4E59]-20250825.png'));
    }

    public function testWordCoverageCountsDuplicatesAndIgnoresExtras(): void
    {
        $result = verifyWordCoverage(['a', 'b', 'b', 'c'], ['b', 'a', 'x', 'c', 'y']);
        self::assertSame(0.75, $result['coverage']);
        self::assertSame(['b'], $result['missing']);
        self::assertSame(1.0, verifyWordCoverage([], ['x'])['coverage']);
    }

    public function testHtmlLinkCountsSkipAttachmentsAndAnchors(): void
    {
        $html = '<a href="https://example.com">x</a><a href="#top">t</a>'
            . '<a href="https://site/secure/attachment/1/f.png">f</a><img src="a.png"><img src="b.png">';
        self::assertSame(['links' => 1, 'images' => 2], verifyHtmlLinkCounts($html));
    }

    public function testMarkdownLinkCounts(): void
    {
        $markdown = "[x](https://example.com) and https://bare.example.com and <https://auto.example.com>\n![i](a.png)";
        self::assertSame(['links' => 3, 'images' => 1], verifyMarkdownLinkCounts($markdown));
    }

    public function testCompareFilesAsMultiset(): void
    {
        $result = verifyCompareFiles([['a.png', 10], ['a.png', 10], ['b.pdf', 5]], [['a.png', 10], ['b.pdf', 6]]);
        self::assertSame(['a.png (10 B)', 'b.pdf (5 B)'], $result['missing']);
        self::assertSame(['b.pdf (6 B)'], $result['unexpected']);
    }

    public function testSampleIsDeterministicAndSpread(): void
    {
        $ids = range(1, 10);
        shuffle($ids);
        self::assertSame([1, 4, 7], verifySample($ids, 3));
        self::assertSame([1, 2], verifySample([2, 1], 5));
        self::assertSame([], verifySample([1, 2], 0));
    }
}
