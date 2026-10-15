<?php

declare(strict_types=1);

namespace Jira2Redmine\Priorities;

/**
 * 06's proposal for each Jira priority: the Redmine priority of the same name (case aside), else a new one,
 * with a position after every position already taken.
 */
final class PriorityProposal
{
    /** @var array<int, true> */
    private array $usedPositions = [];
    private int $nextPosition;

    /**
     * @param array<string, array{id: int, name: string, is_default: ?bool, position: ?int}> $redmineByName the
     *     Redmine priorities by lowercased name
     */
    public function __construct(private readonly array $redmineByName)
    {
        $max = 0;
        foreach ($redmineByName as $priority) {
            if ($priority['position'] !== null) {
                $this->usedPositions[$priority['position']] = true;
                $max = max($max, $priority['position']);
            }
        }
        $this->nextPosition = $max + 1;
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

        if ($position === null && $status === 'READY_FOR_CREATION') {
            $position = max(1, $this->nextPosition);
            while (isset($this->usedPositions[$position])) {
                $position++;
            }
        }
        if ($position !== null) {
            $this->usedPositions[$position] = true;
            if ($this->nextPosition <= $position) {
                $this->nextPosition = $position + 1;
            }
        }

        return ['status' => $status, 'redmine_id' => $redmineId, 'name' => $name, 'is_default' => $isDefault,
            'position' => $position, 'notes' => $notes];
    }
}
