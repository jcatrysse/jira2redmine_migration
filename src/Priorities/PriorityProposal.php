<?php

declare(strict_types=1);

namespace Jira2Redmine\Priorities;

/**
 * 06's proposal for each Jira priority: the Redmine priority of the same name (case aside), else a new one.
 * A new priority gets no position of its own (B73): Redmine's API lists priorities without one, so 06 counted
 * none and proposed 1, 2, 3, and Redmine inserted the new ones there, below every existing priority (the new
 * "Blocker" became the lowest). Without a position Redmine puts it after the existing ones and moves none.
 * A position set by hand on the mapping row is kept.
 */
final class PriorityProposal
{
    /**
     * @param array<string, array{id: int, name: string, is_default: ?bool, position: ?int}> $redmineByName the
     *     Redmine priorities by lowercased name
     */
    public function __construct(private readonly array $redmineByName)
    {
    }

    /**
     * @param string|null $jiraName the Jira name, trimmed; null when Jira gave none
     * @return array{status: string, redmine_id: ?int, name: string, is_default: bool, position: ?int, notes: ?string}
     */
    public function propose(string $jiraId, ?string $jiraName, ?string $currentName, ?bool $currentIsDefault, ?int $currentPosition): array
    {
        $name = $currentName ?? $jiraName;
        $isDefault = $currentIsDefault ?? false;
        $position = $currentPosition;
        $redmineId = null;
        $notes = null;
        $match = $jiraName === null ? null : ($this->redmineByName[strtolower($jiraName)] ?? null);
        if ($jiraName === null) {
            $status = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Missing Jira priority name in the staging snapshot.';
        } elseif ($match !== null) {
            $status = 'MATCH_FOUND';
            $redmineId = $match['id'];
            $name = $match['name'];
            $isDefault = $match['is_default'] ?? false;
            $position = $match['position'];
        } else {
            $status = 'READY_FOR_CREATION';
            $name = $jiraName;
        }
        $name ??= $jiraId;

        return ['status' => $status, 'redmine_id' => $redmineId, 'name' => $name, 'is_default' => $isDefault,
            'position' => $position, 'notes' => $notes];
    }
}
