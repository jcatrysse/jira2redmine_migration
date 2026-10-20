<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B89: run_all.sh's summary gives each step an error count from its log. It counted "[error]", "fatal" and
 * "exception" but not "[failed]", which the scripts print for an object Redmine refused (08: a custom field 422), so
 * every run's summary said "08-push ... errors=0" while two fields failed.
 */
final class RunSummaryTest extends TestCase
{
    public function testTheErrorCountOfAStepCountsFailedLines(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertSame(1, preg_match("/errors=%d.*grep -ciE '([^']+)' \"\\\$logdir\\/\\\$name\\.log\"/", $script, $match));
        $pattern = '/' . str_replace('/', '\/', $match[1]) . '/i';
        $log = [
            '  [failed] Jira custom field Remotely Operated Towed Vehicle (customfield_10067): HTTP 422 Unprocessable Content',
            '  [error] Failed to create Redmine relation (HTTP 422)',
            'PHP Fatal error:  Uncaught TypeError',
            '  [info] Jira issue type 10001: Name shortened to 30 characters for Redmine.',
            '  [created] Jira custom field Department (customfield_10058) -> Redmine custom field #12.',
        ];
        $counted = array_values(array_filter($log, static fn (string $line): bool => preg_match($pattern, $line) === 1));

        self::assertSame(array_slice($log, 0, 3), $counted);
    }
}
