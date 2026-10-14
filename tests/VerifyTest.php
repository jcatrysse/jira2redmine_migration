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

    public function testMentionIsAWordBoundary(): void
    {
        $html = '<p><a href="https://x.atlassian.net/secure/ViewProfile.jspa?accountId=1" class="user-hover">Jane Doe</a>thanks</p>';
        self::assertSame(['jane', 'doe', 'thanks'], verifyWords(verifyHtmlToText($html)));
    }

    public function testMarkdownToTextKeepsLinkLabelsAndAltText(): void
    {
        $markdown = "## Title\n\nSee [the manual](https://example.com/m) and ![diagram](diagram.png)\n\n| a | b |\n|---|---|\n| c | d |";
        $text = verifyMarkdownToText($markdown);
        self::assertSame(['title', 'see', 'the', 'manual', 'and', 'diagram', 'a', 'b', 'c', 'd'], verifyWords($text));
    }

    public function testCodeKeepsAngleBrackets(): void
    {
        $text = verifyMarkdownToText("```\n=ATAN(<depth>/<distance>)\n```\nand `<b>` too");
        self::assertSame(['atan', 'depth', 'distance', 'and', 'b', 'too'], verifyWords($text));
    }

    public function testJiraBrowseUrlsAreLeftOutOnBothSides(): void
    {
        $url = 'https://x.atlassian.net/browse/OAC-42?focusedCommentId=1';
        $html = '<p>See <a href="' . $url . '">' . $url . '</a> now</p>';
        self::assertSame(['see', 'now'], verifyWords(verifyHtmlToText($html)));
        self::assertSame(['see', '42', 'now'], verifyWords(verifyMarkdownToText('See #42 now <https://x.atlassian.net/browse/OAC-42>')));
    }

    public function testMarkdownAutolinksAreText(): void
    {
        $text = verifyMarkdownToText('Linked with <https://example.com/docs> and <mailto:a@b.example>');
        self::assertSame(['from', 'jane', 'example', 'eu'], verifyWords(verifyMarkdownToText('From &lt;<jane@example.eu>&gt;')));
        $expected = ['linked', 'with', 'https', 'example', 'com', 'docs', 'and', 'mailto', 'a', 'b', 'example'];
        self::assertSame($expected, verifyWords($text));
    }

    public function testMarkdownToTextRendersUserReferencesAsNames(): void
    {
        $names = ['126' => 'Jane Doe', 'jdoe' => 'Jane Doe'];
        $resolver = static fn (string $ref): ?string => $names[$ref] ?? null;
        self::assertSame(['jane', 'doe', 'will', 'do'], verifyWords(verifyMarkdownToText('user#126 will do', $resolver)));
        $words = verifyWords(verifyMarkdownToText('hi @jdoe, mail a@b.c', $resolver));
        self::assertSame(['hi', 'jane', 'doe', 'mail', 'a', 'b', 'c'], $words);
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
            . '<a href="https://site/secure/attachment/1/f.png">f</a><img src="a.png"><img src="b.png">'
            . '<img class="rendericon" src="/images/icons/link_attachment_7.gif"><img class="emoticon" src="smile.png">';
        self::assertSame(['links' => 1, 'images' => 2], verifyHtmlLinkCounts($html));
    }

    public function testTheBenchLinksAMentionWithAnEmailLoginAsRedmineDoes(): void
    {
        $bench = (string)file_get_contents(__DIR__ . '/../tools/conversion_bench.php');

        self::assertStringContainsString("@([A-Za-z0-9_.@-]*[A-Za-z0-9_])/', static fn", $bench, 'the bench cuts a login at its @');
    }

    public function testAnIssueReferenceInsideALinkLabelStaysText(): void
    {
        self::assertSame(
            'See [Support #10566: speeds](https://example.org/t/1) and [#12](/issues/12)',
            verifyLinkIssueReferences('See [Support #10566: speeds](https://example.org/t/1) and #12')
        );
    }

    public function testAnOutlookSafeLinkWrapperIsNoTextToKeep(): void
    {
        $html = '<p>See <a href="https://eur06.safelinks.protection.outlook.com/?url=https%3A%2F%2Fexample.org&amp;data=05">'
            . 'https://eur06.safelinks.protection.outlook.com/?url=https%3A%2F%2Fexample.org&amp;data=05%7C02</a> now</p>';

        self::assertSame(['see', 'now'], verifyWords(verifyHtmlToText($html)));
    }

    public function testTheAddressOfATypedLinkIsNoTextToKeep(): void
    {
        // anonymised from a WIKI comment: Jira shows the typed markup, Redmine the link with its label (B42)
        $html = '<li><a href="https://wiki.example.lan/index.php/A:Raw_logging">How to log in </a>'
            . '[<b>RT27 format</b>|https://wiki.example.lan/index.php/A:Raw_logging], see '
            . '[Magnetic domain](https://en.example.org/wiki/Foo_(bar)) or [not a link] here</li>';

        self::assertSame(
            ['how', 'to', 'log', 'in', 'rt27', 'format', 'see', 'magnetic', 'domain', 'or', 'not', 'a', 'link', 'here'],
            verifyWords(verifyHtmlToText($html))
        );
    }

    public function testALinkToTheIssuesOwnParentIsNotExpectedAsARelation(): void
    {
        // the open WIKI failure: a "To Wiki" link from a subtask to its parent, left to the hierarchy by 12 (B40)
        $verify = (string)file_get_contents(__DIR__ . '/../tools/verify.php');
        $skip = strpos($verify, 'RelationState::linksAncestor((string)$jiraId, (string)$other, $parentOf)');
        self::assertNotFalse($skip, 'verify expects a relation Redmine refuses');
        self::assertLessThan((int)strpos($verify, "\$record('relations', in_array("), $skip);
        self::assertTrue(Jira2Redmine\Relations\RelationState::linksAncestor('591', '588', ['591' => '588', '588' => '1065']));
    }

    public function testTheBenchCountsOnlyHeadingsWithText(): void
    {
        $bench = (string)file_get_contents(__DIR__ . '/../tools/conversion_bench.php');
        self::assertSame(1, preg_match("/'heading' => '([^']+)'/", $bench, $m));
        $pattern = $m[1];

        // Jira renders empty headings with an anchor only: nothing to keep
        self::assertSame(0, preg_match($pattern, '<h1><a name=""></a></h1>'));
        self::assertSame(1, preg_match($pattern, '<h3><a name="Plan"></a><b>Plan</b></h3>'));
        self::assertSame(1, preg_match($pattern, '<h2>Plan</h2>'));
        // the issue type icon of an issue macro is no image construct either
        self::assertSame(1, preg_match("/'image' => '([^']+)'/", $bench, $image));
        $icon = '<img class="icon" src="https://x/rest/api/2/universal_avatar/view/type/issuetype/avatar/1" />';
        self::assertSame(0, preg_match($image[1], $icon));
        self::assertSame(1, preg_match($image[1], '<img src="https://x/secure/attachment/5/plan.png" />'));
    }

    public function testTheIssueTypeIconOfAnIssueMacroIsNoImage(): void
    {
        $html = '<a href="https://x.atlassian.net/browse/ABC-1" class="jira-issue-macro-key issue-link">'
            . '<img class="icon" src="https://x.atlassian.net/rest/api/2/universal_avatar/view/type/issuetype/avatar/10318?size=medium" />'
            . 'ABC-1</a> <img src="https://x.atlassian.net/secure/attachment/5/plan.png" />';

        self::assertSame(['links' => 1, 'images' => 1], verifyHtmlLinkCounts($html));
    }

    public function testMarkdownLinkCounts(): void
    {
        $markdown = "[x](https://example.com) and https://bare.example.com and <https://auto.example.com>\n![i](a.png)";
        self::assertSame(['links' => 3, 'images' => 1], verifyMarkdownLinkCounts($markdown));
    }

    public function testMentionsIssueLinksAndEmailAutolinksCountAsLinks(): void
    {
        $markdown = 'Ask @jane.doe@example.eu or user#7 about #123, mail <jane@example.eu>; not a@b.c';
        self::assertSame(['links' => 4, 'images' => 0], verifyMarkdownLinkCounts($markdown));
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

    public function testOpenParentIsFoundWithoutItsNote(): void
    {
        $rows = [
            // pushed: the note is gone, the status override stays
            ['jira_issue_id' => '100', 'jira_status_id' => '6', 'proposed_status_id' => '3', 'notes' => null, 'is_parent' => 1],
            // a parent in its mapped status
            ['jira_issue_id' => '101', 'jira_status_id' => '6', 'proposed_status_id' => '5', 'notes' => null, 'is_parent' => 1],
            // not a parent: a different status is a defect, not a rule
            ['jira_issue_id' => '102', 'jira_status_id' => '6', 'proposed_status_id' => '3', 'notes' => null, 'is_parent' => 0],
            // still noted (before the push)
            ['jira_issue_id' => '103', 'jira_status_id' => '1', 'proposed_status_id' => '3', 'notes' => '[open-parent]', 'is_parent' => 1],
        ];

        self::assertSame(['100' => '3', '103' => '3'], verifyOpenParents($rows, ['6' => '5', '1' => '1']));
    }

    public function testDuplicateJournalsAreFound(): void
    {
        $comment = ['user' => ['id' => 7], 'created_on' => '2026-09-16T14:12:49Z', 'notes' => 'Looks good', 'details' => []];
        $journals = [
            ['id' => 10] + $comment,
            ['id' => 11, 'user' => ['id' => 7], 'created_on' => '2026-09-16T14:13:00Z', 'notes' => 'Looks good', 'details' => []],
            ['id' => 12, 'user' => ['id' => 1], 'created_on' => '2026-09-17T08:00:00Z', 'notes' => '', 'details' => []],
            ['id' => 20] + $comment,
        ];

        self::assertSame(['journal #20 duplicates #10'], verifyDuplicateJournals($journals));
        self::assertSame([], verifyDuplicateJournals(array_slice($journals, 0, 3)));
    }

    public function testAnUnmappedAssigneeIsExpectedEmptyOrTheDefault(): void
    {
        $users = ['acc-mapped' => '12'];

        self::assertSame('12', verifyExpectedAssignee('acc-mapped', $users, null));
        self::assertSame('', verifyExpectedAssignee('acc-app', $users, null));
        self::assertSame('5', verifyExpectedAssignee('acc-app', $users, 5));
        self::assertSame('', verifyExpectedAssignee(null, $users, 5));
    }

    public function testARichTextFieldWithoutTextIsEmpty(): void
    {
        self::assertTrue(verifyJiraValueIsEmpty(['type' => 'doc', 'version' => 1, 'content' => [['type' => 'paragraph']]]));
        self::assertFalse(verifyJiraValueIsEmpty(['type' => 'doc', 'version' => 1, 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Replaced the cable']]],
        ]]));
        self::assertTrue(verifyJiraValueIsEmpty(null));
        self::assertFalse(verifyJiraValueIsEmpty(['value' => 'Other']));
        self::assertTrue(verifyJiraValueIsEmpty(['labels' => [], 'colors' => [], 'labelSource' => 'global']));
        self::assertFalse(verifyJiraValueIsEmpty(['labels' => ['Vessel_A'], 'colors' => ['light-grey']]));
    }
}
