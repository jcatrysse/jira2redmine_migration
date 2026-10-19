<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 11 sent each journal with the issue's updated time set to that journal's time, so after 11 an issue showed the time
 * of the last journal pushed, not Jira's updated time that 10 had set (decision 41: timestamps as in Jira). Every
 * journal now sends Jira's updated time of its issue, so the order of the push cannot move it either.
 */
final class JournalIssueTimeTest extends TestCase
{
    public function testCommentsAndChangelogEntriesKeepJirasUpdatedTimeOfTheIssue(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
        self::assertSame(2, substr_count($eleven, 'ji.updated_at AS issue_updated_at'));
        self::assertSame(2, substr_count($eleven, 'LEFT JOIN staging_jira_issues ji ON ji.id = map.jira_issue_id'));
        self::assertStringContainsString(
            "buildIssueUpdatedOnOverride(\$comment['issue_updated_at'] ?? \$updatedAt ?? \$createdAt)",
            $eleven
        );
        self::assertStringContainsString("buildIssueUpdatedOnOverride(\$history['issue_updated_at'] ?? \$createdAt)", $eleven);
    }

    public function testTenSetsTheSameTime(): void
    {
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        self::assertStringContainsString('issue.updated_at AS jira_updated_at,', $ten);
        self::assertStringContainsString("\$updated = normalizeJiraTimestamp(\$candidate['jira_updated_at'] ?? null);", $ten);
    }
}
