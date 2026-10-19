<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Jan, decision 39: one run of all 14 projects. docs/PRODUCTION.md empties the attachment directory right after
 * 09's push (B2's disk choice) and keeps the relink only as its check; the test runner does the same under
 * EMPTY_TMP and RELINK, so the test run follows the production procedure.
 */
final class OneRunProcedureTest extends TestCase
{
    public function testTheDirectoryTheProcedureEmptiesIsTheOne09DownloadsInto(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../09_migrate_attachments.php');
        self::assertStringContainsString(
            "rtrim(\$tmpBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . 'jira'",
            $script
        );
        // 09 makes it again when it is gone, and its transform requeues a failed upload for a new download
        self::assertStringContainsString('@mkdir($directory, 0775, true)', $script);
        self::assertStringContainsString("migration_status = 'PENDING_DOWNLOAD',\n            local_filepath = NULL", $script);

        $production = (string)file_get_contents(__DIR__ . '/../docs/PRODUCTION.md');
        self::assertStringContainsString('`rm -rf "$J2R_TMP/attachments"`', $production);
        self::assertStringContainsString('--download-limit=1500', $production);
    }

    public function testTheTestRunnerEmptiesItAfterThePushAndBeforeTheIssues(): void
    {
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        $push = strpos($run, 'step 09-push ');
        $empty = strpos($run, '[ -n "${EMPTY_TMP:-}" ] && rm -rf "$J2R_TMP/attachments"');
        $issues = strpos($run, 'step 10 10_migrate_issues.php');
        self::assertIsInt($push);
        self::assertIsInt($empty);
        self::assertIsInt($issues);
        self::assertGreaterThan($push, $empty);
        self::assertLessThan($issues, $empty);
    }

    public function testTheTestRunnerCanStopAtASnapshotPoint(): void
    {
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertStringContainsString('if [ "${STOP_AT:-}" = "$1" ]; then echo "stopped at $1 (STOP_AT)"', $run);
    }

    public function testTheTestRunnerMarksTheSnapshotPointsAndRunsTheRelinkPreviewAfterVerify(): void
    {
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        $order = [
            'mark S0',
            'step 01 01_migrate_projects.php',
            'step 08-push ',
            'mark S1',
            'step 09-pull ',
            'step 10-final ',
            'mark S2',
            'step 11 ',
            'step 16 16_export_workflows.php',
            'mark S3',
            'tools/verify.php',
            'php tools/relink_issue_links.php)',
            'tools/workflow_proposal.php',
            'manual restore',
            'mark end',
        ];
        $last = -1;
        foreach ($order as $needle) {
            $position = strpos($run, $needle);
            self::assertIsInt($position, $needle);
            self::assertGreaterThan($last, $position, $needle);
            $last = $position;
        }
        // the preview only: the one run's relink writes nothing
        self::assertStringNotContainsString('relink_issue_links.php --confirm-push', $run);
    }

    public function testTheWatchersAreReadFromJiraOnce(): void
    {
        // 14's jira phase reads the watchers of every issue of the run (29 min for the 14 projects on 7.0); it runs
        // before 04, which proposes the watchers' memberships, and the push needs only the transform after that
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertSame(1, substr_count($run, 'step 14-jira 14_migrate_watchers.php --phases=jira'));
        self::assertStringContainsString('step 14 14_migrate_watchers.php --phases=transform,push --confirm-push', $run);
        // the stability pass (STABILITY=1) still reruns 14 whole, as a rerun after a failure would (PRODUCTION.md B10)
        self::assertStringNotContainsString('step 14 14_migrate_watchers.php --confirm-push', $run);
        foreach (['PRODUCTION.md', 'RUNBOOK.md'] as $doc) {
            $text = (string)file_get_contents(__DIR__ . '/../docs/' . $doc);
            self::assertStringContainsString('php 14_migrate_watchers.php --phases=transform,push --confirm-push', $text, $doc);
            self::assertStringNotContainsString('php 14_migrate_watchers.php --confirm-push', $text, $doc);
        }
    }
}
