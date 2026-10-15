<?php

declare(strict_types=1);

use Jira2Redmine\Issues\IssueLabels;
use PHPUnit\Framework\TestCase;

final class IssueLabelsTest extends TestCase
{
    public function testLabelsBecomeTagsTrimmedOnceWithoutEmptyOnes(): void
    {
        self::assertSame(['MBES', 'EM712'], IssueLabels::tags([' MBES', 'EM712', '', 'MBES ', '  ']));
        self::assertSame([], IssueLabels::tags(null));
        self::assertSame([], IssueLabels::tags('MBES'));
    }

    public function testTenSendsTheTagsWhenItCreatesTheIssue(): void
    {
        // Jan (2026-10-15): no visible trace; tags set by 13 after creation were an issue update with a journal by the
        // migration account and a new updated time on 7.0 (46 journals, 45 issues per run of GEOS, SIS and OAC)
        $ten = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');
        self::assertStringContainsString('issue.labels AS jira_labels', $ten);
        self::assertSame(2, substr_count($ten, "'tag_list' => issueCreationTags(\$candidate),"), 'the push and its preview');
        self::assertStringContainsString('$tags = IssueLabels::tags($labels);', $ten);
    }
}
