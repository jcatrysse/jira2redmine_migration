<?php

declare(strict_types=1);

use Jira2Redmine\Text\IssueReference;
use PHPUnit\Framework\TestCase;

final class IssueReferenceTest extends TestCase
{
    private const MACRO = '<p>See <span class="jira-issue-macro" data-jira-key="ABC-77">'
        . '<a href="https://site.atlassian.net/browse/ABC-77" class="jira-issue-macro-key issue-link" title="Value not calculated">'
        . '<img class="icon" src="https://site.atlassian.net/rest/api/2/universal_avatar/view/type/issuetype/avatar/10318?size=medium" />'
        . 'ABC-77</a> <span class="aui-lozenge aui-lozenge-subtle aui-lozenge-success">Closed</span></span>'
        . ' and <a href="https://site.atlassian.net/browse/ABC-12">Howto record raw data</a>,'
        . ' <a href="https://site.atlassian.net/browse/XYZ-9">XYZ-9</a>.</p>';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
    }

    public function testALinkWithItsOwnTextKeepsTheText(): void
    {
        self::assertSame('Howto record raw data (#12)', IssueReference::forLink('Howto record raw data', 'ABC-12', 12));
        self::assertSame('#12', IssueReference::forLink('abc-12', 'ABC-12', 12));
        self::assertSame('#12', IssueReference::forLink('![](https://site/icon.png)ABC-12', 'ABC-12', 12));
        self::assertSame('#12', IssueReference::forLink('https://site.atlassian.net/browse/ABC-12', 'ABC-12', 12));
        self::assertSame('#12', IssueReference::forLink(' ', 'ABC-12', 12));
        $board = 'https://site.atlassian.net/jira/software/c/projects/ABC/boards/1?selectedIssue=abc-1';
        self::assertSame('ABC-1', IssueReference::keyOfUrl($board));
        self::assertNull(IssueReference::keyOfUrl('https://example.org/browse'));
    }

    public function testCommentsAndDescriptionsAlike(): void
    {
        $markdown = removeJiraAvatarImages(restoreLiteralMarkupLinks((string)convertJiraHtmlToMarkdown(self::MACRO, [])));
        $expected = 'See #170 `Closed` and Howto record raw data (#12), [XYZ-9](https://site.atlassian.net/browse/XYZ-9).';

        // 11 for comments, 10 for descriptions: the same reference, a target outside the run keeps its Jira link
        self::assertSame($expected, inlineReplaceJiraIssueKeysWithHashes($markdown, ['ABC-77' => 170, 'ABC-12' => 12]));
        self::assertSame($expected, replaceIssueLinksInText($markdown, ['ABC-77' => 170, 'ABC-12' => 12]));
    }

    public function testTheStatusLozengeIsALabel(): void
    {
        $html = '<p>Status: <span class="status-macro aui-lozenge aui-lozenge-current">In Progress</span> today</p>';
        self::assertSame('Status: `In Progress` today', convertJiraHtmlToMarkdown($html, []));
    }

    public function testThePushRewriteKeepsTheTextToo(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE migration_mapping_issues (mapping_id INTEGER PRIMARY KEY, jira_issue_key TEXT,
            redmine_issue_id INTEGER NULL, proposed_description TEXT, last_updated_at TEXT)');
        $insert = $pdo->prepare('INSERT INTO migration_mapping_issues VALUES (?, ?, ?, ?, NULL)');
        $insert->execute([1, 'ABC-12', 12, 'target']);
        $insert->execute([2, 'ABC-13', 13, 'Read [the style guide](https://site.atlassian.net/browse/ABC-12) first.']);

        replaceIssueLinksWithRedmineIds($pdo, null, false, '', false);

        $row = $pdo->query('SELECT proposed_description FROM migration_mapping_issues WHERE mapping_id = 2');
        self::assertNotFalse($row);
        self::assertSame('Read the style guide (#12) first.', $row->fetchColumn());
    }
}
