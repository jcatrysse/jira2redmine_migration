<?php

declare(strict_types=1);

use Jira2Redmine\Workflows\GroupConditions;
use PHPUnit\Framework\TestCase;

final class GroupConditionsTest extends TestCase
{
    private const MODES = ['ABC' => ['Own Team' => 'G', 'Everyone' => 'R', 'Apps' => 'ignore']];

    public function testTheSettingsAreReadPerProjectAndGroup(): void
    {
        self::assertSame(
            ['ABC' => ['own team' => 'G', 'everyone' => 'R', 'apps' => 'ignore']],
            GroupConditions::modes(self::MODES)
        );
        self::assertSame(['ABC' => ['x' => 'G', 'y' => 'ignore']], GroupConditions::modes(['ABC' => ['X' => 'g', 'Y' => 'IGNORE']]));
        self::assertSame([], GroupConditions::modes(null));
    }

    public function testAnUnknownSettingStopsTheRun(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('migration.workflows.group_roles.ABC.Team: "maybe" is not G, R or ignore');

        GroupConditions::modes(['ABC' => ['Team' => 'maybe']]);
    }

    public function testAGroupNotListedStaysAReviewRow(): void
    {
        $resolved = GroupConditions::resolve('ABC', ['Nobody'], [], GroupConditions::modes(self::MODES));

        self::assertSame([], $resolved['roles']);
        self::assertSame(['Nobody'], $resolved['not_members']);
        self::assertSame(
            ['Jira limits it to group "Nobody", which is not a member of ABC: set review_role_id and PROPOSED, or IGNORED'],
            $resolved['review']
        );
        self::assertSame([], $resolved['ignored']);
    }

    public function testGGivesTheTransitionToTheGroupsOwnRoleOnce04MadeItAMember(): void
    {
        $modes = GroupConditions::modes(self::MODES);
        // 04's membership row: the group holds its own role 9
        $member = GroupConditions::resolve('ABC', ['Own Team'], ['ABC' => ['own team' => [9]]], $modes);
        self::assertSame([9], $member['roles']);
        self::assertSame([], $member['review']);

        // before 04 ran: still a review row, which says what to do
        $waiting = GroupConditions::resolve('ABC', ['Own Team'], [], $modes);
        self::assertSame([], $waiting['roles']);
        self::assertStringContainsString('its own role (Jan, decision 14) has no membership yet, run 04', $waiting['review'][0]);
    }

    public function testRGivesTheTransitionToTheRolesTheGroupsUsersHold(): void
    {
        $modes = GroupConditions::modes(self::MODES);
        $resolved = GroupConditions::resolve('ABC', ['Everyone'], [], $modes, ['ABC' => ['everyone' => [4, 3]]]);

        self::assertSame([3, 4], $resolved['roles']);
        self::assertSame([], $resolved['review']);

        // no user of the group is a member: nobody to give it to, Jan decides
        $none = GroupConditions::resolve('ABC', ['Everyone'], [], $modes, ['ABC' => ['everyone' => []]]);
        self::assertSame([], $none['roles']);
        self::assertStringContainsString('no user of the group is a member', $none['review'][0]);
    }

    public function testIgnoreCarriesNothingAndNeedsNoReview(): void
    {
        $resolved = GroupConditions::resolve('ABC', ['Apps'], [], GroupConditions::modes(self::MODES));

        self::assertSame([], $resolved['roles']);
        self::assertSame([], $resolved['review']);
        self::assertSame(
            ['Jira limits it to group "Apps", which is not a member of ABC: ignored (Jan, decision 14)'],
            $resolved['ignored']
        );
    }

    public function testASettingAppliesOnlyInItsProject(): void
    {
        $resolved = GroupConditions::resolve('XYZ', ['Apps'], [], GroupConditions::modes(self::MODES));

        self::assertSame([], $resolved['ignored']);
        self::assertSame(['Apps'], $resolved['not_members']);
    }

    public function testTheUsersRolesComeFromUserAndGroupMemberships(): void
    {
        $memberships = [
            ['project' => 'ABC', 'principal_type' => 'user', 'principal_id' => 'acc-1', 'role' => 3],
            ['project' => 'ABC', 'principal_type' => 'group', 'principal_id' => 'g-dev', 'role' => 4],
            ['project' => 'ABC', 'principal_type' => 'user', 'principal_id' => 'acc-9', 'role' => 5],
            ['project' => 'XYZ', 'principal_type' => 'user', 'principal_id' => 'acc-1', 'role' => 6],
        ];
        $groupMembers = ['g-all' => ['acc-1', 'acc-2'], 'g-dev' => ['acc-2', 'acc-3'], 'g-apps' => ['acc-1']];
        $groupIds = ['everyone' => 'g-all', 'apps' => 'g-apps'];

        $roles = GroupConditions::usersRoles($memberships, $groupMembers, $groupIds, GroupConditions::modes(self::MODES));

        // acc-1 holds 3 directly, acc-2 holds 4 through g-dev; acc-9 is not in the group, XYZ is another project;
        // only groups set to R are computed
        self::assertSame(['ABC' => ['everyone' => [3, 4]]], $roles);
    }
}
