<?php

declare(strict_types=1);

use Jira2Redmine\Journals\ExtractionFailure;
use PHPUnit\Framework\TestCase;

/**
 * B81: 11 logged a failed read of an issue's comments as a [warn] without the issue's key; the log check of
 * docs/PRODUCTION.md B7 counts [error] lines only, so two comments of DOC-351 went missing unseen (a full disk).
 */
final class ExtractionFailureTest extends TestCase
{
    public function testAFailureIsAnErrorWithTheIssueKey(): void
    {
        $line = ExtractionFailure::line('DOC-351', 'comments', 'FAILED', "General error: 1114 The table 'staging_jira_comments' is full");
        self::assertMatchesRegularExpression('/\[error\]|fatal|exception/i', $line);
        self::assertStringContainsString('DOC-351, comments not read', $line);
        self::assertStringContainsString('run 11 again', $line);
    }

    public function testAnIssueJiraRefusesStaysAWarning(): void
    {
        // 401, 403, 404: a rerun does not help (the issue is gone or not visible to the token)
        $line = ExtractionFailure::line('DOC-1', 'history', 'WARNING', 'HTTP 404');
        self::assertSame('  [warn] DOC-1, history: HTTP 404', $line);
        self::assertDoesNotMatchRegularExpression('/\[error\]|fatal|exception/i', $line);
    }

    public function testThe11UsesItForBothPartsAndCountsTheFailures(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
        self::assertStringContainsString("ExtractionFailure::line(\$issueKey, 'comments', \$warningStatus, \$message)", $eleven);
        self::assertStringContainsString("ExtractionFailure::line(\$issueKey, 'history', \$warningStatus, \$message)", $eleven);
        self::assertSame(2, substr_count($eleven, "\$failed += \$warningStatus === 'FAILED' ? 1 : 0;"));
        self::assertStringContainsString('not read: %d.', $eleven);
        self::assertStringNotContainsString('printf("  [warn] %s%s", $message, PHP_EOL);', $eleven);
    }
}
