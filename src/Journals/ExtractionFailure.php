<?php

declare(strict_types=1);

namespace Jira2Redmine\Journals;

/**
 * The log line of an issue whose comments or history 11 could not read from Jira. A failure other than 401, 403 or 404
 * leaves the issue without them until 11 runs again, so it is an error, which the log check of docs/PRODUCTION.md B7
 * counts: a full disk ("The table 'staging_jira_comments' is full") was a [warn] without the issue's key, and the two
 * comments of DOC-351 were missing in Redmine (B81).
 */
final class ExtractionFailure
{
    /**
     * @param string $part "comments" or "history"
     * @param string $status FAILED, or WARNING (401, 403, 404: the issue or its part cannot be read, a rerun does not help)
     */
    public static function line(string $issueKey, string $part, string $status, string $message): string
    {
        if ($status !== 'FAILED') {
            return sprintf('  [warn] %s, %s: %s', $issueKey, $part, $message);
        }

        return sprintf('  [error] %s, %s not read: %s; run 11 again to read them', $issueKey, $part, $message);
    }
}
