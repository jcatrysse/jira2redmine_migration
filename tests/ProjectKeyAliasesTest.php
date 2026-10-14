<?php

declare(strict_types=1);

use Jira2Redmine\Jira\ProjectKeyAliases;
use PHPUnit\Framework\TestCase;

final class ProjectKeyAliasesTest extends TestCase
{
    public function testAnOldKeyReachesTheSameIssue(): void
    {
        $map = ProjectKeyAliases::extend(['ABC-12' => 812, 'XYZ-1' => 5], ['OLD' => 'ABC']);

        self::assertSame(812, $map['OLD-12']);
        self::assertSame(5, $map['XYZ-1']);
        self::assertArrayNotHasKey('OLD-1', $map);
        $text = 'see https://x/browse/OLD-12 and https://x/browse/xyz-3 or /browse/OLD-4';
        self::assertSame(['OLD', 'XYZ'], ProjectKeyAliases::prefixes($text));
    }

    public function testTheRewriteFollowsTheOldKey(): void
    {
        require_once __DIR__ . '/../lib/description_conversion.php';
        $map = ProjectKeyAliases::extend(['ABC-12' => 812], ['OLD' => 'ABC']);

        $text = 'See [OLD-12](https://site.atlassian.net/browse/OLD-12) now';
        self::assertSame('See #812 now', replaceIssueLinksInText($text, $map));
    }

    public function testEveryRewriteUsesTheMapWithFormerKeys(): void
    {
        $root = __DIR__ . '/../';
        foreach (['10_migrate_issues.php', '11_migrate_journals.php'] as $script) {
            $code = (string)file_get_contents($root . $script);
            self::assertTrue(str_contains($code, 'refreshProjectKeyAliases($jiraClient, $pdo);'), $script . ': no former keys');
            self::assertFalse(
                str_contains($code, 'SELECT jira_issue_key, redmine_issue_id FROM migration_mapping_issues'),
                $script . ' builds its own map without former keys'
            );
        }
        self::assertTrue(str_contains((string)file_get_contents($root . 'tools/relink_issue_links.php'), 'loadIssueLinkMap($pdo)'));
    }
}
