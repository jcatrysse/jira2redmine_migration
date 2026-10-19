<?php

declare(strict_types=1);

namespace Jira2Redmine\Journals;

/**
 * The order of 11's push: per issue, in Jira's time order. Redmine's "Last updated by" (Issue#last_updated_by and the
 * issue list column) is the author of the journal with the highest id, not of the latest one, and an id follows the
 * order of the requests. 11 pushed every comment first and every changelog entry after them, so on 222 of 1,870
 * issues (7.0 test run) the last updated user was not the last person who acted in Jira. Per issue in time order
 * the highest id is the latest journal. The history journals of decision 41 go in between: a series of them without
 * a comment in between is one request. On the same second the history comes first, then a changelog entry (an
 * attachment added), then the comment that names it.
 */
final class PushOrder
{
    private const RANK = ['history' => 0, 'changelog' => 1, 'comment' => 2];

    /**
     * @param list<array<string, mixed>> $comments 11's pushable comments (redmine_issue_id, created_at, mapping_id)
     * @param list<array<string, mixed>> $changelogs 11's pushable changelog entries (the same columns)
     * @param list<array<string, mixed>> $history history journals (the same columns; mapping_id the Jira history id)
     * @return list<array{0: 'history'|'changelog'|'comment', 1: array<string, mixed>}>
     */
    public static function timeline(array $comments, array $changelogs, array $history = []): array
    {
        $events = [];
        foreach ($history as $row) {
            $events[] = ['history', $row];
        }
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
     * The requests of a timeline: a comment or changelog entry alone, history journals of one issue together until a
     * comment or changelog entry comes in between.
     *
     * @param list<array{0: 'history'|'changelog'|'comment', 1: array<string, mixed>}> $timeline
     * @return list<array{0: string, 1: array<array-key, mixed>}> a history step holds the list of its journals' rows
     */
    public static function steps(array $timeline): array
    {
        $steps = [];
        $series = [];
        foreach ($timeline as [$kind, $row]) {
            if ($kind === 'history') {
                if ($series !== [] && $series[0]['redmine_issue_id'] !== $row['redmine_issue_id']) {
                    $steps[] = ['history', $series];
                    $series = [];
                }
                $series[] = $row;
                continue;
            }
            if ($series !== []) {
                $steps[] = ['history', $series];
                $series = [];
            }
            $steps[] = [$kind, $row];
        }
        if ($series !== []) {
            $steps[] = ['history', $series];
        }

        return $steps;
    }

    /**
     * @param array{0: 'history'|'changelog'|'comment', 1: array<string, mixed>} $event
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
