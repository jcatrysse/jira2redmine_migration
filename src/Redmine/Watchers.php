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
     * Marker of a watcher Redmine dropped because the user is not a member of the project (B50): with the
     * memberships from Jira's model (B31) such a user has no Browse projects in Jira either, so cannot see
     * the issue there. Not migrated, as in Jira; verify expects it absent. The row stays FAILED, so a
     * rerun of 14 adds the watcher once a membership is given.
     */
    public const NO_ACCESS = '[no-access]';

    public static function noAccessNote(int $redmineUserId): string
    {
        return sprintf(
            '%s User #%d is not a member of the project (no Browse projects in Jira either); not a watcher, as in Jira.',
            self::NO_ACCESS,
            $redmineUserId
        );
    }

    /**
     * Principal ids (users, also through a group) of a page of GET /projects/:id/memberships.json.
     *
     * @param array<mixed> $page
     * @return list<int>
     */
    public static function memberUserIds(array $page): array
    {
        $ids = [];
        foreach ($page['memberships'] ?? [] as $membership) {
            if (is_array($membership) && is_array($membership['user'] ?? null) && isset($membership['user']['id'])) {
                $ids[] = (int)$membership['user']['id'];
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
