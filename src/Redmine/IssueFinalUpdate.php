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
     * The Extended API overrides of the final update: the issue keeps Jira's updated time, and the journal Redmine
     * writes for the change is the author's, at the issue's creation. Its updated time is that creation time too:
     * otherwise it kept the time of the run and Redmine marked it "Edited" (37 journals per run of GEOS, SIS, OAC;
     * Jan, 2026-10-15: post-processing leaves no visible trace).
     *
     * @param string|null $updated Jira's updated time, normalised
     * @param string|null $created Jira's created time, normalised
     * @return array<string, mixed>
     */
    public static function overrides(?string $updated, ?string $created, ?int $authorId): array
    {
        $overrides = [];
        if ($updated !== null) {
            $overrides['updated_on'] = $updated;
        }
        $created ??= $updated;

        $journal = [];
        if ($authorId !== null) {
            $journal['user_id'] = $authorId;
            $journal['updated_by_id'] = $authorId;
        }
        if ($created !== null) {
            $journal['created_on'] = $created;
            $journal['updated_on'] = $created;
        }
        if ($journal !== []) {
            $overrides['journal'] = $journal;
        }

        return $overrides;
    }

    /**
     * Redmine stores text with CRLF line ends; compare as LF.
     */
    private static function normalise(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }
}
