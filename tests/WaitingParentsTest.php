<?php

declare(strict_types=1);

use Jira2Redmine\Issues\WaitingParents;
use PHPUnit\Framework\TestCase;

final class WaitingParentsTest extends TestCase
{
    public function testParentsThatHaveNotArrivedArePerProject(): void
    {
        $report = WaitingParents::report([
            ['issue_key' => 'ABC-12', 'parent_key' => 'DOCS-3'],
            ['issue_key' => 'ABC-13', 'parent_key' => 'OTHER-PRJ-7'],
            ['issue_key' => 'ABC-14', 'parent_key' => 'DOCS-3'],
            ['issue_key' => 'ABC-15', 'parent_key' => null],
        ]);

        self::assertSame(['?' => 1, 'DOCS' => 2, 'OTHER-PRJ' => 1], $report['per_project']);
        self::assertSame('ABC-12 waits for its parent DOCS-3', $report['lines'][0]);
        self::assertSame('ABC-15 waits for its parent (key unknown)', $report['lines'][3]);
    }

    public function testEveryIssueRunPrintsTheList(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');

        self::assertTrue(
            str_contains($script, 'printWaitingParents($pdo);'),
            '10 leaves issues waiting for a parent outside the run without listing them'
        );
    }
}
