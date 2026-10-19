<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

/**
 * The Redmine value of a Jira status, assignee or priority in the history step (decision 41): statuses through 05,
 * priorities through 06 (decision 35), people through 02. A Jira assignee is always an account, never a group, so
 * 03's groups do not take part. An empty Jira value (unassigned) is an empty Redmine value; a value without a
 * Redmine counterpart (LABO's seven statuses, an assignee without an account, an unmapped priority) has none.
 */
final class HistoryLookups
{
    /**
     * @param array<array-key, int> $statuses Jira status id => Redmine status id (mapped rows only)
     * @param array<array-key, int> $priorities Jira priority id => Redmine priority id (mapped rows only)
     * @param array<array-key, array{id: int, login: ?string}> $users Jira account id => Redmine user (mapped rows only)
     * @param int $fallbackAuthorId the author 11 gives a comment whose author has no account: the configured
     *                              default author, else the API user
     */
    public function __construct(
        private readonly array $statuses,
        private readonly array $priorities,
        private readonly array $users,
        private readonly int $fallbackAuthorId
    ) {
    }

    public function maps(string $field, ?string $jiraValue): bool
    {
        if ($jiraValue === null || $jiraValue === '') {
            return true;
        }

        return match ($field) {
            'status' => isset($this->statuses[$jiraValue]),
            'priority' => isset($this->priorities[$jiraValue]),
            'assignee' => isset($this->users[$jiraValue]),
            default => false,
        };
    }

    /** The Redmine id of a value; null for an empty value and for one Redmine does not have. */
    public function value(string $field, ?string $jiraValue): ?int
    {
        if ($jiraValue === null || $jiraValue === '') {
            return null;
        }

        return match ($field) {
            'status' => $this->statuses[$jiraValue] ?? null,
            'priority' => $this->priorities[$jiraValue] ?? null,
            'assignee' => isset($this->users[$jiraValue]) ? $this->users[$jiraValue]['id'] : null,
            default => null,
        };
    }

    /** The same rule as 11's comments: the mapped account, else the default author, else the API user. */
    public function author(?string $jiraAccountId): int
    {
        if ($jiraAccountId !== null && isset($this->users[$jiraAccountId])) {
            return $this->users[$jiraAccountId]['id'];
        }

        return $this->fallbackAuthorId;
    }

    public function login(?string $jiraAccountId): ?string
    {
        if ($jiraAccountId === null || !isset($this->users[$jiraAccountId])) {
            return null;
        }

        return $this->users[$jiraAccountId]['login'];
    }
}
