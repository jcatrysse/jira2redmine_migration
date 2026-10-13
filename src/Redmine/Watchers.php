<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

/**
 * Redmine answers 204 to POST /issues/:id/watchers.json even when it does not store the watcher
 * (for example redmine_view_issue_description accepts only users with view_watched_issues), so the
 * result is read back from the issue (B20).
 */
final class Watchers
{
    /**
     * User ids of the issue's watchers, from GET /issues/:id.json?include=watchers.
     *
     * @param array<string, mixed> $issuePayload the decoded response, with or without the "issue" wrapper
     * @return list<int>
     */
    public static function storedUserIds(array $issuePayload): array
    {
        $issue = isset($issuePayload['issue']) && is_array($issuePayload['issue']) ? $issuePayload['issue'] : $issuePayload;
        $ids = [];
        foreach ($issue['watchers'] ?? [] as $watcher) {
            if (is_array($watcher) && isset($watcher['id'])) {
                $ids[] = (int)$watcher['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $requestedUserIds
     * @param list<int> $storedUserIds
     * @return list<int> the requested users Redmine did not store
     */
    public static function notStored(array $requestedUserIds, array $storedUserIds): array
    {
        return array_values(array_diff($requestedUserIds, $storedUserIds));
    }
}
