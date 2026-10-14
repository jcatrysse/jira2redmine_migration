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
     * memberships from Jira's model (B31) such a user has no Browse projects in Jira either. Jan wants these
     * watchers migrated (decision 12): the members step makes every migrated user a member with a temporary
     * role during the import. The row stays FAILED, so a rerun of 14 adds the watcher once the user is a
     * member; verify counts it as missing.
     */
    public const NO_ACCESS = '[no-access]';

    public static function noAccessNote(int $redmineUserId): string
    {
        return sprintf(
            '%s User #%d is not a member of the project: run the members step (temporary migration role), then rerun 14.',
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
