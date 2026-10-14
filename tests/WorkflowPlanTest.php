<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\WorkflowPlan;
use Jira2Redmine\Workflows\WorkflowScripts;
use PHPUnit\Framework\TestCase;

final class WorkflowPlanTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function transition(int $role, int $old, int $new, string $status, ?int $reviewRole = null, int $assignee = 0): array
    {
        return ['redmine_tracker_id' => 2, 'redmine_role_id' => $role, 'redmine_old_status_id' => $old, 'redmine_new_status_id' => $new,
            'only_assignee' => $assignee, 'review_role_id' => $reviewRole, 'migration_status' => $status];
    }

    public function testOnlyApprovedRowsAreAppliedAndEveryTrackerAndRoleIsManaged(): void
    {
        $plan = WorkflowPlan::fromRows([
            self::transition(4, 0, 1, 'PROPOSED'),
            self::transition(4, 1, 2, 'PROPOSED', null, 1),
            self::transition(4, 2, 5, 'REVIEW'),
            // every row of Manager ignored: Manager's transitions for the tracker are emptied, not left open
            self::transition(3, 1, 2, 'IGNORED'),
            // a group that is not a member: only with the role Jan gave it
            self::transition(0, 2, 5, 'PROPOSED', 7),
            self::transition(0, 1, 5, 'REVIEW'),
        ], [
            ['redmine_tracker_id' => 2, 'redmine_role_id' => 4, 'redmine_status_id' => 5, 'field_name' => '23', 'rule' => 'required',
                'migration_status' => 'PROPOSED'],
            ['redmine_tracker_id' => 2, 'redmine_role_id' => 4, 'redmine_status_id' => 5, 'field_name' => 'assigned_to_id',
                'rule' => 'required', 'migration_status' => 'REVIEW'],
        ], [
            ['redmine_tracker_id' => 2, 'redmine_status_id' => 5, 'redmine_project_ids' => '11,12', 'script' => 'x',
                'migration_status' => 'PROPOSED'],
        ]);

        self::assertSame([
            ['tracker_id' => 2, 'role_id' => 4, 'old_status_id' => 0, 'new_status_id' => 1, 'author' => false, 'assignee' => false],
            ['tracker_id' => 2, 'role_id' => 4, 'old_status_id' => 1, 'new_status_id' => 2, 'author' => false, 'assignee' => true],
            ['tracker_id' => 2, 'role_id' => 7, 'old_status_id' => 2, 'new_status_id' => 5, 'author' => false, 'assignee' => false],
        ], $plan['transitions']);
        self::assertSame([[2, 3], [2, 4], [2, 7]], $plan['managed']);
        self::assertSame(
            [['tracker_id' => 2, 'role_id' => 4, 'old_status_id' => 5, 'field_name' => '23', 'rule' => 'required']],
            $plan['field_permissions']
        );
        self::assertSame([['name' => WorkflowScripts::name(2, 5), 'tracker_id' => 2, 'status_id' => 5, 'project_ids' => [11, 12],
            'script' => 'x']], $plan['scripts']);
        self::assertSame(['transitions' => 2, 'field_permissions' => 1, 'scripts' => 0], $plan['waiting']);
    }
}
