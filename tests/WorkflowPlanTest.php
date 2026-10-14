<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\WorkflowPlan;
use Jira2Redmine\Workflows\WorkflowScripts;
use PHPUnit\Framework\TestCase;

final class WorkflowPlanTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function transition(
        int $role,
        int $old,
        int $new,
        string $status,
        ?int $reviewRole = null,
        int $assignee = 0,
        int $project = 0
    ): array {
        return ['redmine_project_id' => $project, 'redmine_tracker_id' => 2, 'redmine_role_id' => $role, 'redmine_old_status_id' => $old,
            'redmine_new_status_id' => $new,
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

        $global = ['project_id' => null, 'tracker_id' => 2];
        self::assertSame([
            $global + ['role_id' => 4, 'old_status_id' => 0, 'new_status_id' => 1, 'author' => false, 'assignee' => false],
            $global + ['role_id' => 4, 'old_status_id' => 1, 'new_status_id' => 2, 'author' => false, 'assignee' => true],
            $global + ['role_id' => 7, 'old_status_id' => 2, 'new_status_id' => 5, 'author' => false, 'assignee' => false],
        ], $plan['transitions']);
        self::assertSame([[null, 2, 3], [null, 2, 4], [null, 2, 7]], $plan['managed']);
        self::assertSame(
            [['tracker_id' => 2, 'role_id' => 4, 'old_status_id' => 5, 'field_name' => '23', 'rule' => 'required']],
            $plan['field_permissions']
        );
        self::assertSame([['name' => WorkflowScripts::name(2, 5), 'tracker_id' => 2, 'status_id' => 5, 'project_ids' => [11, 12],
            'script' => 'x']], $plan['scripts']);
        self::assertSame(['transitions' => 2, 'field_permissions' => 1, 'scripts' => 0], $plan['waiting']);
    }

    public function testEachProjectGetsItsOwnWorkflowAndTheUnionIsTheFallback(): void
    {
        $rows = [
            self::transition(4, 1, 2, 'PROPOSED'),
            self::transition(4, 2, 9, 'IGNORED'),
            // project 11's own workflow: Developer may go 1 -> 2; Manager has a row but none approved
            self::transition(4, 1, 2, 'PROPOSED', null, 0, 11),
            self::transition(3, 1, 2, 'IGNORED', null, 0, 11),
        ];

        $project = WorkflowPlan::fromRows($rows, [], []);
        self::assertSame('project', $project['mode']);
        self::assertSame([null, 11], array_column($project['transitions'], 'project_id'));
        // Manager in project 11 is managed with nothing: an own, empty workflow (7.0), the global one on 5.1
        self::assertSame([[null, 2, 4], [11, 2, 3], [11, 2, 4]], $project['managed']);

        $union = WorkflowPlan::fromRows($rows, [], [], 'union');
        self::assertSame([null], array_column($union['transitions'], 'project_id'));
        self::assertSame([[null, 2, 4]], $union['managed']);

        $this->expectException(InvalidArgumentException::class);
        WorkflowPlan::fromRows($rows, [], [], 'per-tracker');
    }

    public function testTheProposalFindsTheRedmineProjectThroughTheStagedJiraProject(): void
    {
        // migration_mapping_projects has no project key: the proposal stopped on "Unknown column jira_project_key"
        $schema = (string)file_get_contents(__DIR__ . '/../database/schema.sql');
        $table = substr($schema, (int)strpos($schema, 'CREATE TABLE `migration_mapping_projects`'), 1500);
        self::assertStringNotContainsString('`jira_project_key`', substr($table, 0, (int)strpos($table, ') COMMENT')));
        $tool = (string)file_get_contents(__DIR__ . '/../tools/workflow_proposal.php');
        self::assertStringContainsString('JOIN staging_jira_projects p ON p.id = m.jira_project_id', $tool);
    }

    public function testTheRunnerChecksIdsAndComputesTheChangesAfterThePluginsEnable(): void
    {
        $runner = (string)file_get_contents(__DIR__ . '/../tools/apply_workflows.rb');
        // (a) rows go in with insert_all, past Redmine's validations: unknown ids stop the run
        self::assertStringContainsString("abort \"Ids the plan names that this Redmine does not have:", $runner);
        // (b) enable deletes the rows of a combination without a scope: a first run left such a set empty and
        // a second run had to add them (38 rows on 5.1, session C's remark); the changes come after enable now
        $enable = (int)strpos($runner, 'ScopeWriter.enable(');
        $again = (int)strpos($runner, 'add, remove = compute.call(Set.new)');
        $write = (int)strpos($runner, 'WorkflowRule.insert_all(slice)');
        self::assertGreaterThan(0, $enable);
        self::assertGreaterThan($enable, $again, 'the changes are computed before enable deletes the rows without a scope');
        self::assertGreaterThan($again, $write);
        self::assertStringContainsString('add, remove = compute.call(missing_scopes.to_set)', $runner, 'the preview counts them as gone');
        // the scopes and the rules in one transaction: a scope without its rules is an own, empty workflow
        $transaction = (int)strpos($runner, 'ActiveRecord::Base.transaction do');
        self::assertGreaterThan(0, $transaction);
        self::assertLessThan($enable, $transaction, 'enable commits its scopes before the rules are written');
        self::assertGreaterThan($enable, (int)strpos($runner, "ENV['J2R_TEST_FAIL_BEFORE_RULES']"));
    }
}
