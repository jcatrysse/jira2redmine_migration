<?php

declare(strict_types=1);

use Jira2Redmine\Jira\IssueKeyAliases;
use PHPUnit\Framework\TestCase;

final class IssueKeyAliasesTest extends TestCase
{
    public function testARenamedProjectAndAMovedIssueReachTheMigratedIssue(): void
    {
        // the project OLD was renamed ABC (same number); ABC-7 was moved to XYZ (new number)
        $aliases = ['OLD-12' => 'ABC-12', 'ABC-7' => 'XYZ-31', 'GONE-1' => 'GONE-1'];
        $map = IssueKeyAliases::extend(['ABC-12' => 812, 'XYZ-31' => 931], $aliases);

        self::assertSame(812, $map['OLD-12']);
        self::assertSame(931, $map['ABC-7']);
        self::assertArrayNotHasKey('GONE-1', $map);
        $text = 'see https://x/browse/OLD-12 and https://x/browse/abc-7 or /browse/OLD-12';
        self::assertSame(['OLD-12', 'ABC-7'], IssueKeyAliases::keys($text));
    }

    public function testTheRewriteFollowsTheOldKey(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
        $map = IssueKeyAliases::extend(['ABC-12' => 812, 'XYZ-31' => 931], ['OLD-12' => 'ABC-12', 'ABC-7' => 'XYZ-31']);
        $text = 'See [OLD-12](https://site.atlassian.net/browse/OLD-12) and [the moved one](https://site.atlassian.net/browse/ABC-7)';

        self::assertSame('See #812 and the moved one (#931)', replaceIssueLinksInText($text, $map));
    }

    public function testEveryRewriteUsesTheMapWithOldKeys(): void
    {
        $root = __DIR__ . '/../';
        foreach (['10_migrate_issues.php', '11_migrate_journals.php'] as $script) {
            $code = (string)file_get_contents($root . $script);
            self::assertTrue(str_contains($code, 'refreshIssueKeyAliases($jiraClient, $pdo);'), $script . ': no old keys');
            self::assertFalse(
                str_contains($code, 'SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues'),
                $script . ' builds its own map without old keys'
            );
        }
        self::assertTrue(str_contains((string)file_get_contents($root . 'tools/relink_issue_links.php'), 'loadIssueLinkMap($pdo)'));
    }
}
