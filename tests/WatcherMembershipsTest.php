<?php

declare(strict_types=1);

use Jira2Redmine\Roles\AssigneeMemberships;
use Jira2Redmine\Roles\WatcherMemberships;
use PHPUnit\Framework\TestCase;

final class WatcherMembershipsTest extends TestCase
{
    public function testAWatcherWithoutAccessIsProposedOnce(): void
    {
        $abc = ['project_id' => '10', 'project_key' => 'ABC', 'project_name' => 'Abc'];
        $watchers = [
            $abc + ['account_id' => 'in-group', 'display_name' => 'A'],
            $abc + ['account_id' => 'left', 'display_name' => 'C'],
            $abc + ['account_id' => 'left', 'display_name' => 'C'],
            $abc + ['account_id' => 'assignee-only', 'display_name' => 'D'],
        ];
        $actors = [
            ['project_id' => '10', 'role_name' => 'Browse projects (permission scheme)', 'actor_id' => 'g-1',
                'actor_type' => 'atlassian-group-role-actor'],
        ];
        // the assignee-only row of the same project already makes D a member
        $assigneeRows = AssigneeMemberships::uncovered([$abc + ['account_id' => 'assignee-only', 'display_name' => 'D']], $actors, [], []);

        $rows = WatcherMemberships::uncovered($watchers, [...$actors, ...$assigneeRows], ['g-1' => ['in-group']], []);

        self::assertSame(['left'], array_column($rows, 'actor_id'));
        self::assertSame((string)WatcherMemberships::ROLE_ID, $rows[0]['role_id']);
        self::assertSame(WatcherMemberships::ROLE_NAME, $rows[0]['role_name']);
        self::assertSame('atlassian-user-role-actor', $rows[0]['actor_type']);
        self::assertSame((string)AssigneeMemberships::ROLE_ID, $assigneeRows[0]['role_id'], 'the assignee rows keep their role');
    }

    public function testTheRoleComesFromItsOwnConfigKey(): void
    {
        $config = require __DIR__ . '/../config/config.default.php';
        $mappings = WatcherMemberships::mappings($config['migration']['roles']);
        self::assertSame('Developer', $mappings[WatcherMemberships::ROLE_NAME]);
        self::assertSame('Developer', $mappings[AssigneeMemberships::ROLE_NAME]);
        $dedicated = WatcherMemberships::mappings(['mappings' => [], 'assignee_only_role' => 'Assignee', 'watcher_only_role' => 'Watcher']);
        self::assertSame([AssigneeMemberships::ROLE_NAME => 'Assignee', WatcherMemberships::ROLE_NAME => 'Watcher'], $dedicated);
    }

    public function testThe04RunProposesThemAfterTheWatchersAreStaged(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../04_migrate_roles.php');
        self::assertTrue(str_contains($script, 'WatcherMemberships::uncovered('), '04 does not propose watchers without access');
        self::assertTrue(str_contains($script, 'WatcherMemberships::NOTE'), 'a watcher-only row does not say so');
        self::assertTrue(str_contains($script, 'new RoleMapping(WatcherMemberships::mappings('), 'the watcher-only role is not mapped');
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertLessThan(
            (int)strpos($run, 'step 04 04_migrate_roles.php'),
            (int)strpos($run, 'step 14-jira 14_migrate_watchers.php --phases=jira'),
            '04 runs before the watchers are staged and cannot see them'
        );
        self::assertGreaterThan(0, strpos($run, 'step 14-jira 14_migrate_watchers.php --phases=jira'));
    }
}
