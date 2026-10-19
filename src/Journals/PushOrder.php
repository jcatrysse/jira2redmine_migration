<?php

declare(strict_types=1);

namespace Jira2Redmine\Journals;

/**
 * The order of 11's push: per issue, in Jira's time order. Redmine's "Last updated by" (Issue#last_updated_by and the
 * issue list column) is the author of the journal with the highest id, not of the latest one, and an id follows the
 * order of the requests. 11 pushed every comment first and every changelog entry after them, so on 222 of 1,870
 * issues (7.0 test run) the last updated user was not the last person who acted in Jira. Per issue in time order
 * the highest id is the latest journal. On the same second a changelog entry (an attachment added) comes before the
 * comment that names it; the requests themselves do not change.
 */
final class PushOrder
{
    private const RANK = ['changelog' => 0, 'comment' => 1];

    /**
     * @param list<array<string, mixed>> $comments 11's pushable comments (redmine_issue_id, created_at, mapping_id)
     * @param list<array<string, mixed>> $changelogs 11's pushable changelog entries (the same columns)
     * @return list<array{0: 'comment'|'changelog', 1: array<string, mixed>}>
     */
    public static function timeline(array $comments, array $changelogs): array
    {
        $events = [];
        foreach ($comments as $row) {
            $events[] = ['comment', $row];
        }
        foreach ($changelogs as $row) {
            $events[] = ['changelog', $row];
        }
        usort($events, static fn (array $a, array $b): int => self::key($a) <=> self::key($b));

        return $events;
    }

    /**
     * @param array{0: 'comment'|'changelog', 1: array<string, mixed>} $event
     * @return array{int, string, int, int}
     */
    private static function key(array $event): array
    {
        [$kind, $row] = $event;

        return [
            (int)($row['redmine_issue_id'] ?? 0),
            (string)($row['created_at'] ?? ''),
            self::RANK[$kind],
            (int)($row['mapping_id'] ?? 0),
        ];
    }
}
