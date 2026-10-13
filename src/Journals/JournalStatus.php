<?php

declare(strict_types=1);

namespace Jira2Redmine\Journals;

/**
 * Status of a journal mapping row after the transform of 11. The transform set every row whose issue
 * exists to READY_FOR_PUSH, pushed ones included, so each rerun of 11 created every comment and
 * change a second time (B35: 1,277 duplicate journals on 5.1). A pushed journal now stays SUCCESS;
 * FAILED and SKIPPED rows are judged again (the push skips again what has nothing to show).
 */
final class JournalStatus
{
    public static function afterTransform(string $current, ?int $redmineJournalId, ?int $redmineIssueId): string
    {
        if (in_array($current, ['SUCCESS', 'IGNORED'], true)) {
            return $current;
        }
        if ($redmineJournalId !== null) {
            return 'SUCCESS';
        }
        if ($redmineIssueId === null) {
            return 'PENDING';
        }

        return 'READY_FOR_PUSH';
    }
}
