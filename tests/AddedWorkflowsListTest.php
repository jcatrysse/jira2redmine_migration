<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B82: the workflows step of manual_steps.rb appends what it opens to tmp/j2r_added_workflows.json, and restore removes
 * every transition the list names. A list left from another state of the database (a test instance reset without it)
 * named the transitions of the existing tracker "Task" too, and restore removed its global workflow (4 rows to 0).
 */
final class AddedWorkflowsListTest extends TestCase
{
    public function testTheStepStopsOnAListThatDoesNotMatchThisRedmineBeforeItAddsAnything(): void
    {
        $step = (string)file_get_contents(__DIR__ . '/../tools/testenv/manual_steps.rb');
        $existing = strpos($step, "existing = WorkflowTransition.pluck(:tracker_id, :role_id, :old_status_id, :new_status_id).to_set");
        $check = strpos($step, 'stale = previous.reject { |key| existing.include?(key) }');
        $abort = strpos($step, 'transitions this Redmine does not have: it is left from');
        $insert = strpos($step, 'rows.each_slice(5000) { |slice| WorkflowRule.insert_all(slice) }');
        foreach ([$existing, $check, $abort, $insert] as $position) {
            self::assertIsInt($position);
        }
        self::assertGreaterThan($existing, $check);
        self::assertGreaterThan($check, $abort);
        self::assertGreaterThan($abort, $insert);
        self::assertStringContainsString('File.write(added_file, JSON.generate(previous + keys))', $step);
    }

    public function testAResetOfATestInstanceRemovesTheList(): void
    {
        $reset = (string)file_get_contents(__DIR__ . '/../tools/testenv/reset.sh');
        self::assertStringContainsString('rm -f /home/user/src/redmine$v/tmp/j2r_added_workflows.json', $reset);
    }

    public function testTheRollbackWithoutAVmSnapshotKeepsTheList(): void
    {
        $production = (string)file_get_contents(__DIR__ . '/../docs/PRODUCTION.md');
        self::assertStringContainsString('a copy of `tmp/j2r_added_workflows.json`', $production);
    }
}
