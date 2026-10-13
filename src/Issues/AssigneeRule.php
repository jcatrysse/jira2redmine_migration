<?php

declare(strict_types=1);

namespace Jira2Redmine\Issues;

/**
 * A Jira assignee without a Redmine account (an app account, a person without a visible e-mail) used to
 * keep the issue out of Redmine (B9). The issue is now created unassigned, or with the configured
 * default assignee, and the mapping row says so.
 */
final class AssigneeRule
{
    /**
     * @return array{assignee: ?int, note: ?string}
     */
    public static function resolve(?string $jiraAssigneeId, ?int $redmineAssigneeId, ?int $defaultAssigneeId): array
    {
        if ($jiraAssigneeId === null || $redmineAssigneeId !== null) {
            return ['assignee' => $redmineAssigneeId, 'note' => null];
        }
        if ($defaultAssigneeId !== null) {
            return ['assignee' => $defaultAssigneeId, 'note' => 'Assignee not mapped to a Redmine user; default assignee applied.'];
        }

        return ['assignee' => null, 'note' => 'Assignee not mapped to a Redmine user; created unassigned.'];
    }
}
