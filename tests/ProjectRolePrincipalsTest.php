<?php

declare(strict_types=1);

use Jira2Redmine\Roles\ProjectRolePrincipals;
use PHPUnit\Framework\TestCase;

final class ProjectRolePrincipalsTest extends TestCase
{
    private const ADMIN = [
        'project_id' => '10008',
        'project_key' => 'ABC',
        'project_name' => 'Abc',
        'role_id' => '10002',
        'role_name' => 'Administrators',
    ];

    public function testAUserActorBecomesThatUserNotTheGroupsTheUserIsIn(): void
    {
        $actors = [self::ADMIN + ['actor_type' => 'atlassian-user-role-actor', 'actor_id' => 'acc-1', 'actor_display' => 'Ann Admin']];

        $principals = ProjectRolePrincipals::fromActors($actors, ['g-all' => 'All staff']);

        self::assertCount(1, $principals);
        self::assertSame('user', $principals[0]['principal_type']);
        self::assertSame('acc-1', $principals[0]['principal_id']);
        self::assertSame('Ann Admin', $principals[0]['principal_name']);
    }

    public function testAGroupActorBecomesTheGroup(): void
    {
        $actors = [self::ADMIN + ['actor_type' => 'atlassian-group-role-actor', 'actor_id' => 'g-adm', 'actor_display' => 'admins']];

        $principals = ProjectRolePrincipals::fromActors($actors, ['g-adm' => 'Project admins']);

        self::assertSame(
            [['project_id' => '10008', 'project_key' => 'ABC', 'project_name' => 'Abc', 'role_id' => '10002',
                'role_name' => 'Administrators', 'principal_type' => 'group', 'principal_id' => 'g-adm',
                'principal_name' => 'Project admins']],
            $principals
        );
    }

    public function testDuplicatesAndUnknownActorsAreDropped(): void
    {
        $user = self::ADMIN + ['actor_type' => 'atlassian-user-role-actor', 'actor_id' => 'acc-1'];
        $actors = [
            $user,
            $user,
            self::ADMIN + ['actor_type' => 'something-else', 'actor_id' => 'x'],
            self::ADMIN + ['actor_type' => 'atlassian-user-role-actor', 'actor_id' => ''],
        ];

        self::assertCount(1, ProjectRolePrincipals::fromActors($actors, []));
    }
}
