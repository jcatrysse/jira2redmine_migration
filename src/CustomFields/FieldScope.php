<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * B53: the projects and trackers a field must be enabled for. 08's sync refreshes on every run which Jira
 * projects and issue types hold the field, but the transform leaves a field it created alone, and the
 * update plan compared the stored proposal with itself: a field created by an earlier batch was never
 * enabled for a later batch's projects, and Redmine dropped those values silently (48 empty fields in
 * the WIKI batch's verify). The desired scope is the stored proposal plus what the Jira scope gives now.
 */
final class FieldScope
{
    /**
     * @param list<int> $proposed Redmine ids 08 stored for the field
     * @param list<string> $jiraIds Jira project or issue type ids whose issues hold the field
     * @param array<int|string, int> $lookup Jira id => Redmine id
     * @return list<int> sorted, without duplicates
     */
    public static function desired(array $proposed, array $jiraIds, array $lookup): array
    {
        $ids = $proposed;
        foreach ($jiraIds as $jiraId) {
            if (isset($lookup[$jiraId])) {
                $ids[] = (int)$lookup[$jiraId];
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
