<?php

declare(strict_types=1);

use Jira2Redmine\Roles\WorkflowGroupRoles;
use PHPUnit\Framework\TestCase;

final class WorkflowGroupRolesTest extends TestCase
{
    public function testEachGroupSetToGBecomesAGroupActorOfItsOwnRole(): void
    {
        $config = ['group_roles' => [
            'ABC' => ['Own Team' => 'G', 'Everyone' => 'R', 'Apps' => 'ignore'],
            'XYZ' => ['Own Team' => 'G'],
        ]];
        $projects = ['ABC' => ['id' => '100', 'name' => 'Alpha'], 'XYZ' => ['id' => '200', 'name' => null]];
        $groups = ['own team' => ['id' => 'g-1', 'name' => 'Own Team'], 'everyone' => ['id' => 'g-2', 'name' => 'Everyone']];

        $result = WorkflowGroupRoles::actors($config, $projects, $groups);

        self::assertSame([
            ['project_id' => '100', 'project_key' => 'ABC', 'project_name' => 'Alpha', 'role_id' => '-6',
                'role_name' => 'Own Team (workflow)', 'actor_id' => 'g-1', 'actor_display' => 'Own Team',
                'actor_type' => 'atlassian-group-role-actor'],
            ['project_id' => '200', 'project_key' => 'XYZ', 'project_name' => null, 'role_id' => '-6',
                'role_name' => 'Own Team (workflow)', 'actor_id' => 'g-1', 'actor_display' => 'Own Team',
                'actor_type' => 'atlassian-group-role-actor'],
        ], $result['actors']);
        self::assertSame([], $result['unknown']);
    }

    public function testAnUnknownProjectOrGroupIsReportedNotProposed(): void
    {
        $config = ['group_roles' => ['ABC' => ['Gone' => 'G'], 'NEW' => ['Own Team' => 'G']]];

        $projects = ['ABC' => ['id' => '100', 'name' => 'Alpha']];
        $result = WorkflowGroupRoles::actors($config, $projects, ['own team' => ['id' => 'g-1', 'name' => 'Own Team']]);

        self::assertSame([], $result['actors']);
        self::assertSame(['ABC / gone: no such Jira group (03)', 'NEW / own team: project not staged (01)'], $result['unknown']);
    }

    public function testTheRoleHasOnlyTheTransitionsPermissionAndIsNotAssignable(): void
    {
        $payload = WorkflowGroupRoles::rolePayload('Own Team (workflow)', WorkflowGroupRoles::permissions(null));

        self::assertSame('Own Team (workflow)', $payload['role']['name']);
        // Redmine applies a role's workflow only with edit_issues or add_issues; no view_issues (Jan, decision 14)
        self::assertSame(['edit_issues'], $payload['role']['permissions']);
        self::assertFalse($payload['role']['assignable']);
        self::assertNotContains('view_issues', $payload['role']['permissions']);
    }

    public function testTheNameAndPermissionsFollowTheConfig(): void
    {
        self::assertSame('WF Own Team', WorkflowGroupRoles::roleName('WF %s', 'Own Team'));
        // a pattern without exactly one %s falls back to the default
        self::assertSame('Own Team (workflow)', WorkflowGroupRoles::roleName('fixed name', 'Own Team'));
        self::assertSame('Own Team (workflow)', WorkflowGroupRoles::roleName('%s %d', 'Own Team'));
        self::assertSame(['edit_issues', 'add_issues'], WorkflowGroupRoles::permissions([' edit_issues', 'add_issues', 'edit_issues', '']));
        self::assertSame(['edit_issues'], WorkflowGroupRoles::permissions([]));
    }
}
