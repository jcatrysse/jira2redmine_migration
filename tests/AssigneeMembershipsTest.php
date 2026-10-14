<?php

declare(strict_types=1);

use Jira2Redmine\Roles\AssigneeMemberships;
use PHPUnit\Framework\TestCase;

final class AssigneeMembershipsTest extends TestCase
{
    public function testAnAssigneeWithoutMembershipIsProposed(): void
    {
        $assignees = [
            ['project_id' => '10', 'project_key' => 'ABC', 'project_name' => 'Abc', 'account_id' => 'in-group', 'display_name' => 'A'],
            ['project_id' => '10', 'project_key' => 'ABC', 'project_name' => 'Abc', 'account_id' => 'admin', 'display_name' => 'B'],
            ['project_id' => '10', 'project_key' => 'ABC', 'project_name' => 'Abc', 'account_id' => 'left', 'display_name' => 'C'],
            ['project_id' => '10', 'project_key' => 'ABC', 'project_name' => 'Abc', 'account_id' => 'left', 'display_name' => 'C'],
            ['project_id' => '20', 'project_key' => 'XYZ', 'project_name' => 'Xyz', 'account_id' => 'in-group', 'display_name' => 'A'],
        ];
        $group = 'atlassian-group-role-actor';
        $actors = [
            ['project_id' => '10', 'role_name' => 'Browse projects (permission scheme)', 'actor_id' => 'g-1', 'actor_type' => $group],
            ['project_id' => '10', 'role_name' => 'Administrators', 'actor_id' => 'admin', 'actor_type' => 'atlassian-user-role-actor'],
            // the add-ons role gives no membership
            ['project_id' => '20', 'role_name' => 'atlassian-addons-project-access', 'actor_id' => 'g-1', 'actor_type' => $group],
        ];

        $rows = AssigneeMemberships::uncovered($assignees, $actors, ['g-1' => ['in-group']], ['atlassian-addons-project-access']);

        $pairs = array_map(static fn (array $r): array => [$r['project_id'], $r['actor_id']], $rows);
        self::assertSame([['10', 'left'], ['20', 'in-group']], $pairs);
        self::assertSame(AssigneeMemberships::ROLE_NAME, $rows[0]['role_name']);
        self::assertSame('atlassian-user-role-actor', $rows[0]['actor_type']);
    }

    public function testRolesMapsThemAndTheRunStagesIssuesFirst(): void
    {
        $config = require __DIR__ . '/../config/config.default.php';
        self::assertSame('Developer', AssigneeMemberships::mappings($config['migration']['roles'])[AssigneeMemberships::ROLE_NAME]);
        $dedicated = AssigneeMemberships::mappings(['mappings' => ['Administrators' => 'Manager'], 'assignee_only_role' => 'Assignee']);
        self::assertSame(['Administrators' => 'Manager', AssigneeMemberships::ROLE_NAME => 'Assignee'], $dedicated);
        $script = (string)file_get_contents(__DIR__ . '/../04_migrate_roles.php');
        self::assertTrue(str_contains($script, 'AssigneeMemberships::NOTE'), 'an assignee-only row does not say so');
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertLessThan(
            (int)strpos($run, 'step 04 04_migrate_roles.php'),
            (int)strpos($run, 'step 10-jira 10_migrate_issues.php --phases=jira'),
            '04 runs before the issues are staged and cannot see their assignees'
        );
    }
}
