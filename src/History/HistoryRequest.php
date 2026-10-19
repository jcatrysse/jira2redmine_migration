<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

/**
 * The request of the history step: POST /extended_api/issues/:id/history.json, one per issue with all its journals
 * and the optional file (redmine_extended_api; admin only). The plugin writes them with insert_all and does not save
 * the issue: updated_on, closed_on and the watchers stay, no mail, no webhook (decision 37). It answers 201 with the
 * new journal ids in the order sent.
 *
 * @phpstan-import-type Detail from IssueHistory
 * @phpstan-type File array{token: string, filename: string, content_type: string, description: string, author_id: int, created_on: string}
 * @phpstan-type Body array{journals: list<array{user_id: int, created_on: string, details: list<Detail>}>, attachments?: list<File>}
 */
final class HistoryRequest
{
    public static function path(int $redmineIssueId): string
    {
        return sprintf('issues/%d/history.json', $redmineIssueId);
    }

    /**
     * @param array{token: string, author_id: int, created_on: string}|null $file the uploaded jira-history.json
     * @return Body
     */
    public static function body(IssueHistory $history, ?array $file): array
    {
        $body = ['journals' => array_map(static fn (array $journal): array => [
            'user_id' => $journal['user_id'],
            'created_on' => $journal['created_on'],
            'details' => $journal['details'],
        ], $history->journals)];
        if ($file !== null) {
            $body['attachments'] = [[
                'token' => $file['token'],
                'filename' => HistoryFile::FILENAME,
                'content_type' => HistoryFile::CONTENT_TYPE,
                'description' => HistoryFile::DESCRIPTION,
                'author_id' => $file['author_id'],
                'created_on' => $file['created_on'],
            ]];
        }

        return $body;
    }

    /**
     * The journal ids of the answer, in order: {"journals": [{"id": 1}, ...]}, {"journals": [1, ...]} or
     * {"journal_ids": [1, ...]}; null when the answer holds none of these.
     *
     * @param array<mixed> $answer
     * @return list<int>|null
     */
    public static function journalIds(array $answer): ?array
    {
        $list = $answer['journal_ids'] ?? $answer['journals'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $ids = [];
        foreach ($list as $journal) {
            $id = is_array($journal) ? ($journal['id'] ?? null) : $journal;
            if (!is_numeric($id)) {
                return null;
            }
            $ids[] = (int)$id;
        }

        return $ids;
    }

    /**
     * The id of the file in the answer ({"attachments": [{"id": 9}]}), null when it names none.
     *
     * @param array<mixed> $answer
     */
    public static function attachmentId(array $answer): ?int
    {
        $first = is_array($answer['attachments'] ?? null) ? ($answer['attachments'][0] ?? null) : null;
        $id = is_array($first) ? ($first['id'] ?? null) : $first;

        return is_numeric($id) ? (int)$id : null;
    }
}
