<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

use JsonException;

/**
 * jira-history.json, the one file an issue gets when its Redmine history had to leave a value out (Jan, decision 41):
 * all its status, assignee and priority transitions as Jira has them, with the author as Jira account id and Redmine
 * login, never a display name. It carries no "generated" time, so a dry run gives the same file twice. Its author
 * and time are those of the last transition in it, never the migration user and never the time of the run.
 */
final class HistoryFile
{
    public const FILENAME = 'jira-history.json';
    public const CONTENT_TYPE = 'application/json';
    public const DESCRIPTION = 'Jira history of status, assignee and priority, with the values Redmine does not have';

    /**
     * @throws JsonException
     */
    public static function content(string $jiraKey, string $jiraIssueId, IssueHistory $history): string
    {
        $transitions = array_map(static fn (array $transition): array => [
            'time' => $transition['time'],
            'field' => $transition['field'],
            'from' => $transition['from'],
            'to' => $transition['to'],
            'author' => $transition['author'],
        ], $history->transitions);

        return json_encode([
            'format' => 1,
            'jira_key' => $jiraKey,
            'jira_issue_id' => $jiraIssueId,
            'not_in_redmine' => $history->notInRedmine,
            'transitions' => $transitions,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * The author and time of the file: those of its last transition, and the Jira history entry they come from.
     *
     * @return array{author_id: int, created_on: string, history_id: string}
     */
    public static function stamp(IssueHistory $history, HistoryLookups $lookups): array
    {
        $last = $history->transitions[array_key_last($history->transitions) ?? 0] ?? null;
        if ($last === null) {
            throw new \LogicException('A history file needs at least one transition.');
        }

        return [
            'author_id' => $lookups->author($last['author']['jira_account_id']),
            'created_on' => $last['time'],
            'history_id' => $last['history_id'],
        ];
    }
}
