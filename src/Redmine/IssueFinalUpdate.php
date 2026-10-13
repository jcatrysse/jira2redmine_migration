<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

/**
 * What the final update of 10_migrate_issues.php sends (B10, B11, B22): the description is already
 * set when the issue is created, so the update only sends what differs from the issue as it is in
 * Redmine now (rewritten Jira links, the parent). An unchanged description is not sent again, so
 * Redmine records no "Description changed" journal for it.
 */
final class IssueFinalUpdate
{
    /**
     * @param array<string, mixed> $currentIssue the "issue" object of GET /issues/:id.json
     * @return array<string, mixed> the fields to send; empty when nothing changes
     */
    public static function changes(?string $proposedDescription, ?int $parentIssueId, array $currentIssue): array
    {
        $changes = [];
        $currentDescription = isset($currentIssue['description']) ? (string)$currentIssue['description'] : '';
        if ($proposedDescription !== null && self::normalise($proposedDescription) !== self::normalise($currentDescription)) {
            $changes['description'] = $proposedDescription;
        }

        $currentParent = isset($currentIssue['parent']['id']) ? (int)$currentIssue['parent']['id'] : null;
        if ($parentIssueId !== null && $parentIssueId !== $currentParent) {
            $changes['parent_issue_id'] = $parentIssueId;
        }

        return $changes;
    }

    /**
     * Redmine stores text with CRLF line ends; compare as LF.
     */
    private static function normalise(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }
}
