<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\WorkflowComparison;
use PHPUnit\Framework\TestCase;

final class WorkflowComparisonTest extends TestCase
{
    public function testEveryDifferenceGetsACategoryOrIsUnexpected(): void
    {
        $expected = [
            // project 11, tracker 2, role 4, from status 1: Jira allows 2, the group condition keeps 3 from this role
            '11|2|4|1' => [2 => [], 3 => ['group condition: the role is not one the group holds'], 5 => []],
            // a new issue: no creation transition for this role (permission), Redmine offers the default status
            '11|2|4|0' => [1 => ['permission condition: the role does not hold the permission']],
        ];
        $offered = [
            '11|2|4|1' => [1, 2, 9],
            '11|2|4|0' => [1],
            '11|2|3|1' => [3],
        ];

        $result = WorkflowComparison::compare($expected, $offered, [2 => 1]);

        self::assertSame([
            WorkflowComparison::CURRENT => 1,
            WorkflowComparison::DEFAULT_STATUS => 1,
            WorkflowComparison::EQUAL => 1,
            'group condition: the role is not one the group holds' => 1,
            'unexpected: missing' => 1,
            'unexpected: offered, not in Jira' => 2,
        ], $result['counts']);
        self::assertSame(
            ['11|2|4|1 -> 9 offered, not in Jira', '11|2|4|1 -> 5 missing', '11|2|3|1 -> 3 offered, not in Jira'],
            $result['unexpected']
        );
    }
}
