<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Jan's decision 12 (2026-10-14): during the import every migrated user is a member of every migrated project
 * with a temporary role, on top of 04's final memberships; restore removes only that role. 04's assignee-only
 * (B46) and watcher-only (B50) members are then off by default.
 */
final class TemporaryMigrationRoleTest extends TestCase
{
    public function testAssigneeAndWatcherOnlyMembersAreOffByDefault(): void
    {
        $config = require __DIR__ . '/../config/config.default.php';
        self::assertFalse($config['migration']['roles']['assignee_only_members']);
        self::assertFalse($config['migration']['roles']['watcher_only_members']);
        $script = (string)file_get_contents(__DIR__ . '/../04_migrate_roles.php');
        self::assertTrue(str_contains($script, "\$assigneeRows = !\$assigneeOnly ? false"), '04 proposes assignee-only members anyway');
        self::assertTrue(str_contains($script, "\$watcherRows = !\$watcherOnly ? false"), '04 proposes watcher-only members anyway');
    }

    public function testTheMembersStepAddsTheRoleAfter04AndRestoreTakesItAway(): void
    {
        $steps = (string)file_get_contents(__DIR__ . '/../tools/testenv/manual_steps.rb');
        $start = (int)strpos($steps, "when 'members'");
        $members = substr($steps, $start, (int)strpos($steps, "when 'restore'") - $start);
        $restore = substr($steps, (int)strpos($steps, "when 'restore'"));
        self::assertStringContainsString("TEMPORARY_ROLE = 'Migration (temporary)'", $steps);
        // the role's definition is shared with the workflows step (decision 33), the members step uses it
        $definition = substr($steps, (int)strpos($steps, 'temporary_role = lambda do'), 500);
        self::assertStringContainsString('temporary.assignable = true', $definition);
        self::assertStringContainsString('temporary.permissions = Redmine::AccessControl.permissions.reject(&:public?)', $definition);
        self::assertStringContainsString('temporary = temporary_role.call', $members);
        self::assertStringContainsString(
            'MemberRole.create!(member: member, role: temporary)',
            $members,
            'an existing member does not get the role'
        );
        self::assertStringContainsString('MemberRole.where(role_id: temporary.id, inherited_from: nil)', $restore);
        self::assertStringContainsString(
            'member_role.destroy',
            $restore,
            'restore skips the callback that removes members left without a role'
        );
        self::assertStringNotContainsString('Watcher.prune', $steps);
        // the opened workflows were global rules: a project's own workflow is not restore's to remove
        self::assertStringContainsString(
            "scope = scope.where(project_id: nil) if WorkflowRule.column_names.include?('project_id')",
            $restore
        );

        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertGreaterThan(
            (int)strpos($run, 'step 04-push 04_migrate_roles.php'),
            (int)strpos($run, "\nmanual members\n"),
            'the temporary role comes before the final memberships'
        );
        self::assertLessThan((int)strpos($run, 'step 08-transform'), (int)strpos($run, "\nmanual members\n"));
        self::assertStringContainsString('manual proof temporary_role_proof.rb', $run);
        self::assertStringContainsString('php tools/testenv/membership_check.php', $run);
    }

    public function testTheRunbookWarnsAgainstPruningWatchers(): void
    {
        $runbook = (string)file_get_contents(__DIR__ . '/../docs/RUNBOOK.md');
        self::assertStringContainsString('rake redmine:watchers:prune', $runbook);
        self::assertStringContainsString('Migration (temporary)', $runbook);
    }
}
