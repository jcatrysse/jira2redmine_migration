<?php

declare(strict_types=1);

use Jira2Redmine\Roles\MembershipPlan;
use Jira2Redmine\Roles\PermissionGrants;
use PHPUnit\Framework\TestCase;

final class PermissionGrantsTest extends TestCase
{
    public function testGroupGrantsOfThePermissionScheme(): void
    {
        $scheme = ['id' => 10021, 'permissions' => [
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'group', 'parameter' => 'All staff', 'value' => 'g-1',
                'group' => ['name' => 'All staff', 'groupId' => 'g-1']]],
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'projectRole', 'parameter' => '10003', 'value' => '10003']],
            ['permission' => 'ADMINISTER_PROJECTS', 'holder' => ['type' => 'group', 'parameter' => 'Admins', 'value' => 'g-2',
                'group' => ['name' => 'Admins', 'groupId' => 'g-2']]],
            ['permission' => 'ASSIGNABLE_USER', 'holder' => ['type' => 'applicationRole', 'parameter' => 'jira-software']],
            ['permission' => 'CREATE_ISSUES', 'holder' => ['type' => 'group', 'value' => 'g-1']],
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'group', 'value' => 'g-1', 'group' => ['groupId' => 'g-1']]],
        ]];

        $result = PermissionGrants::fromScheme($scheme);

        self::assertSame([
            ['permission' => 'BROWSE_PROJECTS', 'group_id' => 'g-1', 'group_name' => 'All staff'],
            ['permission' => 'ADMINISTER_PROJECTS', 'group_id' => 'g-2', 'group_name' => 'Admins'],
            ['permission' => 'ASSIGNABLE_USER', 'group_id' => PermissionGrants::APPLICATION_ROLE,
                'group_name' => 'Application role: jira-software'],
        ], $result['grants']);
        self::assertSame([], $result['other_holders']);
    }

    public function testAGrantToEveryLicensedUserIsProposedForTheNonMemberGroup(): void
    {
        $scheme = ['permissions' => [
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'applicationRole']],
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'applicationRole', 'parameter' => 'jira-software']],
            ['permission' => 'BROWSE_PROJECTS', 'holder' => ['type' => 'reporter']],
        ]];

        $result = PermissionGrants::fromScheme($scheme);

        // one row per permission, whatever the application
        $any = ['permission' => 'BROWSE_PROJECTS', 'group_id' => PermissionGrants::APPLICATION_ROLE,
            'group_name' => 'Application role: any'];
        self::assertSame([$any], $result['grants']);
        self::assertSame(['BROWSE_PROJECTS reporter' => 1], $result['other_holders']);
        $script = (string)file_get_contents(__DIR__ . '/../04_migrate_roles.php');
        self::assertTrue(
            str_contains($script, '$manualReason = PermissionGrants::APPLICATION_ROLE_NOTE;'),
            '04 pushes a grant to every logged-in user without Jan\'s review'
        );
    }

    public function testABuiltInGroupMembershipIsRead(): void
    {
        $nonMember = ['id' => 21, 'group_non_member' => ['id' => 3, 'name' => 'Non member users'], 'roles' => [['id' => 4]]];
        $page = ['memberships' => [$nonMember]];

        self::assertSame([3 => ['id' => 21, 'role_ids' => [4]]], MembershipPlan::existingFromApi($page));
    }

    public function testASecondRoleIsAddedToTheExistingMembership(): void
    {
        self::assertSame(['action' => 'create', 'role_ids' => [4]], MembershipPlan::decide(null, 4));
        self::assertSame(['action' => 'update', 'role_ids' => [3, 4]], MembershipPlan::decide(['id' => 9, 'role_ids' => [4]], 3));
        self::assertSame(['action' => 'none', 'role_ids' => [3, 4]], MembershipPlan::decide(['id' => 9, 'role_ids' => [3, 4]], 3));
    }

    public function testExistingMembershipsKeepOnlyDirectRoles(): void
    {
        $page = ['memberships' => [
            ['id' => 11, 'user' => ['id' => 7, 'name' => 'A'], 'roles' => [['id' => 4, 'name' => 'Developer', 'inherited' => true]]],
            ['id' => 12, 'group' => ['id' => 30, 'name' => 'G'], 'roles' => [['id' => 4], ['id' => 3, 'name' => 'Manager']]],
            ['id' => 13, 'user' => ['id' => 8], 'roles' => [['id' => 3], ['id' => 4, 'inherited' => true], ['id' => 4]]],
            ['id' => 14, 'roles' => [['id' => 3]]],
        ]];

        $existing = MembershipPlan::existingFromApi($page);

        // user 7 is a member only through group 30: the membership exists, without direct roles
        self::assertSame(['id' => 11, 'role_ids' => []], $existing[7]);
        self::assertSame(['id' => 12, 'role_ids' => [3, 4]], $existing[30]);
        self::assertSame(['id' => 13, 'role_ids' => [3, 4]], $existing[8]);
        self::assertCount(3, $existing);
        self::assertSame(['action' => 'update', 'role_ids' => [4]], MembershipPlan::decide($existing[7], 4));
    }
}
