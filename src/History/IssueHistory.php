<?php

declare(strict_types=1);

namespace Jira2Redmine\History;

/**
 * One issue's history as the history step writes it (decision 41): the journals for Redmine, every Jira transition of
 * status, assignee and priority for the file, and the values Redmine does not have.
 *
 * @phpstan-type Detail array{property: string, prop_key: string, old_value: ?string, value: ?string}
 * @phpstan-type Journal array{history_id: string, user_id: int, created_on: string, details: list<Detail>}
 * @phpstan-type Value array{id: ?string, name: ?string}
 * @phpstan-type Author array{jira_account_id: ?string, redmine_login: ?string}
 * @phpstan-type Transition array{history_id: string, time: string, field: string, from: Value, to: Value, author: Author}
 */
final class IssueHistory
{
    /**
     * @param list<Journal> $journals one per Jira history entry with a detail left, in Jira's order
     * @param list<Transition> $transitions every status, assignee and priority item, in Jira's order
     * @param list<array{field: string, id: string, name: ?string}> $notInRedmine the distinct values without a Redmine value
     * @param int $skipped items left out because their new value has no Redmine value
     * @param int $dropped items left out because nothing changed after the mapping (A to A)
     * @param array<string, ?int> $last per field with a detail: the Redmine value the journals end in
     */
    public function __construct(
        public readonly array $journals,
        public readonly array $transitions,
        public readonly array $notInRedmine,
        public readonly int $skipped,
        public readonly int $dropped,
        public readonly array $last
    ) {
    }

    /** Only an issue whose history lost a value gets jira-history.json. */
    public function needsFile(): bool
    {
        return $this->notInRedmine !== [];
    }

    /**
     * The check of the step: the status the journals end in against the issue's. null when they agree or the history
     * has no status detail; "open parent" when the issue differs only by 10's open parent rule (Jan: the parent of an
     * open subtask gets the open status "Resolved") and the history ends as Jira's status; else "mismatch".
     *
     * @param ?int $issueStatusId the status 10 gave the issue
     * @param ?int $jiraStatusId the Redmine status of the issue's current Jira status
     */
    public function statusCheck(?int $issueStatusId, ?int $jiraStatusId): ?string
    {
        if (!array_key_exists('status', $this->last) || $this->last['status'] === $issueStatusId) {
            return null;
        }

        return $this->last['status'] === $jiraStatusId ? 'open parent' : 'mismatch';
    }

    public function detailCount(): int
    {
        return array_sum(array_map(static fn (array $journal): int => count($journal['details']), $this->journals));
    }
}
