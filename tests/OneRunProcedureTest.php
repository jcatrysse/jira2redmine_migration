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
}
